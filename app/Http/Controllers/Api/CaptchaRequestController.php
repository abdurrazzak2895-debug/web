<?php

namespace App\Http\Controllers\Api;

use App\Enums\CaptchaRequestStatus;
use App\Enums\CaptchaTokenType;
use App\Http\Controllers\Controller;
use App\Models\CaptchaRequest;
use App\Models\Setting;
use App\Services\Captcha\CaptchaEncryptionService;
use App\Services\Captcha\CaptchaRaceCoordinator;
use App\Support\CaptchaDeliveryGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaptchaRequestController extends Controller
{
    /**
     * POST /api/captcha/request
     *
     * Machine callers authenticate with `Authorization: Bearer <CAPTCHA_API_TOKEN>`
     * (a signed-in admin session is accepted too, for the in-app testers). Claims a
     * ready token from the pool immediately, or falls back to racing providers for a
     * new solve.
     *
     * Naming `type` in the body gets the token delivered inline in this response;
     * omitting it falls back to the older claim-then-GET protocol, where the type is
     * given at consumption time via GET /api/captcha/request/{id}?type=...
     *
     * Both forms are refused outright while no captcha provider is enabled.
     */
    public function store(Request $request, CaptchaEncryptionService $encryptor, CaptchaRaceCoordinator $races): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:30',
            'agent_slot_id' => 'nullable|integer',
            'type' => 'nullable|string|in:turnstile,turnstile_encrypted,raw',
        ]);

        $phone = $validated['phone'] ?? null;
        $type = $validated['type'] ?? null;

        // The master switch, checked before anything else: with every provider switched off
        // the bot gets nothing — no fresh solve, and no token the pool already holds. Refusing
        // here rather than after the pool claim also means a gated request leaves no claimed
        // row behind, and never starts a race that would spend on a disabled provider.
        if (CaptchaDeliveryGate::shut()) {
            return response()->json([
                'status' => 'failed',
                'error' => CaptchaDeliveryGate::reason(),
            ], 503);
        }

        if ($phone !== null) {
            $limit = (int) (Setting::instance()->captcha_daily_limit_per_account ?? 0);

            if ($limit > 0) {
                $usedToday = CaptchaRequest::where('phone', $phone)
                    ->whereDate('created_at', today())
                    ->count();

                if ($usedToday >= $limit) {
                    return response()->json([
                        'status' => 'failed',
                        'error' => "Daily captcha limit of {$limit} reached for this account.",
                    ], 429);
                }
            }
        }

        // Atomically claim the oldest ready pool entry
        $poolEntry = DB::transaction(function () {
            $entry = CaptchaRequest::where('source', 'pool')
                ->where('status', CaptchaRequestStatus::Ready)
                ->lockForUpdate()
                ->oldest('solved_at')
                ->first();

            if ($entry) {
                $entry->update(['status' => CaptchaRequestStatus::Claimed]);
            }

            return $entry;
        });

        // A caller that names its type up front is served in this very response. The extra
        // GET the old protocol forced cost a round trip plus one full client poll interval
        // on every pool hit — which is the path almost every request takes, so it was the
        // largest single delay in captcha delivery and none of it was spent solving.
        if ($poolEntry && $type !== null) {
            $consumed = $this->consume($poolEntry->request_id);

            if (is_array($consumed)) {
                $token = $this->encryptFor($type, $consumed['token'], $encryptor);

                if ($token === null) {
                    return response()->json([
                        'status' => 'failed',
                        'error' => 'Captcha encryption unavailable (sidecar unreachable or meta not ready).',
                    ], 500);
                }

                return response()->json([
                    'request_id' => $poolEntry->request_id,
                    'status' => 'ready',
                    'token' => $token,
                    'solved_at_ms' => $consumed['solved_at_ms'],
                    'config_version' => Setting::currentProtocolConstantsVersion(),
                ]);
            }

            // The claim was ours and nothing else may take a claimed row, so this is close to
            // unreachable. Falling through to a fresh race is still the right answer: handing
            // back an id that no longer resolves would cost the caller its whole poll budget.
            $poolEntry = null;
        }

        if ($poolEntry) {
            return response()->json(['request_id' => $poolEntry->request_id]);
        }

        // Pool empty — create the delivery slot and race several providers to fill it.
        $captchaRequest = CaptchaRequest::create([
            'request_id' => Str::uuid()->toString(),
            'type' => CaptchaTokenType::Turnstile,
            'status' => CaptchaRequestStatus::Pending,
            'source' => CaptchaRaceCoordinator::SOURCE_DEMAND,
            'phone' => $phone,
            'agent_slot_id' => $validated['agent_slot_id'] ?? null,
        ]);

        $races->launch($captchaRequest);

        return response()->json(['request_id' => $captchaRequest->request_id], 201);
    }

    /**
     * GET /api/captcha/request/{requestId}?type=turnstile|turnstile_encrypted
     *
     * Same credential as store() above. Returns the solved token, applying encryption
     * if type=turnstile_encrypted. Deletes the entry on consumption.
     */
    public function show(Request $request, string $requestId, CaptchaEncryptionService $encryptor): JsonResponse
    {
        $type = $request->query('type', 'turnstile');

        if (! in_array($type, ['turnstile', 'turnstile_encrypted', 'raw'], true)) {
            return response()->json(['status' => 'failed', 'error' => 'Invalid type.'], 422);
        }

        // Stamped on every response so a bot parked at the gate learns that a mid-window bundle
        // extraction changed the IVAC constants, and can refresh /api/config before it uses the token
        // this poll is waiting for. Opaque hash only — the token rides along to the bot, but the
        // constants themselves must never be shipped here.
        $configVersion = Setting::currentProtocolConstantsVersion();

        // Non-consumable statuses don't need a lock — fast path
        $peek = CaptchaRequest::where('request_id', $requestId)->first();

        if (! $peek) {
            return response()->json(['status' => 'failed', 'error' => 'Request not found or expired.'], 404);
        }

        // Every token type the bot consumes (login, reserve, and raw for payment/setup) is
        // withheld while no provider is enabled, even if the pool already holds a solved
        // token — this lets an admin disable all providers to stop the bot completely,
        // without stopping the pool filler. The gate can also shut between the POST that
        // claimed this row and this poll, which is why the claim is released below.
        if (CaptchaDeliveryGate::shut()) {
            // Hand a claimed pool token back so the gate costs nothing while it is shut. Without this
            // the bot drains the pool into 'claimed' limbo it never consumes: fillPool() counts only
            // pending/processing/ready, so every withheld claim looks like depletion and buys a
            // replacement solve — and a parked bot re-claims every 65s per account.
            if ($peek->source === 'pool' && $peek->status === CaptchaRequestStatus::Claimed) {
                CaptchaRequest::where('request_id', $requestId)
                    ->where('status', CaptchaRequestStatus::Claimed)
                    ->update(['status' => CaptchaRequestStatus::Ready]);
            }

            return response()->json([
                'status' => 'pending',
                'config_version' => $configVersion,
            ]);
        }

        $consumable = $peek->status === CaptchaRequestStatus::Ready
            || $peek->status === CaptchaRequestStatus::Claimed;

        if (! $consumable) {
            return match ($peek->status) {
                CaptchaRequestStatus::Failed, CaptchaRequestStatus::Expired => response()->json([
                    'status' => 'failed',
                    'error' => $peek->error_message ?? 'Captcha solve failed.',
                    'config_version' => $configVersion,
                ]),
                default => response()->json(['status' => 'pending', 'config_version' => $configVersion]),
            };
        }

        $result = $this->consume($requestId);

        if ($result === null) {
            return response()->json(['status' => 'failed', 'error' => 'Request not found or expired.'], 404);
        }

        // Lost the race — another thread consumed it first
        if ($result instanceof CaptchaRequest) {
            return match ($result->status) {
                CaptchaRequestStatus::Failed, CaptchaRequestStatus::Expired => response()->json([
                    'status' => 'failed',
                    'error' => $result->error_message ?? 'Captcha solve failed.',
                    'config_version' => $configVersion,
                ]),
                default => response()->json(['status' => 'pending', 'config_version' => $configVersion]),
            };
        }

        $token = $this->encryptFor($type, $result['token'], $encryptor);

        if ($token === null) {
            return response()->json([
                'status' => 'failed',
                'error' => ($type === 'turnstile' ? 'Login' : 'Reserve').' captcha encryption unavailable (sidecar unreachable or meta not ready).',
            ], 500);
        }

        return response()->json([
            'status' => 'ready',
            'token' => $token,
            'solved_at_ms' => $result['solved_at_ms'],
            'config_version' => $configVersion,
        ]);
    }

    /**
     * Take a solved token off a row and delete it, all under one lock.
     *
     * Returns the payload on success, the row itself when someone else consumed it first
     * (so the caller can report that row's terminal status), or null when it is gone.
     *
     * @return array{token: string, solved_at_ms: int|null}|CaptchaRequest|null
     */
    private function consume(string $requestId): array|CaptchaRequest|null
    {
        return DB::transaction(function () use ($requestId) {
            $captchaRequest = CaptchaRequest::where('request_id', $requestId)
                ->lockForUpdate()
                ->first();

            if (! $captchaRequest) {
                return null;
            }

            $consumable = $captchaRequest->status === CaptchaRequestStatus::Ready
                || $captchaRequest->status === CaptchaRequestStatus::Claimed;

            if (! $consumable) {
                return $captchaRequest;
            }

            $token = $captchaRequest->token;
            $solvedAtMs = $captchaRequest->solved_at?->getTimestampMs();
            $captchaRequest->delete();

            return ['token' => $token, 'solved_at_ms' => $solvedAtMs];
        });
    }

    /**
     * Apply the transform the caller asked for. Null means the sidecar could not encrypt;
     * type=raw is returned untouched.
     */
    private function encryptFor(string $type, string $token, CaptchaEncryptionService $encryptor): ?string
    {
        return match ($type) {
            'turnstile' => $encryptor->encryptLogin($token),
            'turnstile_encrypted' => $encryptor->encryptReserve($token),
            default => $token,
        };
    }
}

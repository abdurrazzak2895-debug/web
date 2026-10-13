<?php

namespace App\Http\Middleware;

use App\Models\AgentSlot;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the machine-facing captcha delivery API.
 *
 * POST/GET /api/captcha/request hand out solved Turnstile tokens, and every token handed out is
 * paid for at the configured solve providers. Reachable by anyone who could reach the host, the
 * endpoint let a stranger drain the pool or queue fresh solves on the operator's account, so it
 * now requires a credential.
 *
 * Three kinds of caller are legitimate:
 *
 *  - machines holding the shared secret — {@see \App\Services\Captcha\RawCaptchaTokenProvider}
 *    and any operator script — presenting `Authorization: Bearer <CAPTCHA_API_TOKEN>`
 *    (an `X-Api-Key` header is accepted too);
 *  - a bot worker presenting the slot `api_key` it already uses for heartbeats and config, so
 *    the fleet needs no second secret;
 *  - a signed-in admin using the in-app API testers, which keep working through the session
 *    rather than a token.
 *
 * There is no anonymous path, and leaving the shared token unset does not create one: without it
 * the endpoint still refuses everyone who is not a slot or an admin.
 */
class AuthenticateCaptchaApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken() ?? $request->header('X-Api-Key');

        if (is_string($presented) && $presented !== '') {
            if ($this->isSharedToken($presented) || $this->isSlotKey($presented)) {
                return $next($request);
            }

            // A caller that brought a credential and still failed is the signature of a rollout
            // that stopped half way. The response stays deliberately vague; the hint goes to the
            // log where the operator will look.
            if ((string) config('captcha.api_token') === '') {
                Log::warning('Captcha API rejected a credential while CAPTCHA_API_TOKEN is unset', [
                    'ip' => $request->ip(),
                ]);
            }

            return $this->unauthorized();
        }

        // The in-app testers call these endpoints from the admin UI with no token of their own;
        // an authenticated session is a stronger claim than the shared secret.
        if ($request->user() !== null) {
            return $next($request);
        }

        return $this->unauthorized();
    }

    /**
     * hash_equals keeps the comparison constant-time, so a caller cannot learn the shared token
     * one byte at a time from response timing.
     */
    private function isSharedToken(string $presented): bool
    {
        $expected = (string) config('captcha.api_token');

        return $expected !== '' && hash_equals($expected, $presented);
    }

    /**
     * Slots authenticate with the key they already hold, which is the same comparison the
     * heartbeat and config endpoints make — a unique-index hit on a 64-character random key.
     */
    private function isSlotKey(string $presented): bool
    {
        return AgentSlot::query()->where('api_key', $presented)->exists();
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => 'failed',
            'error' => 'Unauthorized.',
        ], 401);
    }
}

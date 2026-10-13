<?php

namespace App\Services\Captcha;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mints a RAW Cloudflare Turnstile token for the server-side IVAC calls that need one.
 *
 * IVAC gates its invoice download on an `x-token` header carrying an unencrypted Turnstile
 * token, exactly as the file-upload endpoint does — the encrypted `c` payload is not accepted
 * there. Without the header IVAC answers 500 with an INCIDENT-ID, which reads like an outage
 * and is nothing of the sort.
 *
 * The token comes from the portal's own captcha API rather than a reimplementation of it, so
 * the delivery gate, the pool claim and the provider race stay in one place. That API refuses
 * anonymous callers, so the machine token rides along — see request() below.
 */
class RawCaptchaTokenProvider
{
    private const POLL_ATTEMPTS = 60;

    private const POLL_INTERVAL_MICROSECONDS = 400_000;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    /**
     * A raw Turnstile token, or null when none could be solved.
     *
     * Never throws: every caller has a meaningful answer for "no token" already, and an invoice
     * fetch failing is preferable to an exception escaping a queued job.
     */
    public function mint(): ?string
    {
        try {
            $response = $this->request()
                ->post($this->endpoint('/api/captcha/request'), ['type' => 'raw']);

            // A pool hit is answered inline, which is the path almost every request takes.
            $inline = $response->json('token');
            if (is_string($inline) && $inline !== '') {
                return $inline;
            }

            $requestId = $response->json('request_id');
            if (! is_string($requestId) || $requestId === '') {
                return null;
            }

            return $this->poll($requestId);
        } catch (\Throwable $e) {
            Log::warning('RawCaptchaTokenProvider could not mint a token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Wait out a fresh solve, giving up rather than blocking a worker indefinitely.
     */
    private function poll(string $requestId): ?string
    {
        for ($attempt = 0; $attempt < self::POLL_ATTEMPTS; $attempt++) {
            usleep(self::POLL_INTERVAL_MICROSECONDS);

            $poll = $this->request()
                ->get($this->endpoint('/api/captcha/request/'.$requestId), ['type' => 'raw']);

            $status = $poll->json('status');

            if ($status === 'ready') {
                $token = $poll->json('token');

                return is_string($token) && $token !== '' ? $token : null;
            }

            if ($status === 'failed') {
                return null;
            }
        }

        return null;
    }

    /**
     * A client carrying the portal's machine token.
     *
     * /api/captcha/request refuses anonymous callers, so without this header every mint here
     * answers 401 and the invoice download degrades to "no token" — silently, because mint()
     * swallows failures by design.
     */
    private function request(): PendingRequest
    {
        $request = Http::timeout(self::REQUEST_TIMEOUT_SECONDS);

        $token = (string) config('captcha.api_token');

        return $token === '' ? $request : $request->withToken($token);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}

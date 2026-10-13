<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the machine-facing captcha delivery API.
 *
 * POST/GET /api/captcha/request hand out solved Turnstile tokens, and every token
 * handed out is paid for at the configured solve providers. Reachable by anyone who
 * can reach the host, the endpoint let a stranger drain the pool or queue fresh
 * solves on the operator's account, so it now requires a credential.
 *
 * Two kinds of caller are legitimate:
 *
 *  - machines — the Java bot and {@see \App\Services\Captcha\RawCaptchaTokenProvider}
 *    calling the portal over HTTP — presenting `Authorization: Bearer <CAPTCHA_API_TOKEN>`
 *    (an `X-Api-Key` header is accepted too);
 *  - a signed-in admin using the in-app API testers, which keep working through the
 *    session rather than a token.
 *
 * It fails closed: with no token configured the endpoint answers 503 instead of
 * reverting to open, so forgetting the environment variable cannot silently expose it.
 */
class AuthenticateCaptchaApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('captcha.api_token');

        if ($expected === '') {
            return response()->json([
                'status' => 'failed',
                'error' => 'Captcha API token is not configured. Set CAPTCHA_API_TOKEN on the portal.',
            ], 503);
        }

        $presented = $request->bearerToken() ?? $request->header('X-Api-Key');

        // hash_equals keeps the comparison constant-time, so a caller cannot learn the
        // token one byte at a time from response timing.
        if (is_string($presented) && $presented !== '' && hash_equals($expected, $presented)) {
            return $next($request);
        }

        // The in-app testers call these endpoints from the admin UI with no token of
        // their own; an authenticated session is a stronger claim than the shared secret.
        if ($request->user() !== null) {
            return $next($request);
        }

        return response()->json([
            'status' => 'failed',
            'error' => 'Unauthorized.',
        ], 401);
    }
}

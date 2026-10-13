<?php

namespace App\Http\Middleware;

use App\Models\AgentSlot;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * /api/config carries the IVAC sign-in material for every account the caller may see — phones,
 * passwords, stored JWTs — so it no longer answers to a bare `?gmail=`. Production was serving
 * 416 accounts to anyone who asked, and the same payload handed over `captchaBotSecret`, which
 * unlocks /api/captcha/bot-providers (provider API keys) and /api/captcha/bot-store.
 *
 * Three callers are legitimate:
 *
 *  - a bot started from this portal, holding the signed URL it was handed. The JAR fetches
 *    whatever URL it is given, so it needs no change — the link itself is the credential;
 *  - a bot in distributed mode, holding the slot api_key it already sends to /api/slots/*;
 *  - an operator session with `bot.manage`, for debugging.
 */
class EnsureBotConfigAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasValidSignature()) {
            return $next($request);
        }

        // A request that carried a signature and failed it is expired or tampered with, not
        // anonymous. Say so plainly: the fix is to start the bot again from the portal.
        if ($request->has('signature')) {
            return response()->json([
                'status' => 'failed',
                'error' => 'This bot configuration link is invalid or has expired. Start the bot again from the portal to mint a new one.',
            ], 403);
        }

        $credential = $request->bearerToken();

        if (is_string($credential) && $credential !== '') {
            if (AgentSlot::where('api_key', $credential)->exists()) {
                return $next($request);
            }

            // A presented-but-unknown key answers exactly like no key at all, so the endpoint
            // cannot be used to tell a wrong credential from a missing one.
            return $this->unauthorized();
        }

        $user = $request->user();

        if ($user !== null && Gate::forUser($user)->allows('bot.manage')) {
            return $next($request);
        }

        return $this->unauthorized();
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => 'failed',
            'error' => 'Unauthorized.',
        ], 401);
    }
}
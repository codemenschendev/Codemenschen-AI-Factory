<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's second factor (2026-09-24). Each admin chooses whether to use it (owner's decision,
 * same day): with it switched on, a token opens nothing in /admin until the authenticator code was
 * entered on it; before that the only open doors are the 2FA routes. Switched off, the e-mail link
 * alone signs in, as before.
 *
 * The API authenticates by bearer token only (no stateful Sanctum, no session sign-in exists), so
 * a real request always carries a PersonalAccessToken. Anything else is a test acting as a user.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if ($user?->two_factor_enabled_at !== null && $token instanceof PersonalAccessToken && $token->two_factor_at === null) {
            return response()->json(['message' => 'Enter the code from your authenticator app.', 'two_factor' => 'verify'], 403);
        }

        return $next($request);
    }
}

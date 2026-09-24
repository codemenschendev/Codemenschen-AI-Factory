<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's second factor (2026-09-24). An admin token opens nothing in /admin until the
 * authenticator code was entered on it; before that the only open doors are the 2FA routes.
 *
 * The API authenticates by bearer token only (no stateful Sanctum, no session sign-in exists), so
 * a real request always carries a PersonalAccessToken. Anything else is a test acting as a user.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && $token->two_factor_at === null) {
            $enabled = $request->user()->two_factor_enabled_at !== null;

            return response()->json([
                'message' => $enabled ? 'Enter the code from your authenticator app.' : 'Set up two-factor sign-in first.',
                'two_factor' => $enabled ? 'verify' : 'setup',
            ], 403);
        }

        return $next($request);
    }
}

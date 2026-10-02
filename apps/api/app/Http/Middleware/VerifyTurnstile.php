<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cloudflare Turnstile in front of the public requests that cost a model call (Patrick,
 * 2026-09-24: "bots make us poor"). The browser sends a one-use token in X-Turnstile; it is
 * checked with Cloudflare before the request goes on.
 *
 * Without a secret configured the check is off, so a deploy never locks the forms before the key
 * is on the server. When Cloudflare itself cannot be reached the request passes: the throttles
 * and the e-mail confirmation still stand, and an outage there must not stop every lead.
 */
class VerifyTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.turnstile.secret');
        if (! $secret) {
            return $next($request);
        }

        $token = (string) $request->header('X-Turnstile', '');
        if ($token === '' || strlen($token) > 2048) {
            return response()->json(['message' => 'Bot check missing.', 'error' => 'turnstile'], 403);
        }

        try {
            $res = Http::asForm()->timeout(5)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('turnstile.unreachable', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return $next($request);
        }
        if (! $res->successful()) {
            Log::warning('turnstile.http', ['status' => $res->status()]);

            return $next($request);
        }
        if (! $res->json('success')) {
            Log::info('turnstile.rejected', ['codes' => $res->json('error-codes'), 'ip' => $request->ip()]);

            return response()->json(['message' => 'Bot check failed.', 'error' => 'turnstile'], 403);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole guard for the operator lane. It sits behind auth:sanctum, so by the time it runs the
 * caller is a known customer; the only question left is whether that customer is us.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && method_exists($user, 'isAdmin') && $user->isAdmin(), 403, 'Kein Zugang.');

        return $next($request);
    }
}

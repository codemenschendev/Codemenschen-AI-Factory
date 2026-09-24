<?php

namespace App\Http\Middleware;

use App\Domain\Security\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes every console action that changes something into the audit log, refused ones included:
 * a 403 or a 422 on a spend button is as worth knowing as a 200. Reading is not logged.
 */
class AuditAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethodSafe()) {
            return $response;
        }
        $route = $request->route();
        $subject = collect($route?->parameters() ?? [])
            ->map(fn ($v, $k) => $k.':'.(is_object($v) && method_exists($v, 'getKey') ? $v->getKey() : (is_scalar($v) ? $v : '?')))
            ->implode(' ');
        Audit::record(
            $request->method().' '.preg_replace('#^api/#', '', (string) ($route?->uri() ?? $request->path())),
            $request->user(),
            $subject !== '' ? $subject : null,
            $request->except(array_keys($request->allFiles())),
            $request,
            $response->getStatusCode(),
        );

        return $response;
    }
}

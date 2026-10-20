<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The probe token of Werk (werk-connect, Werk docs/API.md 7.1 and 7.10): `Authorization: Bearer`
 * or `X-Werk-Token`, compared in constant time with WERK_TOKEN. No token configured is 403, a
 * wrong one 401, and nothing of these answers may be cached on the way.
 */
class WerkToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('services.werk.token'));
        if ($expected === '') {
            return self::noStore(response()->json(['error' => 'no_token_configured'], 403));
        }
        $header = (string) $request->header('Authorization', '');
        $given = str_starts_with(strtolower($header), 'bearer ')
            ? trim(substr($header, 7))
            : trim((string) $request->header('X-Werk-Token', ''));
        if ($given === '' || ! hash_equals($expected, $given)) {
            return self::noStore(response()->json(['error' => 'invalid_token'], 401));
        }

        return self::noStore($next($request));
    }

    private static function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}

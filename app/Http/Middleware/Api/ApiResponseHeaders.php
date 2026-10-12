<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers every API answer should carry. Member data must not be stored by shared caches or proxies, and the
 * apps never need a browser to interpret an answer, so content sniffing and framing are switched off too.
 * Media routes set their own (immutable, private) caching and are left alone.
 */
class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        $isMedia = $request->routeIs('v1.media.*');
        if (! $isMedia && ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}

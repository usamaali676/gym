<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline security headers. The CSP is only sent for built assets
 * (no Vite dev server), because HMR needs a different origin and websocket.
 *
 * script-src needs 'unsafe-eval' for Alpine.js expressions and
 * 'wasm-unsafe-eval' for the MediaPipe WebAssembly runtime. Nothing is
 * loaded from a third-party origin, so script-src stays 'self'.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // The camera is used by the Posture Lab on our own origin only.
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=()',
        ];

        if (! file_exists(public_path('hot'))) {
            $headers['Content-Security-Policy'] = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-eval' 'wasm-unsafe-eval'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "media-src 'self' blob:",
                "font-src 'self'",
                "connect-src 'self'",
                "worker-src 'self' blob:",
                "manifest-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]);
        }

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins baseline security headers on every response (audit L1, L12,
 * M3). Caddy / nginx may pin these at the edge too, but defence in
 * depth is cheap and survives misconfigured reverse proxies.
 *
 * The CSP applied here is JSON-strict (`default-src 'none'`) so an
 * accidental HTML response from the API surface cannot execute any
 * inline script. The share-viewer Blade swaps in a tighter,
 * SPA-friendly CSP via {@see SetShareLinkHeaders}.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        // Don't clobber CSP if a more specific middleware (e.g. the
        // share-viewer) already set one.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // HSTS only over HTTPS — emitting over plain HTTP gets the
        // header stripped by some intermediaries and confuses dev.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

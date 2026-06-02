<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When SHARE_UI_HOST is set and the inbound request's Host matches
 * it, the only routes allowed are:
 *   - the share viewer SPA shell (route name share.host.catchall /
 *     share.view)
 *   - Vite-built assets (/build/*) so the shell can load its JS/CSS
 *   - the share-link public API endpoints (so the SPA can call them
 *     same-origin from the share host instead of bouncing across to
 *     the main API host)
 *
 * Everything else returns 404. This is the second line of defense:
 * the cloud's reverse proxy SHOULD already route share.usework.space
 * to a tightened path allow-list, but the middleware ensures a
 * misconfigured proxy can't expose the rest of the API by accident.
 */
class ShareHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $shareHost = strtolower((string) config('teamcore.share.host', ''));
        if ($shareHost === '') {
            return $next($request);
        }

        if (! $this->hostMatches($request, $shareHost)) {
            return $next($request);
        }

        if (! $this->isAllowed($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function hostMatches(Request $request, string $shareHost): bool
    {
        // Check the raw Host header (and the resolved getHost so X-
        // Forwarded-Host setups still match) — symfony's getHost can
        // strip an unknown vhost when trusted_hosts is locked down.
        $candidates = array_filter([
            $request->header('host'),
            $request->getHost(),
            $request->getHttpHost(),
        ]);

        foreach ($candidates as $candidate) {
            // Strip an explicit port if present (Host: example:8080).
            $host = strtolower(explode(':', (string) $candidate)[0]);
            if ($host === $shareHost) {
                return true;
            }
        }

        return false;
    }

    private function isAllowed(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        // Vite asset paths (laravel-vite-plugin emits under /build/).
        if (str_starts_with($path, '/build/')) {
            return true;
        }

        // Public share endpoints + their meta route.
        if (str_starts_with($path, '/api/v1/share-links/')) {
            return true;
        }

        // SPA shell — /s/{token} plus the host-level catch-all.
        if ($path === '/' || str_starts_with($path, '/s/')) {
            return true;
        }

        // Favicon / manifest live on the same host; let them through
        // so the share viewer's tab icon resolves.
        if (in_array($path, ['/favicon.ico', '/site.webmanifest', '/up'], true)) {
            return true;
        }

        return false;
    }
}

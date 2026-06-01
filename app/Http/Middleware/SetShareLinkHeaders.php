<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets cache, indexing and CSP headers for public share-link responses.
 *
 * - `Cache-Control: no-store, private` keeps reverse proxies and CDNs
 *   from caching one recipient's payload and serving it to another.
 * - `X-Robots-Tag` keeps search engines from indexing share URLs that
 *   end up posted publicly.
 * - `Cross-Origin-Resource-Policy: same-origin` keeps the snapshot
 *   from being embedded by an arbitrary site.
 *
 * Audit M3: also pin a strict Content-Security-Policy on the Blade
 * shell. The only inline `<script>` the page emits is the runtime
 * config (`window.__TC_SHARE_API_BASE__ = ...`); we whitelist it via
 * a per-request nonce that the Blade reads from
 * `request()->attributes->get('csp_nonce')`. Everything else is
 * `'self'` (the Vite-built bundle).
 */
class SetShareLinkHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Per-request nonce — the Blade puts this on its inline
        // <script>, and the CSP whitelists exactly that nonce.
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $request->attributes->set('csp_nonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $apiBase = (string) config('teamcore.share.api_base', '');
        $connectSrc = "'self'";
        if ($apiBase !== '') {
            // Allow XHR to the cross-origin API host the share viewer
            // is configured to call. Parse out scheme+host only — the
            // CSP wants origins, not full URLs with paths.
            $parts = parse_url($apiBase);
            if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                $origin = $parts['scheme'].'://'.$parts['host'];
                if (! empty($parts['port'])) {
                    $origin .= ':'.$parts['port'];
                }
                $connectSrc .= ' '.$origin;
            }
        }

        $csp = implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "form-action 'none'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'nonce-{$nonce}'",
            "connect-src {$connectSrc}",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }
}

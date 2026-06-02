<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves the share-viewer SPA shell on /s/{tokenHash} (and any path
 * on the configured share-only host). The Blade injects a runtime
 * API base via window.__TC_SHARE_API_BASE__ so a single built bundle
 * supports both same-origin self-hosted deployments and the cloud's
 * dedicated share.usework.space host.
 *
 * Self-hosted operators can flip SELFHOST_SHARE_LINKS_ENABLED=false
 * in their env to disable both the share API endpoints (existing
 * guard) and this UI route (added below) at once.
 */
class PublicShareViewController extends Controller
{
    public function show(Request $request): View
    {
        // On self-hosted, the admin can disable the share feature in
        // its entirety. Cloud always exposes it. We re-check the same
        // toggle here as the existing share-link API endpoints so a
        // disabled instance returns 404 at the UI layer too.
        $edition = (string) config('teamcore.edition', 'cloud');
        $sharesEnabled = (bool) config('teamcore.features.share_links_enabled', true);

        if ($edition === 'self_hosted' && ! $sharesEnabled) {
            abort(404);
        }

        return view('share', [
            'apiBase' => (string) config('teamcore.share.api_base', ''),
        ]);
    }
}

<?php

namespace Tests\Feature\Sharing;

use Tests\TestCase;

class ShareViewerRouteTest extends TestCase
{
    public function test_share_route_returns_spa_shell(): void
    {
        $hash = str_repeat('a', 64);

        $response = $this->get("/s/{$hash}");

        $response->assertOk();
        // The Blade shell carries the runtime API-base injection and
        // the root mount point — both are required for the SPA to
        // bootstrap.
        $response->assertSee('__TC_SHARE_API_BASE__', false);
        $response->assertSee('id="root"', false);
    }

    public function test_share_route_injects_web_fallback_base(): void
    {
        // The "Open in app" button derives its web-fallback URL from
        // window.__TC_SHARE_WEB_FALLBACK_BASE__; without this the
        // client has nowhere to redirect when the OS-level usework://
        // protocol handler isn't registered (app not installed).
        $hash = str_repeat('a', 64);

        $response = $this->get("/s/{$hash}");

        $response->assertOk();
        $response->assertSee('__TC_SHARE_WEB_FALLBACK_BASE__', false);
    }

    public function test_web_fallback_uses_configured_cloud_web_app_url(): void
    {
        // Cloud sets SHARE_UI_WEB_APP_URL to the dedicated web-app
        // origin (app.usework.space) — distinct from the share-host
        // (share.usework.space) the recipient is on.
        config(['teamcore.share.web_app_url' => 'https://app.usework.space']);

        $hash = str_repeat('a', 64);
        $response = $this->get("/s/{$hash}")->assertOk();

        $response->assertSee('"https:\/\/app.usework.space"', false);
    }

    public function test_web_fallback_falls_back_to_request_origin_when_unset(): void
    {
        // Self-hosted single-host installs leave the env var empty;
        // the controller derives the fallback origin from the
        // request so behind-Caddy installs need no extra plumbing.
        config(['teamcore.share.web_app_url' => '']);

        $hash = str_repeat('a', 64);
        $response = $this->get("http://selfhost.example/s/{$hash}")->assertOk();

        $response->assertSee('"http:\/\/selfhost.example"', false);
    }

    public function test_web_fallback_strips_trailing_slash(): void
    {
        // Operators occasionally set SHARE_UI_WEB_APP_URL with a
        // trailing slash. The client appends "/s/{tokenHash}" with a
        // leading slash, so we must not emit "https://host//s/..."
        // (some routers treat that as a 404).
        config(['teamcore.share.web_app_url' => 'https://app.usework.space/']);

        $hash = str_repeat('a', 64);
        $response = $this->get("/s/{$hash}")->assertOk();

        $response->assertSee('"https:\/\/app.usework.space"', false);
        $response->assertDontSee('https:\/\/app.usework.space\/"', false);
    }

    public function test_invalid_token_hash_format_returns_404(): void
    {
        // 64 hex chars is the contract — anything else is a typo.
        $this->get('/s/not-a-valid-hash')->assertNotFound();
    }

    public function test_share_ui_is_disabled_when_self_hosted_toggle_is_off(): void
    {
        config([
            'teamcore.edition' => 'self_hosted',
            'teamcore.features.share_links_enabled' => false,
        ]);

        $hash = str_repeat('b', 64);
        $this->get("/s/{$hash}")->assertNotFound();
    }

    public function test_share_ui_stays_on_when_toggle_off_on_cloud(): void
    {
        // The toggle is named after SELFHOST_SHARE_LINKS_ENABLED for a
        // reason — on cloud, share is always on regardless of the env
        // var's value.
        config([
            'teamcore.edition' => 'cloud',
            'teamcore.features.share_links_enabled' => false,
        ]);

        $hash = str_repeat('c', 64);
        $this->get("/s/{$hash}")->assertOk();
    }

    public function test_share_host_middleware_blocks_api_routes_when_host_set(): void
    {
        config(['teamcore.share.host' => 'share.usework.space']);

        // Symfony's Request->getHost() reads from the URL, not from
        // arbitrary headers — pass an absolute URL so the test client
        // populates the host correctly.
        $response = $this->json('GET', 'http://share.usework.space/api/v1/auth/me');

        $response->assertNotFound();
    }

    public function test_share_host_middleware_allows_share_api_endpoints(): void
    {
        config(['teamcore.share.host' => 'share.usework.space']);

        $hash = str_repeat('d', 64);

        $response = $this->json(
            'GET',
            "http://share.usework.space/api/v1/share-links/{$hash}",
        );

        $this->assertContains($response->status(), [200, 404, 410]);
    }

    public function test_share_host_middleware_noop_when_host_unset(): void
    {
        config(['teamcore.share.host' => '']);

        // Authed API hits work normally on the default host. getJson
        // sets the Accept header so the exception handler renders a
        // JSON 401 instead of trying to redirect to a `login` route.
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}

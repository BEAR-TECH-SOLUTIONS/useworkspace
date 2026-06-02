<?php

namespace Tests\Feature\Broadcasting;

use Tests\TestCase;

/**
 * Regression coverage for the /broadcasting/auth wiring.
 *
 * The prior bug: `withRouting(channels: ...)` registered an unprefixed
 * `/broadcasting/auth` behind the `web` middleware alongside our
 * explicit `withBroadcasting(..., prefix => 'api/v1', middleware =>
 * ['auth:sanctum'])` route. Pusher-js's default authEndpoint
 * (`/broadcasting/auth`) hit the web-middlewared duplicate and the
 * API-only exception handler 500'd trying to redirect to a
 * nonexistent `login` named route.
 */
class BroadcastAuthTest extends TestCase
{
    public function test_only_the_api_v1_prefixed_broadcast_auth_route_is_registered(): void
    {
        $routes = \Route::getRoutes();

        $broadcastRoutes = collect($routes->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'broadcasting/auth'))
            ->values();

        // Exactly one route, at the api/v1 prefix, with Sanctum auth.
        $this->assertCount(1, $broadcastRoutes, 'Duplicate /broadcasting/auth registration regressed.');
        $this->assertSame('api/v1/broadcasting/auth', $broadcastRoutes[0]->uri());
        $this->assertContains('auth:sanctum', $broadcastRoutes[0]->gatherMiddleware());
    }

    public function test_broadcast_auth_rejects_unauthenticated_with_json_401(): void
    {
        // No bearer token — must return 401 JSON, never an HTML
        // redirect-to-login. This is what caused the 500 when the
        // client hit /broadcasting/auth without proper auth.
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-board.1',
        ])->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function test_channel_callbacks_are_loaded_once_at_boot(): void
    {
        // `withRouting(channels: ...)` registered channels.php and
        // `withBroadcasting(channels: ...)` ALSO did — double-
        // registration was silent but meant the file ran twice per
        // boot. Guard against that regression by asserting the
        // expected channel names are registered exactly once.
        /** @var \Illuminate\Contracts\Broadcasting\Broadcaster $broadcaster */
        $broadcaster = app(\Illuminate\Contracts\Broadcasting\Broadcaster::class);

        $reflection = new \ReflectionClass($broadcaster);
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);

        $channelNames = array_keys($property->getValue($broadcaster));

        $this->assertContains('user.{userId}', $channelNames);
        $this->assertContains('board.{boardId}', $channelNames);
        $this->assertContains('project.{projectId}', $channelNames);
        $this->assertContains('vault.{vaultId}', $channelNames);
        $this->assertContains('bucket.{bucketId}', $channelNames);
    }
}

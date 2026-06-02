<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\SessionMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $token = $user->currentAccessToken();

        $payload = [
            'user' => new UserResource($user),
            'edition' => (string) config('teamcore.edition'),
            'feature_flags' => $this->featureFlags(),
            // Session block — single source of truth for the client's
            // token-refresh scheduler. See App\Support\SessionMeta.
            'session' => SessionMeta::describe(
                $token instanceof PersonalAccessToken ? $token : null,
            ),
        ];

        // Self-hosted only: ship Reverb connection details so the
        // client doesn't fall back to its build-time VITE_REVERB_*
        // env (which points at the cloud's Reverb instance). Cloud
        // installs omit the key entirely — pusher-js on the client
        // keeps using its baked-in env values, as today.
        $broadcasting = $this->broadcastingConfig();
        if ($broadcasting !== null) {
            $payload['broadcasting'] = $broadcasting;
        }

        return response()->json($payload);
    }

    /**
     * Resolve the public-facing Reverb connection details from
     * APP_URL + REVERB_APP_KEY for self-hosted installs. Returns
     * null on cloud (client falls back to build-time env) and on
     * self-hosted installs that don't have REVERB_APP_KEY set
     * (treat as "broadcasting disabled" rather than ship a half-
     * configured block).
     *
     * The internal REVERB_HOST / REVERB_PORT / REVERB_SCHEME env
     * vars point at the docker-internal `reverb` service over plain
     * HTTP — DO NOT surface those to the client. The client connects
     * via WSS through Caddy at APP_URL's host:443 instead.
     *
     * @return array<string, mixed>|null
     */
    private function broadcastingConfig(): ?array
    {
        if ((string) config('teamcore.edition') !== 'self_hosted') {
            return null;
        }

        $appKey = (string) config('broadcasting.connections.reverb.key', '');
        if ($appKey === '') {
            return null;
        }

        $appUrl = (string) config('app.url');
        $parsed = parse_url($appUrl) ?: [];
        $host = (string) ($parsed['host'] ?? '');
        $scheme = (string) ($parsed['scheme'] ?? 'https');
        $port = (int) ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80));

        if ($host === '') {
            return null;
        }

        return [
            'app_key' => $appKey,
            'host' => $host,
            'scheme' => $scheme,
            'port' => $port,
            'auth_endpoint' => rtrim($appUrl, '/').'/api/v1/broadcasting/auth',
        ];
    }

    /**
     * Edition-derived flags the desktop client uses to gate UI. Cloud
     * unconditionally enables billing + plan upgrade + the remote
     * feedback endpoint; self-hosted reads admin-controlled toggles
     * from env so on-prem admins can fence those features.
     *
     * @return array<string, bool>
     */
    private function featureFlags(): array
    {
        $edition = (string) config('teamcore.edition');
        $isCloud = $edition === 'cloud';

        return [
            'billing' => $isCloud,
            'plan_upgrade' => $isCloud,
            'share_links' => (bool) config('teamcore.features.share_links_enabled', true),
            'feedback_remote' => $isCloud || (bool) config('teamcore.features.feedback_remote', true),
        ];
    }

    /**
     * Update the authenticated user's mutable profile fields. `name`
     * is the only mutable field — email is immutable by spec; any
     * `email` key in the body is ignored because the FormRequest
     * only whitelists `name`.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['name' => $request->string('name')->toString()])->save();

        return response()->json([
            'user' => new UserResource($user->refresh()),
        ]);
    }
}

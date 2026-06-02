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

        return response()->json([
            'user' => new UserResource($user),
            'edition' => (string) config('teamcore.edition'),
            'feature_flags' => $this->featureFlags(),
            // Session block — single source of truth for the client's
            // token-refresh scheduler. See App\Support\SessionMeta.
            'session' => SessionMeta::describe(
                $token instanceof PersonalAccessToken ? $token : null,
            ),
        ]);
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

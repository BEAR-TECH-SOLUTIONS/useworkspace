<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Single source of truth for the "2FA was just proven on this
 * session" flag (audit L8, L9).
 *
 * Previously the flag was stored under `2fa_verified:{user_id}` —
 * shared across every bearer the user owned. A stolen token could
 * therefore ride a verification proven in another browser/device. The
 * key here is `{user_id}:{token_id}`, so each token must prove 2FA on
 * its own; revoking or logging out one bearer doesn't carry over to
 * another.
 *
 * For the *login* 2FA challenge flow (no bearer yet at verify time)
 * the user-id keyed entry remains the fall-through, but
 * Require2FA's gate is only meaningful for authenticated requests,
 * which always have a token id available.
 */
class TwoFactorVerification
{
    public const TTL_MINUTES = 10;

    /**
     * Mark this token id as having proven 2FA. The fall-through
     * `2fa_verified:{user_id}` key is also written for legacy code
     * paths that don't yet have a token in hand.
     */
    public function mark(User $user, ?int $tokenId): void
    {
        $until = now()->addMinutes(self::TTL_MINUTES);
        Cache::put($this->userKey($user), true, $until);
        if ($tokenId !== null) {
            Cache::put($this->tokenKey($user, $tokenId), true, $until);
        }
    }

    public function verified(User $user, ?int $tokenId): bool
    {
        if ($tokenId === null) {
            // No token context (e.g. challenge flow) — fall back to
            // the user-scoped key.
            return Cache::has($this->userKey($user));
        }

        return Cache::has($this->tokenKey($user, $tokenId));
    }

    public function forget(User $user, ?int $tokenId = null): void
    {
        Cache::forget($this->userKey($user));
        if ($tokenId !== null) {
            Cache::forget($this->tokenKey($user, $tokenId));
        }
    }

    public function verifiedRequest(Request $request): bool
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return false;
        }

        return $this->verified($user, $this->tokenIdFromRequest($request));
    }

    public function tokenIdFromRequest(Request $request): ?int
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return null;
        }

        // currentAccessToken() returns either a PersonalAccessToken
        // (regular Sanctum bearer) or a TransientToken on stateful
        // SPA sessions. TransientTokens have no id, so we coerce to
        // null and fall back to the user-scoped key.
        $token = method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        return $token instanceof PersonalAccessToken ? (int) $token->id : null;
    }

    private function userKey(User $user): string
    {
        return '2fa_verified:'.$user->id;
    }

    private function tokenKey(User $user, int $tokenId): string
    {
        return '2fa_verified:'.$user->id.':'.$tokenId;
    }
}

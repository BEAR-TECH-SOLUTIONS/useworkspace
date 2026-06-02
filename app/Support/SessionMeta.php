<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Single source of truth for the bearer-token lifecycle metadata
 * surfaced to the client. Used by:
 *   • GET  /auth/me               — on every authed request
 *   • POST /auth/token/refresh    — on the response that returns
 *                                   the new token
 *
 * Keeping the computation in one place guarantees the client's
 * refresh scheduler and the server's RefreshTokenController window
 * check stay in sync: a drift here would either make the scheduler
 * call too early (409 churn) or too late (forced login).
 */
class SessionMeta
{
    /**
     * @return array{token_id: int|null, issued_at: string|null, eligible_at: string|null, expires_at: string|null, ttl_minutes: int|null}
     */
    public static function describe(?PersonalAccessToken $token): array
    {
        $ttlMinutes = config('sanctum.expiration');

        if ($token === null || $ttlMinutes === null) {
            return [
                'token_id' => $token?->id !== null ? (int) $token->id : null,
                'issued_at' => null,
                'eligible_at' => null,
                'expires_at' => null,
                'ttl_minutes' => $ttlMinutes === null ? null : (int) $ttlMinutes,
            ];
        }

        $ttlMinutes = (int) $ttlMinutes;
        $issuedAt = $token->created_at instanceof \DateTimeInterface
            ? Carbon::instance($token->created_at)
            : Carbon::parse((string) $token->created_at);

        return [
            'token_id' => (int) $token->id,
            'issued_at' => $issuedAt->toIso8601String(),
            'eligible_at' => $issuedAt->copy()->addMinutes((int) floor($ttlMinutes * 2 / 3))->toIso8601String(),
            'expires_at' => $issuedAt->copy()->addMinutes($ttlMinutes)->toIso8601String(),
            'ttl_minutes' => $ttlMinutes,
        ];
    }
}

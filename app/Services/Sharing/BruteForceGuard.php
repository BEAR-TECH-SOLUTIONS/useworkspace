<?php

namespace App\Services\Sharing;

use App\Enums\NotificationType;
use App\Events\Sharing\ShareLinkBruteForceDetected;
use App\Models\Vault\ShareLink;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks failed-unlock attempts per share-link token and trips the
 * auto-revoke threshold.
 *
 * Per-IP rate-limit lives at the route layer
 * (RateLimiter::for('share-unlock') in AppServiceProvider) — that's
 * the cheap, common-case defence. This guard handles the rarer
 * "single attacker, many IPs" case: 50 failed unlocks within an hour
 * for a single token triggers an auto-revoke and notifies the creator.
 *
 * Plan §11.
 */
class BruteForceGuard
{
    public const THRESHOLD = 50;

    public const WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly ShareLinkRevoker $revoker,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record a failed unlock attempt against a share link. Returns true
     * iff the link tripped the threshold and was auto-revoked.
     */
    public function recordFailure(ShareLink $link): bool
    {
        $key = $this->cacheKey($link);
        $attempts = (int) Cache::increment($key);

        // First write needs a TTL — Cache::increment doesn't set one
        // when the key was missing. Use add() defensively after the
        // increment so we don't accidentally extend the window.
        if ($attempts === 1) {
            Cache::put($key, 1, self::WINDOW_SECONDS);
        }

        if ($attempts < self::THRESHOLD) {
            return false;
        }

        // Tripped — revoke once, then ignore further misses on the
        // already-revoked row (revokeOne is idempotent).
        $revoked = $this->revoker->revokeOne($link, 'brute_force_auto_revoke');

        if ($revoked) {
            ShareLinkBruteForceDetected::dispatch($link->refresh(), $attempts);

            $this->notifications->create(
                userId: (int) $link->created_by,
                type: NotificationType::ShareLinkBruteForce,
                title: 'A share link was auto-revoked after repeated failed unlocks',
                body: 'The recipient (or an attacker) hit '.$attempts.' wrong attempts in an hour.',
                metadata: [
                    'share_link_id' => (int) $link->id,
                    'resource_type' => $link->resource_type,
                    'attempts' => $attempts,
                ],
            );
        }

        return $revoked;
    }

    public function reset(ShareLink $link): void
    {
        Cache::forget($this->cacheKey($link));
    }

    private function cacheKey(ShareLink $link): string
    {
        return 'share-link:brute-force:'.$link->id;
    }
}

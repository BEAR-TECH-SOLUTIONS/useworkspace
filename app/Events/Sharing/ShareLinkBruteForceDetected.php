<?php

namespace App\Events\Sharing;

use App\Events\BroadcastEvent;
use App\Models\Vault\ShareLink;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Fires when a share link crosses the per-token brute-force threshold
 * (50 failed unlocks in an hour). The link has already been auto-revoked
 * by the time this dispatches. Plan §11.
 */
class ShareLinkBruteForceDetected extends BroadcastEvent
{
    public function __construct(
        public readonly ShareLink $link,
        public readonly int $attempts,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->link->created_by)];
    }

    public function broadcastAs(): string
    {
        return 'share_link.brute_force';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'share_link_id' => (int) $this->link->id,
            'attempts' => $this->attempts,
        ];
    }
}

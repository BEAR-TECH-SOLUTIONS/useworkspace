<?php

namespace App\Events\Sharing;

use App\Events\BroadcastEvent;
use App\Models\Vault\ShareLink;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;

/**
 * Fires on every successful payload render of a share link. Drives
 * the desktop notification bell — the creator sees access in real time.
 */
class ShareLinkViewed extends BroadcastEvent
{
    public function __construct(
        public readonly ShareLink $link,
        public readonly bool $wasUnlocked,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->link->created_by)];
    }

    public function broadcastAs(): string
    {
        return 'share_link.viewed';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'share_link_id' => (int) $this->link->id,
            'resource_type' => $this->link->resource_type,
            'resource_id' => (int) $this->link->resource_id,
            'viewed_at' => Carbon::now()->toIso8601String(),
            'was_unlocked' => $this->wasUnlocked,
        ];
    }
}

<?php

namespace App\Events\Sharing;

use App\Events\BroadcastEvent;
use App\Models\Vault\ShareLink;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Fires when a share link is revoked — manually, by max_views auto-cap,
 * by brute-force auto-revoke, or by housekeeping policy
 * (creator password change / 2FA recovery / vault rotation).
 */
class ShareLinkRevoked extends BroadcastEvent
{
    public function __construct(
        public readonly ShareLink $link,
        public readonly string $reason,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->link->created_by)];
    }

    public function broadcastAs(): string
    {
        return 'share_link.revoked';
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'share_link_id' => (int) $this->link->id,
            'reason' => $this->reason,
        ];
    }
}

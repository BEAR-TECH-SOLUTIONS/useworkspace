<?php

namespace App\Services\Sharing;

use App\Enums\AuditAction;
use App\Events\Sharing\ShareLinkRevoked;
use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\ShareLink;
use App\Models\Vault\Vault;
use App\Services\Permissions\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-revoke active share links in response to housekeeping events
 * (account password change, 2FA recovery, account deletion, vault key
 * rotation) and brute-force auto-revoke.
 *
 * NB: these are *housekeeping policy*, not crypto defense. The share's
 * encryption key is derived from the share password — completely
 * independent of the user's account password and the vault key. Auto-
 * revoke here protects against "the user's intent for this link is
 * stale", not against a crypto break. Plan §10.
 */
class ShareLinkRevoker
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Revoke every active share link the user created.
     */
    public function revokeAllForCreator(User $user, string $reason, ?User $actor = null): int
    {
        return $this->revokeAll(
            ShareLink::query()
                ->where('created_by', $user->id)
                ->whereNull('revoked_at'),
            $reason,
            $actor ?? $user,
        );
    }

    /**
     * Revoke every active credential share whose source credential
     * lives in the given vault.
     */
    public function revokeAllForVault(Vault $vault, string $reason, ?User $actor = null): int
    {
        $credentialIds = Credential::query()
            ->where('vault_id', $vault->id)
            ->pluck('id');

        if ($credentialIds->isEmpty()) {
            return 0;
        }

        return $this->revokeAll(
            ShareLink::query()
                ->where('resource_type', 'credential')
                ->whereIn('resource_id', $credentialIds)
                ->whereNull('revoked_at'),
            $reason,
            $actor,
        );
    }

    /**
     * Revoke a single share link with a structured reason. Idempotent —
     * already-revoked rows are left alone.
     */
    public function revokeOne(ShareLink $link, string $reason, ?User $actor = null): bool
    {
        if ($link->revoked_at !== null) {
            return false;
        }

        DB::transaction(function () use ($link, $reason, $actor): void {
            $link->update(['revoked_at' => Carbon::now()]);

            $this->audit->record(
                actor: $actor,
                action: AuditAction::ShareLinkRevoked,
                projectId: $link->project_id,
                resourceType: null,
                resourceId: $link->id,
                metadata: [
                    'share_link_id' => $link->id,
                    'reason' => $reason,
                    'auto' => true,
                ],
            );
        });

        ShareLinkRevoked::dispatch($link->refresh(), $reason);

        return true;
    }

    private function revokeAll(\Illuminate\Database\Eloquent\Builder $query, string $reason, ?User $actor): int
    {
        $count = 0;

        $query->lazy()->each(function (ShareLink $link) use ($reason, $actor, &$count): void {
            if ($this->revokeOne($link, $reason, $actor)) {
                $count++;
            }
        });

        return $count;
    }
}

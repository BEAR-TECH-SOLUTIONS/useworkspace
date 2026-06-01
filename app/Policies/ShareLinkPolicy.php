<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vault\ShareLink;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

/**
 * Authorises owner-side reads/writes of a single ShareLink row.
 *
 * Two paths grant access: the original creator always has it (they
 * minted the link), and any user with `share` permission on the
 * underlying source resource has it (so a project owner can revoke a
 * teammate's link to their own resource).
 */
class ShareLinkPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, ShareLink $link): bool
    {
        return $this->isCreator($user, $link) || $this->canShareResource($user, $link);
    }

    public function delete(User $user, ShareLink $link): bool
    {
        return $this->isCreator($user, $link) || $this->canShareResource($user, $link);
    }

    public function viewAudit(User $user, ShareLink $link): bool
    {
        return $this->delete($user, $link);
    }

    private function isCreator(User $user, ShareLink $link): bool
    {
        return (int) $link->created_by === (int) $user->id;
    }

    private function canShareResource(User $user, ShareLink $link): bool
    {
        $resource = $link->resource;

        if ($resource === null) {
            return false;
        }

        return $this->perms->can($user, Abilities::SHARE, $resource);
    }
}

<?php

namespace App\Policies;

use App\Models\Docs\Doc;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class DocPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, Doc $doc): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $doc);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Doc $doc): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $doc);
    }

    public function archive(User $user, Doc $doc): bool
    {
        return $this->perms->can($user, Abilities::ARCHIVE, $doc);
    }

    public function delete(User $user, Doc $doc): bool
    {
        return $this->perms->can($user, Abilities::DELETE, $doc);
    }

    public function share(User $user, Doc $doc): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $doc);
    }
}

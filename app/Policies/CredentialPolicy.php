<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vault\Credential;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class CredentialPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, Credential $credential): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $credential);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Credential $credential): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $credential);
    }

    public function delete(User $user, Credential $credential): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $credential);
    }

    public function share(User $user, Credential $credential): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $credential);
    }
}

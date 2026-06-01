<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class VaultPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, Vault $vault): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $vault);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Vault $vault): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $vault);
    }

    public function archive(User $user, Vault $vault): bool
    {
        return $this->perms->can($user, Abilities::ARCHIVE, $vault);
    }

    public function delete(User $user, Vault $vault): bool
    {
        return $this->perms->can($user, Abilities::DELETE, $vault);
    }

    public function share(User $user, Vault $vault): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $vault);
    }
}

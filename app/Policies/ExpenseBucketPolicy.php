<?php

namespace App\Policies;

use App\Models\Expenses\ExpenseBucket;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class ExpenseBucketPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, ExpenseBucket $bucket): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $bucket);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ExpenseBucket $bucket): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $bucket);
    }

    public function archive(User $user, ExpenseBucket $bucket): bool
    {
        return $this->perms->can($user, Abilities::ARCHIVE, $bucket);
    }

    public function delete(User $user, ExpenseBucket $bucket): bool
    {
        return $this->perms->can($user, Abilities::DELETE, $bucket);
    }

    public function share(User $user, ExpenseBucket $bucket): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $bucket);
    }
}

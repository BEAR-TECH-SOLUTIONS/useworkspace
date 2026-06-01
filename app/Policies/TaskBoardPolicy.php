<?php

namespace App\Policies;

use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class TaskBoardPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, TaskBoard $board): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $board);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TaskBoard $board): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $board);
    }

    public function archive(User $user, TaskBoard $board): bool
    {
        return $this->perms->can($user, Abilities::ARCHIVE, $board);
    }

    public function delete(User $user, TaskBoard $board): bool
    {
        return $this->perms->can($user, Abilities::DELETE, $board);
    }

    public function share(User $user, TaskBoard $board): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $board);
    }
}

<?php

namespace App\Policies;

use App\Models\Expenses\Expense;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class ExpensePolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, Expense $expense): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $expense);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $expense);
    }

    public function share(User $user, Expense $expense): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $expense);
    }
}

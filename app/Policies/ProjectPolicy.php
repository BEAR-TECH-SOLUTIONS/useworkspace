<?php

namespace App\Policies;

use App\Models\Project\Project;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;

class ProjectPolicy
{
    public function __construct(private readonly PermissionService $perms) {}

    public function view(User $user, Project $project): bool
    {
        return $this->perms->can($user, Abilities::VIEW, $project);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->perms->can($user, Abilities::UPDATE, $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->perms->can($user, Abilities::ARCHIVE, $project);
    }

    public function share(User $user, Project $project): bool
    {
        return $this->perms->can($user, Abilities::SHARE, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        if ($project->is_personal) {
            return false;
        }

        // Destructive ops are restricted to the user who originally
        // created the project, not every owner-role member.
        return $user->id === $project->original_owner_id;
    }

    public function purgeContents(User $user, Project $project): bool
    {
        if ($project->is_personal) {
            return false;
        }

        return $user->id === $project->original_owner_id;
    }
}

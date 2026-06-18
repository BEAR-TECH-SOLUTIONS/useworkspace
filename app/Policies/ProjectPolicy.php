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

        return $this->canDestroy($user, $project);
    }

    public function purgeContents(User $user, Project $project): bool
    {
        if ($project->is_personal) {
            return false;
        }

        return $this->canDestroy($user, $project);
    }

    /**
     * Destructive project operations are authorised for the user who
     * originally created the project, or the owner of the workspace
     * (organisation) the project belongs to — so a workspace owner can
     * wipe projects created by their members. Other owner-role project
     * members and non-owner workspace admins still receive 403.
     */
    private function canDestroy(User $user, Project $project): bool
    {
        return $user->id === $project->original_owner_id
            || $user->id === $project->organisation->owner_id;
    }
}

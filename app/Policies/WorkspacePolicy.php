<?php

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\User;

/**
 * Gates for the workspace surface (DB table: organisations).
 *
 * Workspace membership is identity, not project access — any member can
 * `view` the workspace and its directory, but billing, member-role
 * changes, and member removal are admin-only. The legacy
 * `organisations.owner_id` is treated as an implicit admin so pre-
 * migration state never locks anyone out of their own workspace.
 */
class WorkspacePolicy
{
    public function view(User $user, Organisation $workspace): bool
    {
        return $this->hasMembership($user, $workspace);
    }

    public function update(User $user, Organisation $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    public function manageMembers(User $user, Organisation $workspace): bool
    {
        // The earlier H17 fix here blocked manageMembers on
        // workspaces flagged `is_personal=true`, on the assumption
        // that personal workspaces stay solo. That assumption was
        // wrong: `is_personal` is just a marker for the workspace
        // auto-bootstrapped at register (so it's exempt from the
        // per-user workspace cap from M11). Owners are free to
        // invite/provision members into their personal workspace
        // just like any other. The personal-vs-shared invariant
        // lives on the *project* level, not the workspace level.
        return $this->isAdmin($user, $workspace);
    }

    /**
     * Admin-only read for the access-picker UI (the member-add
     * dialog's project/resource tree). Distinct from
     * manageMembers because this is a pure read — surfacing the
     * resource list to a personal-workspace admin is harmless
     * (there's nothing to enumerate beyond their own project),
     * and the H17 personal-workspace block on manageMembers was
     * about MUTATIONS, not reads. Keeping it on manageMembers
     * meant team-tier admins of any workspace that happened to
     * be flagged personal couldn't load the picker — generic
     * 403, no code, identical drift symptom to the provision-user
     * fix.
     */
    public function viewResourceTree(User $user, Organisation $workspace): bool
    {
        return $this->isAdmin($user, $workspace);
    }

    /**
     * Admin-or-member-with-toggle gate for inviting new users. The
     * `members_can_invite_members` flag is admin-controlled per
     * workspace; when false the gate degrades to admin-only.
     *
     * (Personal workspaces *are* invitable — the previous H17 block
     * here was based on a wrong assumption. See manageMembers.)
     */
    public function inviteMembers(User $user, Organisation $workspace): bool
    {
        if ($this->isAdmin($user, $workspace)) {
            return true;
        }

        if (! (bool) ($workspace->members_can_invite_members ?? true)) {
            return false;
        }

        return $this->hasMembership($user, $workspace);
    }

    /**
     * Admin-or-member-with-toggle gate for creating new projects in
     * this workspace. Mirrors the inviteMembers shape.
     */
    public function createProjects(User $user, Organisation $workspace): bool
    {
        if ($this->isAdmin($user, $workspace)) {
            return true;
        }

        if (! (bool) ($workspace->members_can_create_projects ?? true)) {
            return false;
        }

        return $this->hasMembership($user, $workspace);
    }

    private function hasMembership(User $user, Organisation $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    private function isAdmin(User $user, Organisation $workspace): bool
    {
        if ($workspace->owner_id === $user->id) {
            return true;
        }

        return OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('role', OrganisationRole::Admin->value)
            ->exists();
    }
}

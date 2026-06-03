<?php

namespace App\Services\Project;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Docs\Doc;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Docs\WelcomeDocContent;

/**
 * Wires up everything a freshly-created project needs:
 *   - The owner's project-level ResourcePermission row (the ACL anchor)
 *   - Default board (with three columns), default vault, default expense
 *     bucket, and a "Welcome" doc so new users land in a
 *     populated project instead of empty panes.
 *
 * "Project membership" lives entirely in resource_permissions now — there
 * is no shadow project_members table. The vault key for the owner is
 * minted client-side and uploaded via POST /vaults/{vault}/migrate-key,
 * so this bootstrapper carries no crypto material.
 *
 * Caller is responsible for wrapping this in a transaction.
 */
class ProjectBootstrapper
{
    public function __construct(private readonly WelcomeDocContent $welcomeDoc) {}

    public function bootstrap(Project $project, User $owner): void
    {
        ResourcePermission::create([
            'user_id' => $owner->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Owner->value,
            'granted_by' => $owner->id,
        ]);

        // Workspace-owner invariant: the workspaces.owner_id user
        // always has Owner access on every resource in their
        // workspace. We grant project-level here (cascades to every
        // child board / vault / bucket / doc) so the row is in place
        // BEFORE the client builds its migrate-key grant set — the
        // ordering matters because assertVaultGrantRecipients would
        // 422 if the workspace owner was missing from the grants.
        //
        // No-op when the project creator IS the workspace owner
        // (most common case: personal workspace, or workspace owner
        // creates a project themselves). updateOrCreate via the
        // (user_id, resource_type, resource_id) unique index would
        // also work; we prefer the explicit check so the audit
        // trail (granted_by) is clean.
        $workspaceOwnerId = (int) Organisation::query()
            ->whereKey($project->organisation_id)
            ->value('owner_id');

        if ($workspaceOwnerId > 0 && $workspaceOwnerId !== (int) $owner->id) {
            ResourcePermission::query()->updateOrCreate(
                [
                    'user_id' => $workspaceOwnerId,
                    'resource_type' => ResourceType::Project->value,
                    'resource_id' => $project->id,
                ],
                [
                    'project_id' => $project->id,
                    'role' => MemberRole::Owner->value,
                    'granted_by' => $owner->id,
                ],
            );
        }

        $board = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Tasks',
            'is_default' => true,
            'created_by' => $owner->id,
        ]);

        foreach (['To do' => 10000.0, 'In progress' => 20000.0, 'Done' => 30000.0] as $name => $position) {
            TaskColumn::create([
                'board_id' => $board->id,
                'name' => $name,
                'position' => $position,
            ]);
        }

        Vault::create([
            'project_id' => $project->id,
            'name' => 'Default vault',
            'is_default' => true,
            'created_by' => $owner->id,
        ]);

        ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'General',
            'currency' => 'USD',
            'is_default' => true,
            'created_by' => $owner->id,
        ]);

        // Canonical onboarding doc — plaintext is derived from the
        // BlockNote JSON by the extractor, so the two stay in lockstep
        // (one source of truth, not drifting content/content_text).
        Doc::create([
            'project_id' => $project->id,
            'title' => WelcomeDocContent::TITLE,
            'content' => $this->welcomeDoc->content(),
            'content_text' => $this->welcomeDoc->plaintext(),
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
    }
}

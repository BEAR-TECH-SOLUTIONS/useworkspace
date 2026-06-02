<?php

namespace Tests\Feature\Projects;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Support\Str;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Verifies the §5 most-specific-grant-wins resolution rule using the
 * three-user setup CLAUDE §12 mandates: owner, project-wide editor, and
 * a scoped editor with only one child grant.
 */
class PermissionResolutionTest extends TestCase
{
    public function test_owner_has_full_access_via_owner_id(): void
    {
        [$owner] = $this->scenario();
        $project = $this->ownedProject($owner);
        $perms = app(PermissionService::class);

        $this->assertSame(MemberRole::Owner, $perms->effectiveRole($owner, $project));
        $this->assertTrue($perms->can($owner, Abilities::DELETE, $project));
    }

    public function test_project_wide_editor_can_view_and_update_but_not_delete(): void
    {
        [$owner, $projectWideEditor] = $this->scenario();
        $project = $this->ownedProject($owner);

        ResourcePermission::create([
            'user_id' => $projectWideEditor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $perms = app(PermissionService::class);

        $this->assertSame(MemberRole::Editor, $perms->effectiveRole($projectWideEditor, $project));
        $this->assertTrue($perms->can($projectWideEditor, Abilities::UPDATE, $project));
        $this->assertFalse($perms->can($projectWideEditor, Abilities::DELETE, $project));
    }

    public function test_scoped_editor_with_only_board_grant_cannot_see_project(): void
    {
        [$owner, , $scopedEditor] = $this->scenario();
        $project = $this->ownedProject($owner);

        $board = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Side board',
            'created_by' => $owner->id,
        ]);

        ResourcePermission::create([
            'user_id' => $scopedEditor->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $perms = app(PermissionService::class);

        // No project-level grant → cannot view the project itself.
        $this->assertNull($perms->effectiveRole($scopedEditor, $project));
        $this->assertFalse($perms->can($scopedEditor, Abilities::VIEW, $project));

        // But the board-level grant resolves correctly.
        $this->assertSame(MemberRole::Editor, $perms->effectiveRole($scopedEditor, $board));
        $this->assertTrue($perms->can($scopedEditor, Abilities::UPDATE, $board));
    }

    public function test_specific_grant_overrides_project_level_grant(): void
    {
        [$owner, $user] = $this->scenario();
        $project = $this->ownedProject($owner);

        $board = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Locked-down board',
            'created_by' => $owner->id,
        ]);

        // Project-wide editor.
        ResourcePermission::create([
            'user_id' => $user->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // But viewer-only on this specific board (more specific wins).
        ResourcePermission::create([
            'user_id' => $user->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $perms = app(PermissionService::class);

        $this->assertSame(MemberRole::Editor, $perms->effectiveRole($user, $project));
        $this->assertSame(MemberRole::Viewer, $perms->effectiveRole($user, $board));
        $this->assertFalse($perms->can($user, Abilities::UPDATE, $board));
        $this->assertTrue($perms->can($user, Abilities::VIEW, $board));
    }

    public function test_visible_scope_for_boards(): void
    {
        [$owner, $projectWide, $scoped] = $this->scenario();
        $project = $this->ownedProject($owner);

        $boardA = TaskBoard::create(['project_id' => $project->id, 'name' => 'A', 'created_by' => $owner->id]);
        $boardB = TaskBoard::create(['project_id' => $project->id, 'name' => 'B', 'created_by' => $owner->id]);

        // Project-wide editor sees both.
        ResourcePermission::create([
            'user_id' => $projectWide->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // Scoped user only sees boardB.
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $boardB->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $perms = app(PermissionService::class);

        // The project owner sees every board (default board from bootstrap + A + B).
        $ownerVisible = $perms->visibleScope($owner, ResourceType::Board, $project)->pluck('id')->all();
        $this->assertContains($boardA->id, $ownerVisible);
        $this->assertContains($boardB->id, $ownerVisible);

        $projectWideVisible = $perms->visibleScope($projectWide, ResourceType::Board, $project)->pluck('id')->all();
        $this->assertContains($boardA->id, $projectWideVisible);
        $this->assertContains($boardB->id, $projectWideVisible);

        $scopedVisible = $perms->visibleScope($scoped, ResourceType::Board, $project)->pluck('id')->all();
        $this->assertSame([$boardB->id], $scopedVisible);
    }

    /**
     * @return array{0: User, 1: User, 2: User}
     */
    private function scenario(): array
    {
        return [UserFactory::create(), UserFactory::create(), UserFactory::create()];
    }

    private function ownedProject(User $owner): Project
    {
        $org = Organisation::create([
            'owner_id' => $owner->id,
            'name' => 'Org '.bin2hex(random_bytes(3)),
            'slug' => 'org-'.Str::random(8),
        ]);

        OrganisationMember::create([
            'organisation_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $org->id,
                'name' => 'Project '.bin2hex(random_bytes(3)),
            ])
            ->assertCreated();

        return Project::findOrFail($response->json('project.id'));
    }
}

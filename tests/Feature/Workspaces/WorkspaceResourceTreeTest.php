<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class WorkspaceResourceTreeTest extends TestCase
{
    public function test_admin_receives_full_resource_tree_sorted_by_name(): void
    {
        $admin = UserFactory::create();

        // ProjectFactory creates a workspace with one default project
        // + default board/vault/bucket. Add a second project in the
        // same workspace so we can assert the cross-project list.
        $alpha = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($alpha->organisation_id)->firstOrFail();
        $alpha->forceFill(['name' => 'Alpha', 'icon' => 'rocket', 'color' => '#6366f1'])->save();

        $zeta = ProjectFactory::forOwner($admin);
        $zeta->forceFill(['organisation_id' => $workspace->id, 'name' => 'Zeta'])->save();

        // Seed an extra board + vault + bucket on Alpha so we can check
        // resource-level sort order.
        TaskBoard::create(['project_id' => $alpha->id, 'name' => 'Sprint 12', 'created_by' => $admin->id]);
        Vault::create(['project_id' => $alpha->id, 'name' => 'Staging', 'created_by' => $admin->id, 'is_default' => false]);
        ExpenseBucket::create([
            'project_id' => $alpha->id,
            'name' => 'Infrastructure',
            'currency' => 'USD',
            'created_by' => $admin->id,
            'is_default' => false,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $projects = $response->json('projects');
        $this->assertCount(2, $projects);

        // Alphabetical project order.
        $this->assertSame('Alpha', $projects[0]['name']);
        $this->assertSame('Zeta', $projects[1]['name']);

        $this->assertSame('rocket', $projects[0]['icon']);
        $this->assertSame('#6366f1', $projects[0]['color']);

        // Alpha has the default board ("Tasks") + the seeded "Sprint 12",
        // sorted by name.
        $boardNames = array_column($projects[0]['boards'], 'name');
        $this->assertSame(['Sprint 12', 'Tasks'], $boardNames);

        // Vaults include `migrated_at` for the invite key-wrap check
        // (non-null since every vault is born migrated).
        foreach ($projects[0]['vaults'] as $vault) {
            $this->assertArrayHasKey('migrated_at', $vault);
        }

        // Bucket shape is id+name only.
        foreach ($projects[0]['buckets'] as $bucket) {
            $this->assertSame(['id', 'name'], array_keys($bucket));
        }
    }

    public function test_excludes_archived_vaults_and_buckets(): void
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $liveVault = Vault::create(['project_id' => $project->id, 'name' => 'Live vault', 'created_by' => $admin->id]);
        $archivedVault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Archived vault',
            'created_by' => $admin->id,
            'is_archived' => true,
        ]);

        $liveBucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Live bucket',
            'currency' => 'USD',
            'created_by' => $admin->id,
        ]);
        $archivedBucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Archived bucket',
            'currency' => 'USD',
            'created_by' => $admin->id,
            'is_archived' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $project0 = $response->json('projects.0');
        $vaultIds = array_column($project0['vaults'], 'id');
        $bucketIds = array_column($project0['buckets'], 'id');

        $this->assertContains($liveVault->id, $vaultIds);
        $this->assertNotContains($archivedVault->id, $vaultIds);
        $this->assertContains($liveBucket->id, $bucketIds);
        $this->assertNotContains($archivedBucket->id, $bucketIds);
    }

    public function test_returns_projects_with_zero_resources(): void
    {
        // Bare workspace with no ProjectFactory seed — empty projects list.
        $admin = UserFactory::create();
        $workspace = Organisation::create([
            'owner_id' => $admin->id,
            'name' => 'Empty',
            'slug' => 'empty-'.bin2hex(random_bytes(3)),
            'is_personal' => false,
            'tier' => 'free',
            'seat_cap' => 1,
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk()
            ->assertExactJson(['projects' => []]);
    }

    public function test_non_admin_member_is_forbidden(): void
    {
        $admin = UserFactory::create();
        $regularMember = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $regularMember->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($regularMember)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertForbidden();
    }

    public function test_does_not_leak_projects_from_other_workspaces(): void
    {
        // Admin owns workspace A with "InScope" project + also admins
        // workspace B with "OtherWorkspace" project. Hitting A must
        // only surface InScope — the query must scope on
        // organisation_id, not just "projects the caller can see".
        $admin = UserFactory::create();

        $inScope = ProjectFactory::forOwner($admin);
        $workspaceA = Organisation::query()->whereKey($inScope->organisation_id)->firstOrFail();
        $inScope->forceFill(['name' => 'InScope'])->save();

        $otherWorkspaceProject = ProjectFactory::forOwner($admin);
        $workspaceB = Organisation::query()->whereKey($otherWorkspaceProject->organisation_id)->firstOrFail();
        $otherWorkspaceProject->forceFill(['name' => 'OtherWorkspace'])->save();

        $this->assertNotSame($workspaceA->id, $workspaceB->id, 'Precondition: distinct workspaces.');

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspaceA->id}/resource-tree")
            ->assertOk();

        $projectNames = array_column($response->json('projects'), 'name');

        $this->assertContains('InScope', $projectNames);
        $this->assertNotContains('OtherWorkspace', $projectNames);
    }

    public function test_outsider_is_forbidden(): void
    {
        $admin = UserFactory::create();
        $outsider = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $this->actingAs($outsider)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertForbidden();
    }

    public function test_non_owner_admin_sees_only_projects_they_have_grants_on(): void
    {
        // Owner seeds two projects; a second admin is added to the
        // workspace but only granted project-level access on one of
        // them. The tree must hide the other project entirely.
        $owner = UserFactory::create();
        $visible = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($visible->organisation_id)->firstOrFail();
        $visible->forceFill(['name' => 'Visible'])->save();

        $hidden = ProjectFactory::forOwner($owner);
        $hidden->forceFill(['organisation_id' => $workspace->id, 'name' => 'Hidden'])->save();

        $admin = $this->makeWorkspaceAdmin($workspace, $owner);

        ResourcePermission::create([
            'user_id' => $admin->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $visible->id,
            'project_id' => $visible->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $names = array_column($response->json('projects'), 'name');
        $this->assertSame(['Visible'], $names);
    }

    public function test_non_owner_admin_with_only_child_grants_sees_only_those_resources(): void
    {
        // Pattern B: admin holds a direct board grant and a direct
        // bucket grant inside one project, no project-level row. The
        // project shows up but only the two granted resources — the
        // sibling vault + default board/bucket stay hidden.
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        $project->forceFill(['name' => 'Shared'])->save();

        // Extra resources on the same project so there's noise to filter.
        $visibleBoard = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Visible board',
            'created_by' => $owner->id,
        ]);
        $hiddenBoard = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Hidden board',
            'created_by' => $owner->id,
        ]);
        $visibleBucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Visible bucket',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);

        $admin = $this->makeWorkspaceAdmin($workspace, $owner);

        ResourcePermission::create([
            'user_id' => $admin->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $visibleBoard->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);
        ResourcePermission::create([
            'user_id' => $admin->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $visibleBucket->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $projects = $response->json('projects');
        $this->assertCount(1, $projects);
        $this->assertSame('Shared', $projects[0]['name']);

        $boardIds = array_column($projects[0]['boards'], 'id');
        $this->assertSame([$visibleBoard->id], $boardIds);
        $this->assertNotContains($hiddenBoard->id, $boardIds);

        // Admin has no vault grant on this project → vaults must be empty.
        $this->assertSame([], $projects[0]['vaults']);

        $bucketIds = array_column($projects[0]['buckets'], 'id');
        $this->assertSame([$visibleBucket->id], $bucketIds);
    }

    public function test_non_owner_admin_with_project_grant_sees_all_resources_via_cascade(): void
    {
        // Project-level grant cascades to every child — same picker
        // shape as the owner sees. A direct child grant alongside the
        // project grant is redundant and must NOT narrow the list.
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $extraBoard = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Extra board',
            'created_by' => $owner->id,
        ]);

        $admin = $this->makeWorkspaceAdmin($workspace, $owner);
        ResourcePermission::create([
            'user_id' => $admin->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $boardNames = array_column($response->json('projects.0.boards'), 'name');
        $this->assertContains('Extra board', $boardNames);
        $this->assertContains('Tasks', $boardNames);
    }

    public function test_non_owner_admin_with_no_grants_gets_empty_tree(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $admin = $this->makeWorkspaceAdmin($workspace, $owner);

        $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk()
            ->assertExactJson(['projects' => []]);
    }

    private function makeWorkspaceAdmin(Organisation $workspace, User $invitedBy): User
    {
        $admin = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $admin->id,
            'role' => OrganisationRole::Admin->value,
            'invited_by' => $invitedBy->id,
            'joined_at' => now(),
        ]);

        return $admin;
    }
}

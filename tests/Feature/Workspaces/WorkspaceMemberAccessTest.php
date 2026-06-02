<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * GET /workspaces/{w}/members/{u}/access — single-query aggregate the
 * "Edit access" modal builds against.
 */
class WorkspaceMemberAccessTest extends TestCase
{
    public function test_aggregates_project_and_resource_grants(): void
    {
        $admin = UserFactory::create();
        $target = UserFactory::create();

        // Admin's project — ProjectFactory creates the workspace too.
        $projectA = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($projectA->organisation_id)->firstOrFail();

        // Second project in the same workspace.
        $projectB = ProjectFactory::forOwner($admin);
        $projectB->organisation_id = $workspace->id;
        $projectB->save();

        // Target is a workspace member.
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $target->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        // Project A: project-wide editor cascade.
        ResourcePermission::create([
            'user_id' => $target->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $projectA->id,
            'project_id' => $projectA->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $admin->id,
        ]);

        // Project B: one direct vault grant (Pattern B). ProjectFactory
        // seeded a default vault on create.
        $vaultB = $projectB->vaults()->first();
        ResourcePermission::create([
            'user_id' => $target->id,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vaultB->id,
            'project_id' => $projectB->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/members/{$target->id}/access")
            ->assertOk()
            ->assertJsonPath('access.user_id', $target->id)
            ->assertJsonPath('access.user.public_key', $target->public_key);

        $projects = collect($response->json('access.projects'))
            ->keyBy('project_id')
            ->all();

        $this->assertArrayHasKey($projectA->id, $projects);
        $this->assertArrayHasKey($projectB->id, $projects);

        $this->assertSame('project', $projects[$projectA->id]['mode']);
        $this->assertSame('editor', $projects[$projectA->id]['project_role']);
        $this->assertNull($projects[$projectA->id]['resources']);

        $this->assertSame('resources', $projects[$projectB->id]['mode']);
        $this->assertNull($projects[$projectB->id]['project_role']);
        $this->assertCount(1, $projects[$projectB->id]['resources']);
        $this->assertSame('vault', $projects[$projectB->id]['resources'][0]['type']);
        $this->assertSame($vaultB->id, $projects[$projectB->id]['resources'][0]['id']);
    }

    public function test_member_with_no_grants_returns_empty_projects(): void
    {
        $admin = UserFactory::create();
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $target->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/members/{$target->id}/access")
            ->assertOk()
            ->assertJsonPath('access.projects', []);
    }

    public function test_non_admin_gets_403(): void
    {
        $admin = UserFactory::create();
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $target->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($target)
            ->getJson("/api/v1/workspaces/{$workspace->id}/members/{$target->id}/access")
            ->assertStatus(403);
    }

    public function test_non_member_target_returns_404(): void
    {
        $admin = UserFactory::create();
        $stranger = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/members/{$stranger->id}/access")
            ->assertStatus(404);
    }
}

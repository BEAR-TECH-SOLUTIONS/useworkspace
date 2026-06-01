<?php

namespace Tests\Feature\Auth;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    public function test_returns_user_workspaces_projects_and_access_in_one_call(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        // Laravel normalises directive order; assert on the directives
        // themselves rather than exact string match.
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);

        $response->assertJsonPath('user.id', $owner->id)
            ->assertJsonPath('workspaces.0.id', $workspace->id)
            ->assertJsonPath('projects.0.id', $project->id)
            ->assertJsonPath(
                "access.project_role_by_project_id.{$project->id}",
                MemberRole::Owner->value,
            );
    }

    public function test_project_payload_inlines_boards_vaults_buckets_with_same_shapes_as_per_type_endpoints(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        TaskBoard::create(['project_id' => $project->id, 'name' => 'Sprint 12', 'created_by' => $owner->id]);
        Vault::create(['project_id' => $project->id, 'name' => 'Production', 'created_by' => $owner->id]);
        ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Infrastructure',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $row = collect($response->json('projects'))->firstWhere('id', $project->id);
        $this->assertNotNull($row);

        $boardNames = array_column($row['boards'], 'name');
        $vaultNames = array_column($row['vaults'], 'name');
        $bucketNames = array_column($row['buckets'], 'name');

        $this->assertContains('Sprint 12', $boardNames);
        $this->assertContains('Production', $vaultNames);
        $this->assertContains('Infrastructure', $bucketNames);

        // Vault entries carry `my_wrapped_key` (even if null) — same
        // shape as GET /projects/{p}/vaults.
        $this->assertArrayHasKey('my_wrapped_key', $row['vaults'][0]);
    }

    public function test_wrapped_key_map_is_keyed_by_vault_and_populated_for_migrated_vaults(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now()])->save();

        ResourceKey::create([
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'encrypted_key' => 'owner-wrapped',
            'key_version' => 1,
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $this->assertSame(
            'owner-wrapped',
            $response->json("access.wrapped_key_by_resource_key.vault:{$vault->id}.encrypted_key"),
        );
        $this->assertSame(
            1,
            $response->json("access.wrapped_key_by_resource_key.vault:{$vault->id}.key_version"),
        );

        // `resource_role_by_key` includes cascaded children — the
        // owner owns the project, so every vault/board/bucket in it
        // should appear with role=owner.
        $this->assertSame(
            MemberRole::Owner->value,
            $response->json("access.resource_role_by_key.vault:{$vault->id}"),
        );
    }

    public function test_pattern_b_user_sees_only_granted_projects_and_resources(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $visibleBoard = TaskBoard::create(['project_id' => $project->id, 'name' => 'Visible', 'created_by' => $owner->id]);
        $hiddenBoard = TaskBoard::create(['project_id' => $project->id, 'name' => 'Hidden', 'created_by' => $owner->id]);

        // Second project the scoped user has NO grants in — must not
        // leak into the bootstrap payload.
        $otherProject = ProjectFactory::forOwner($owner);

        $scoped = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $visibleBoard->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($scoped)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $projectIds = array_column($response->json('projects'), 'id');
        $this->assertSame([$project->id], $projectIds);

        $boardIds = array_column($response->json('projects.0.boards'), 'id');
        $this->assertSame([$visibleBoard->id], $boardIds);
        $this->assertNotContains($hiddenBoard->id, $boardIds);

        // No vault / bucket grant on this project → empty arrays.
        $this->assertSame([], $response->json('projects.0.vaults'));
        $this->assertSame([], $response->json('projects.0.buckets'));

        // Access map reflects the scope — no project-level role,
        // only the one board key. `json()` decodes empty JSON objects
        // as `[]` in PHP-land, which is fine here; the API payload
        // itself is still `{}` on the wire.
        $this->assertSame([], $response->json('access.project_role_by_project_id'));
        $this->assertSame(
            MemberRole::Editor->value,
            $response->json("access.resource_role_by_key.board:{$visibleBoard->id}"),
        );
    }

    public function test_workspaces_include_ones_where_user_is_only_a_member(): void
    {
        // User owns workspace A (via ProjectFactory) and is a member
        // of workspace B owned by someone else. Bootstrap must surface
        // both, in `is_personal`-desc then name order.
        $user = UserFactory::create();
        ProjectFactory::forOwner($user);

        $otherOwner = UserFactory::create();
        $otherProject = ProjectFactory::forOwner($otherOwner);
        $workspaceB = Organisation::query()->whereKey($otherProject->organisation_id)->firstOrFail();
        OrganisationMember::create([
            'organisation_id' => $workspaceB->id,
            'user_id' => $user->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $otherOwner->id,
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $workspaceIds = array_column($response->json('workspaces'), 'id');
        $this->assertContains($workspaceB->id, $workspaceIds);
    }

    public function test_empty_inbox_when_user_has_no_projects(): void
    {
        // Bare user — no ProjectFactory, no memberships.
        $user = UserFactory::create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $response->assertJsonPath('projects', [])
            ->assertJsonPath('access.project_role_by_project_id', [])
            ->assertJsonPath('access.resource_role_by_key', [])
            ->assertJsonPath('access.wrapped_key_by_resource_key', []);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/bootstrap')->assertUnauthorized();
    }
}

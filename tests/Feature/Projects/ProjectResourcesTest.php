<?php

namespace Tests\Feature\Projects;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ProjectResourcesTest extends TestCase
{
    public function test_owner_sees_all_three_resource_types_in_one_response(): void
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
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();

        $boardNames = array_column($response->json('boards'), 'name');
        $vaultNames = array_column($response->json('vaults'), 'name');
        $bucketNames = array_column($response->json('buckets'), 'name');

        // Default board "Tasks" + seeded "Sprint 12". Sorted by name.
        $this->assertContains('Sprint 12', $boardNames);
        $this->assertContains('Tasks', $boardNames);
        $this->assertContains('Production', $vaultNames);
        $this->assertContains('Default vault', $vaultNames);
        $this->assertContains('Infrastructure', $bucketNames);
        $this->assertContains('General', $bucketNames);
    }

    public function test_vault_payload_matches_existing_endpoint_shape_including_my_wrapped_key(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now()])->save();

        // Seed the current-version wrapped key for the owner so the
        // endpoint must hydrate `my_wrapped_key`.
        ResourceKey::create([
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'encrypted_key' => 'owner-wrapped',
            'key_version' => 1,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();

        $row = collect($response->json('vaults'))->firstWhere('id', $vault->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('my_wrapped_key', $row);
        $this->assertSame('owner-wrapped', $row['my_wrapped_key']['encrypted_key']);
        $this->assertSame(1, $row['my_wrapped_key']['key_version']);
        $this->assertNotNull($row['migrated_at']);
    }

    public function test_archived_vaults_and_buckets_are_excluded(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $liveVault = Vault::create(['project_id' => $project->id, 'name' => 'Live vault', 'created_by' => $owner->id]);
        $archivedVault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Archived vault',
            'created_by' => $owner->id,
            'is_archived' => true,
        ]);

        $liveBucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Live bucket',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);
        $archivedBucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Archived bucket',
            'currency' => 'USD',
            'created_by' => $owner->id,
            'is_archived' => true,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();

        $vaultIds = array_column($response->json('vaults'), 'id');
        $bucketIds = array_column($response->json('buckets'), 'id');

        $this->assertContains($liveVault->id, $vaultIds);
        $this->assertNotContains($archivedVault->id, $vaultIds);
        $this->assertContains($liveBucket->id, $bucketIds);
        $this->assertNotContains($archivedBucket->id, $bucketIds);
    }

    public function test_pattern_b_user_sees_only_their_granted_resources(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $visibleBoard = TaskBoard::create(['project_id' => $project->id, 'name' => 'Visible', 'created_by' => $owner->id]);
        TaskBoard::create(['project_id' => $project->id, 'name' => 'Hidden', 'created_by' => $owner->id]);

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
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();

        $boardIds = array_column($response->json('boards'), 'id');
        $this->assertSame([$visibleBoard->id], $boardIds);

        // No vault / bucket grants → empty arrays (not 403).
        $this->assertSame([], $response->json('vaults'));
        $this->assertSame([], $response->json('buckets'));
    }

    public function test_outsider_is_forbidden(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertForbidden();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/projects/1/resources')->assertUnauthorized();
    }
}

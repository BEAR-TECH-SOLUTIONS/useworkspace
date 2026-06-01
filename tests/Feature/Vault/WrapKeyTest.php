<?php

namespace Tests\Feature\Vault;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class WrapKeyTest extends TestCase
{
    public function test_happy_path_adds_wrapped_key_for_project_member(): void
    {
        $owner = UserFactory::create();
        $member = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->migratedVault($project, $owner);

        // Add member at project level.
        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/wrap-key", [
                'user_id' => $member->id,
                'encrypted_key' => base64_encode('wrapped-for-member'),
                'key_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('vault.id', $vault->id);

        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $member->id,
            'key_version' => 1,
            'encrypted_key' => base64_encode('wrapped-for-member'),
        ]);

        // Member can now see their wrapped key on the vault.
        $response = $this->actingAs($member)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk();

        $vaultData = collect($response->json('data'))->firstWhere('id', $vault->id);
        $this->assertNotNull($vaultData['my_wrapped_key']);
        $this->assertSame(base64_encode('wrapped-for-member'), $vaultData['my_wrapped_key']['encrypted_key']);
    }

    public function test_idempotent_no_duplicate_row(): void
    {
        $owner = UserFactory::create();
        $member = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->migratedVault($project, $owner);

        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $payload = [
            'user_id' => $member->id,
            'encrypted_key' => base64_encode('wrapped'),
            'key_version' => 1,
        ];

        $this->actingAs($owner)->postJson("/api/v1/vaults/{$vault->id}/wrap-key", $payload)->assertOk();
        $this->actingAs($owner)->postJson("/api/v1/vaults/{$vault->id}/wrap-key", $payload)->assertOk();

        $count = ResourceKey::query()
            ->where('resource_type', ResourceType::Vault->value)
            ->where('resource_id', $vault->id)
            ->where('user_id', $member->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_version_mismatch_returns_409(): void
    {
        $owner = UserFactory::create();
        $member = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->migratedVault($project, $owner);

        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // Simulate rotation to v2.
        ResourceKey::create([
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'encrypted_key' => 'rotated',
            'key_version' => 2,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/wrap-key", [
                'user_id' => $member->id,
                'encrypted_key' => base64_encode('stale'),
                'key_version' => 1,
            ])
            ->assertStatus(409);
    }

    public function test_no_project_access_returns_422(): void
    {
        $owner = UserFactory::create();
        $outsider = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->migratedVault($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/wrap-key", [
                'user_id' => $outsider->id,
                'encrypted_key' => base64_encode('wrapped'),
                'key_version' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['User does not have cascading access to this vault.']);
    }

    public function test_pattern_b_user_returns_422(): void
    {
        $owner = UserFactory::create();
        $patternB = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->migratedVault($project, $owner);

        // Direct vault grant only (Pattern B).
        ResourcePermission::create([
            'user_id' => $patternB->id,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/wrap-key", [
                'user_id' => $patternB->id,
                'encrypted_key' => base64_encode('wrapped'),
                'key_version' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'User has a direct vault grant (Pattern B) — they should already have a wrapped key from the grant.');
    }

    private function migratedVault($project, $owner): Vault
    {
        $vault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Migrated',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        ResourceKey::create([
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'encrypted_key' => 'owner-key',
            'key_version' => 1,
        ]);

        $vault->forceFill(['migrated_at' => now()])->save();

        return $vault;
    }
}

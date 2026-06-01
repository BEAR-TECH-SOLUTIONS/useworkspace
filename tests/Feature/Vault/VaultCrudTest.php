<?php

namespace Tests\Feature\Vault;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class VaultCrudTest extends TestCase
{
    public function test_owner_can_create_vault(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/vaults", [
                'name' => 'Production secrets',
                'color' => '#112233',
            ]);

        $response->assertCreated()
            ->assertJsonPath('vault.name', 'Production secrets')
            ->assertJsonPath('vault.is_default', false);
    }

    public function test_default_vault_cannot_be_deleted(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $default = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/vaults/{$default->id}")
            ->assertStatus(422);
    }

    public function test_owner_can_delete_non_default_vault(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Disposable',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/vaults/{$vault->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    }

    public function test_outsider_cannot_view_vault(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/vaults/{$vault->id}")
            ->assertForbidden();
    }

    public function test_scoped_vault_grant_cannot_see_other_vaults(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $vaultA = Vault::create(['project_id' => $project->id, 'name' => 'A', 'created_by' => $owner->id]);
        $vaultB = Vault::create(['project_id' => $project->id, 'name' => 'B', 'created_by' => $owner->id]);

        // Project-level viewer + specific editor grant only on vaultA.
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vaultA->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // Can mutate vaultA (specific editor grant).
        $this->actingAs($scoped)
            ->patchJson("/api/v1/vaults/{$vaultA->id}", ['name' => 'A (edited)'])
            ->assertOk();

        // Cannot mutate vaultB (only project-level viewer cascades, and viewer < editor).
        $this->actingAs($scoped)
            ->patchJson("/api/v1/vaults/{$vaultB->id}", ['name' => 'B (hijacked)'])
            ->assertForbidden();
    }
}

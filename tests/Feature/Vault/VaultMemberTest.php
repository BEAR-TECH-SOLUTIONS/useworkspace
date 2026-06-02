<?php

namespace Tests\Feature\Vault;

use App\Enums\AuditAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\AuditLog;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Covers the per-vault member endpoints (Pattern B). The vault plane
 * carries a crypto plane, so store() must accept an encrypted_key that
 * the server persists verbatim into resource_keys at the vault's
 * current key_version.
 */
class VaultMemberTest extends TestCase
{
    public function test_owner_can_list_vault_members(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);
        $invitee = UserFactory::create();

        $this->grantVaultMember($owner, $vault, $invitee, MemberRole::Editor);

        $this->actingAs($owner)
            ->getJson("/api/v1/vaults/{$vault->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.resource_type', 'vault')
            ->assertJsonPath('data.0.resource_id', $vault->id)
            ->assertJsonPath('data.0.user.email', $invitee->email)
            ->assertJsonPath('data.0.role', 'editor');
    }

    public function test_owner_can_grant_vault_membership_with_wrapped_key(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $invitee->email,
                'role' => 'editor',
                'encrypted_key' => base64_encode('wrapped-for-invitee'),
            ])
            ->assertCreated()
            ->assertJsonPath('member.user.email', $invitee->email)
            ->assertJsonPath('member.role', 'editor');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'role' => MemberRole::Editor->value,
        ]);

        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $invitee->id,
            'encrypted_key' => base64_encode('wrapped-for-invitee'),
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => AuditAction::ResourceGranted->value,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'target_user_id' => $invitee->id,
        ]);
    }

    public function test_vault_member_store_requires_encrypted_key(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $invitee->email,
                'role' => 'editor',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encrypted_key']);
    }

    public function test_non_owner_cannot_manage_vault_members(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);

        // Editor has project-level editor — cannot share.
        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $target->email,
                'role' => 'viewer',
                'encrypted_key' => base64_encode('wrapped'),
            ])
            ->assertForbidden();
    }

    public function test_owner_can_update_and_revoke_member(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);

        $this->grantVaultMember($owner, $vault, $invitee, MemberRole::Viewer);

        $this->actingAs($owner)
            ->patchJson("/api/v1/vaults/{$vault->id}/members/{$invitee->id}", [
                'role' => 'editor',
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'editor');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/vaults/{$vault->id}/members/{$invitee->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
        ]);

        // Revoke must also drop any wrapped-key rows for this user on the vault.
        $this->assertSame(0, ResourceKey::query()
            ->where('user_id', $invitee->id)
            ->where('resource_type', ResourceType::Vault->value)
            ->where('resource_id', $vault->id)
            ->count());
    }

    public function test_pattern_b_vault_user_can_list_vaults_in_project(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $vaultA = Vault::create(['project_id' => $project->id, 'name' => 'A', 'created_by' => $owner->id]);
        Vault::create(['project_id' => $project->id, 'name' => 'B', 'created_by' => $owner->id]);

        // Direct grant on vaultA only — no project-level row.
        $this->grantVaultMember($owner, $vaultA, $scoped, MemberRole::Editor);

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$vaultA->id], $ids);
    }

    public function test_project_members_endpoint_excludes_pattern_b_users(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $this->nonDefaultVault($project, $owner);

        $this->grantVaultMember($owner, $vault, $scoped, MemberRole::Editor);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/members")
            ->assertOk();

        $userIds = array_column($response->json('data'), 'user_id');
        $this->assertNotContains($scoped->id, $userIds);
    }

    private function nonDefaultVault($project, $owner): Vault
    {
        return Vault::create([
            'project_id' => $project->id,
            'name' => 'Shared',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }

    private function grantVaultMember($owner, Vault $vault, $target, MemberRole $role): void
    {
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $target->email,
                'role' => $role->value,
                'encrypted_key' => base64_encode('wrapped-for-'.$target->id),
            ])
            ->assertCreated();
    }
}
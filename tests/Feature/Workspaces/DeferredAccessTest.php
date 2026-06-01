<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Notification;
use App\Models\Permissions\DeferredAccessGrant;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class DeferredAccessTest extends TestCase
{
    public function test_list_groups_by_user_and_surfaces_ready_users_first(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id, 'name' => 'Backend'])->save();
        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now(), 'name' => 'Production'])->save();

        $ready = UserFactory::create(['name' => 'Ready']);
        $pending = UserFactory::create(['name' => 'Pending']);
        $pending->forceFill([
            'master_password_salt' => null,
            'master_password_verifier' => null,
            'public_key' => null,
            'encrypted_private_key' => null,
            'private_key_iv' => null,
        ])->save();

        DeferredAccessGrant::create([
            'user_id' => $ready->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $project->id,
            'mode' => 'project',
            'project_role' => MemberRole::Editor->value,
            'resources' => [['vault_id' => $vault->id]],
        ]);

        DeferredAccessGrant::create([
            'user_id' => $pending->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $project->id,
            'mode' => 'project',
            'project_role' => MemberRole::Viewer->value,
            'resources' => [['vault_id' => $vault->id]],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/deferred-access")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $this->assertSame($ready->id, $data[0]['user_id']);
        $this->assertTrue($data[0]['user']['master_password_set']);
        $this->assertSame('Backend', $data[0]['grants'][0]['project_name']);
        $this->assertSame($vault->id, $data[0]['grants'][0]['vaults'][0]['vault_id']);
        $this->assertSame('Production', $data[0]['grants'][0]['vaults'][0]['vault_name']);
        // `mode` / `project_role` are now implementation detail and
        // must not appear on the response — clients only care about
        // which vaults need wrapping.
        $this->assertArrayNotHasKey('mode', $data[0]['grants'][0]);
        $this->assertArrayNotHasKey('project_role', $data[0]['grants'][0]);

        $this->assertSame($pending->id, $data[1]['user_id']);
        $this->assertFalse($data[1]['user']['master_password_set']);
    }

    public function test_list_tolerates_legacy_non_vault_resource_entries(): void
    {
        // Reproduces a prod log entry: rows written under the old
        // pre-split schema carried board/bucket entries in `resources`
        // (no `vault_id` key). Under the new shape those entries are
        // impossible to write — but existing rows still live in DBs
        // that were populated before the refactor. The list endpoint
        // must skip them rather than 500 the whole response.
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id, 'name' => 'Backend'])->save();
        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now(), 'name' => 'Production'])->save();

        $user = UserFactory::create();

        DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $project->id,
            'mode' => 'resources',
            'project_role' => null,
            // Mixed: one legacy board entry (no vault_id) + one vault.
            'resources' => [
                ['type' => 'board', 'id' => 999, 'role' => 'editor'],
                ['vault_id' => $vault->id, 'role' => 'viewer'],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/deferred-access")
            ->assertOk();

        $grant = $response->json('data.0.grants.0');
        $this->assertCount(1, $grant['vaults']);
        $this->assertSame($vault->id, $grant['vaults'][0]['vault_id']);
    }

    public function test_list_requires_workspace_admin(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/workspaces/{$workspace->id}/deferred-access")
            ->assertForbidden();
    }

    public function test_finalize_writes_resource_keys_and_deletes_row_for_project_mode(): void
    {
        [$admin, $workspace, $project, $deferred, $user, $vault] = $this->seedProjectDeferred();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/deferred-access/{$deferred->id}/finalize", [
                'vault_keys' => $this->vaultKeysFor($project),
            ])
            ->assertOk();

        $response->assertJsonPath('applied.project_id', $project->id)
            ->assertJsonPath('applied.vaults_applied', 1);

        // Project-level permission was applied at provisioning time —
        // not touched by finalise. finalise writes the resource_keys row
        // for the user.
        $this->assertTrue(
            ResourceKey::query()
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->where('user_id', $user->id)
                ->exists(),
        );

        // mode=project doesn't create a per-vault resource_permissions
        // row — access cascades from the project row.
        $this->assertFalse(
            ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->exists(),
        );

        $this->assertNull(DeferredAccessGrant::find($deferred->id));
    }

    public function test_finalize_writes_vault_permission_and_key_for_resources_mode(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now()])->save();
        $this->seedAdminVaultKey($vault, $admin, $project);

        $user = $this->readyUser();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        $deferred = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $project->id,
            'mode' => 'resources',
            'project_role' => null,
            'resources' => [['vault_id' => $vault->id, 'role' => 'viewer']],
        ]);

        $this->actingAs($admin)
            ->postJson("/api/v1/deferred-access/{$deferred->id}/finalize", [
                'vault_keys' => $this->vaultKeysFor($project),
            ])
            ->assertOk()
            ->assertJsonPath('applied.vaults_applied', 1);

        // Resources mode: vault permission row AND key row both written.
        $this->assertTrue(
            ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->where('role', 'viewer')
                ->exists(),
        );
        $this->assertTrue(
            ResourceKey::query()
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->where('user_id', $user->id)
                ->exists(),
        );
    }

    public function test_finalize_rejects_when_target_user_has_no_master_password(): void
    {
        [$admin, $workspace, $project, $deferred, $user] = $this->seedProjectDeferred();

        $user->forceFill([
            'master_password_salt' => null,
            'master_password_verifier' => null,
            'public_key' => null,
            'encrypted_private_key' => null,
            'private_key_iv' => null,
        ])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/deferred-access/{$deferred->id}/finalize", [
                'vault_keys' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'user_not_ready');

        $this->assertNotNull(DeferredAccessGrant::find($deferred->id));
    }

    public function test_finalize_rejects_when_vault_keys_missing(): void
    {
        [$admin, $workspace, $project, $deferred] = $this->seedProjectDeferred();

        $this->actingAs($admin)
            ->postJson("/api/v1/deferred-access/{$deferred->id}/finalize", [
                'vault_keys' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'missing_vault_keys');

        $this->assertNotNull(DeferredAccessGrant::find($deferred->id));
    }

    public function test_batch_finalize_partial_success(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $projectA = ProjectFactory::forOwner($admin);
        $projectA->forceFill(['organisation_id' => $workspace->id])->save();
        $this->migrateDefaultVault($projectA, $admin);
        $projectB = ProjectFactory::forOwner($admin);
        $projectB->forceFill(['organisation_id' => $workspace->id])->save();
        $this->migrateDefaultVault($projectB, $admin);

        $user = $this->readyUser();

        $deferredA = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $projectA->id,
            'mode' => 'project',
            'project_role' => MemberRole::Editor->value,
            'resources' => [['vault_id' => $projectA->vaults()->first()->id]],
        ]);
        $deferredB = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $projectB->id,
            'mode' => 'project',
            'project_role' => MemberRole::Viewer->value,
            'resources' => [['vault_id' => $projectB->vaults()->first()->id]],
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/deferred-access/finalize-batch", [
                'grants' => [
                    [
                        'deferred_access_id' => $deferredA->id,
                        'vault_keys' => $this->vaultKeysFor($projectA),
                    ],
                    [
                        'deferred_access_id' => $deferredB->id,
                        'vault_keys' => [],
                    ],
                ],
            ])
            ->assertOk();

        $applied = $response->json('applied');
        $errors = $response->json('errors');

        $this->assertCount(1, $applied);
        $this->assertSame($deferredA->id, $applied[0]['deferred_access_id']);
        $this->assertSame('ok', $applied[0]['status']);
        $this->assertSame(1, $applied[0]['vaults_applied']);

        $this->assertCount(1, $errors);
        $this->assertSame($deferredB->id, $errors[0]['deferred_access_id']);
        $this->assertSame('missing_vault_keys', $errors[0]['code']);

        $this->assertNull(DeferredAccessGrant::find($deferredA->id));
        $this->assertNotNull(DeferredAccessGrant::find($deferredB->id));
    }

    public function test_master_password_setup_notifies_workspace_admins_once(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $project->vaults()->first()->forceFill(['migrated_at' => now()])->save();

        $secondAdmin = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $secondAdmin->id,
            'role' => OrganisationRole::Admin->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        // Provision with a project that has a migrated vault — so a
        // deferred row gets stashed and the master-password hook has
        // something to notify about.
        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice',
                'password' => 'alice-pass-123',
                'projects' => [
                    ['project_id' => $project->id, 'mode' => 'project', 'project_role' => 'editor'],
                ],
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'alice@company.com')->firstOrFail();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', [
                'master_password_salt' => base64_encode(random_bytes(16)),
                'master_password_verifier' => base64_encode(random_bytes(32)),
                'public_key' => 'pub-'.bin2hex(random_bytes(8)),
                'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
                'private_key_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertOk();

        $adminNotification = Notification::query()
            ->where('user_id', $admin->id)
            ->where('type', NotificationType::UserReadyForAccess->value)
            ->firstOrFail();
        $this->assertSame(1, (int) $adminNotification->metadata['pending_count']);
        $this->assertSame($user->id, (int) $adminNotification->metadata['user_id']);

        $this->assertTrue(
            Notification::query()
                ->where('user_id', $secondAdmin->id)
                ->where('type', NotificationType::UserReadyForAccess->value)
                ->exists(),
        );
    }

    public function test_workspace_member_removal_clears_pending_deferred_rows(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $projectA = ProjectFactory::forOwner($admin);
        $projectA->forceFill(['organisation_id' => $workspace->id])->save();
        $projectB = ProjectFactory::forOwner($admin);
        $projectB->forceFill(['organisation_id' => $workspace->id])->save();

        $user = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        // Two pending deferred rows across two projects in this workspace.
        $rowA = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $projectA->id,
            'mode' => 'project',
            'project_role' => MemberRole::Editor->value,
            'resources' => [['vault_id' => $projectA->vaults()->first()->id]],
        ]);
        $rowB = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $projectB->id,
            'mode' => 'resources',
            'project_role' => null,
            'resources' => [['vault_id' => $projectB->vaults()->first()->id, 'role' => 'viewer']],
        ]);

        // Control row: a deferred grant for the same user in a DIFFERENT
        // workspace must survive — removal only touches this workspace.
        $otherAdmin = UserFactory::create();
        $otherProject = ProjectFactory::forOwner($otherAdmin);
        $otherWorkspace = Organisation::query()->whereKey($otherProject->organisation_id)->firstOrFail();
        $otherRow = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $otherWorkspace->id,
            'provisioned_by' => $otherAdmin->id,
            'project_id' => $otherProject->id,
            'mode' => 'project',
            'project_role' => MemberRole::Editor->value,
            'resources' => [['vault_id' => $otherProject->vaults()->first()->id]],
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/workspaces/{$workspace->id}/members/{$user->id}")
            ->assertNoContent();

        $this->assertNull(DeferredAccessGrant::find($rowA->id));
        $this->assertNull(DeferredAccessGrant::find($rowB->id));
        $this->assertNotNull(DeferredAccessGrant::find($otherRow->id));
    }

    public function test_set_member_access_clears_matching_deferred_row(): void
    {
        [$admin, $workspace, $project, $deferred, $user] = $this->seedProjectDeferred();

        $user->forceFill([
            'master_password_salt' => base64_encode(random_bytes(16)),
            'master_password_verifier' => base64_encode(random_bytes(32)),
            'public_key' => 'pub',
            'encrypted_private_key' => 'enc',
            'private_key_iv' => base64_encode(random_bytes(12)),
        ])->save();

        $this->actingAs($admin)
            ->putJson("/api/v1/projects/{$project->id}/members/{$user->id}/access", [
                'mode' => 'none',
            ])
            ->assertOk();

        $this->assertNull(DeferredAccessGrant::find($deferred->id));
    }

    /**
     * @return array{0: User, 1: Organisation}
     */
    private function businessWorkspace(): array
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        // Helper kept the legacy "business" name; the Team plan is
        // the canonical equivalent that supports direct provisioning
        // for these deferred-access scenarios.
        $workspace->forceFill([
            'tier' => PlanTier::Team->value,
            'seat_cap' => PlanTier::Team->defaultSeatCap(),
        ])->save();

        return [$admin, $workspace];
    }

    /**
     * @return array{0: User, 1: Organisation, 2: \App\Models\Project\Project, 3: DeferredAccessGrant, 4: User, 5: Vault}
     */
    private function seedProjectDeferred(): array
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $vault = $project->vaults()->first();
        $vault->forceFill(['migrated_at' => now()])->save();
        $this->seedAdminVaultKey($vault, $admin, $project);

        $user = $this->readyUser();

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        // Project row applied immediately (new split provisioning
        // model). Deferred row only carries the vault list.
        ResourcePermission::create([
            'user_id' => $user->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $admin->id,
        ]);

        $deferred = DeferredAccessGrant::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provisioned_by' => $admin->id,
            'project_id' => $project->id,
            'mode' => 'project',
            'project_role' => MemberRole::Editor->value,
            'resources' => [['vault_id' => $vault->id]],
        ]);

        return [$admin, $workspace, $project, $deferred, $user, $vault];
    }

    /**
     * @return array<int, array{vault_id:int, encrypted_key:string, key_version:int}>
     */
    private function vaultKeysFor($project): array
    {
        return $project->vaults()->whereNotNull('migrated_at')->get()->map(fn ($v) => [
            'vault_id' => $v->id,
            'encrypted_key' => 'wrapped-'.bin2hex(random_bytes(8)),
            'key_version' => 1,
        ])->all();
    }

    private function migrateDefaultVault($project, User $admin): void
    {
        $vault = $project->vaults()->firstOrFail();
        $vault->forceFill(['migrated_at' => now()])->save();
        $this->seedAdminVaultKey($vault, $admin, $project);
    }

    private function seedAdminVaultKey(Vault $vault, User $admin, $project): void
    {
        ResourceKey::updateOrCreate(
            [
                'resource_type' => ResourceType::Vault->value,
                'resource_id' => $vault->id,
                'user_id' => $admin->id,
                'key_version' => 1,
            ],
            [
                'project_id' => $project->id,
                'encrypted_key' => 'admin-wrapped-'.bin2hex(random_bytes(8)),
            ],
        );
    }

    /**
     * User who has completed the master-password handshake so
     * `hasMasterPassword()` returns true in the finalise path.
     */
    private function readyUser(): User
    {
        return UserFactory::create();
    }
}

<?php

namespace Tests\Feature\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class WorkspaceProvisioningTest extends TestCase
{
    public function test_admin_on_business_tier_can_provision_a_user_without_projects(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice Smith',
                'password' => 'initial-password-123',
                'role' => OrganisationRole::Member->value,
            ])
            ->assertCreated();

        $response->assertJsonPath('user.email', 'alice@company.com')
            ->assertJsonPath('user.name', 'Alice Smith')
            ->assertJsonPath('user.master_password_set', false)
            ->assertJsonPath('membership.workspace_id', $workspace->id)
            ->assertJsonPath('membership.role', 'member')
            ->assertJsonPath('projects_added', []);

        $user = User::query()->where('email', 'alice@company.com')->firstOrFail();
        $this->assertTrue(Hash::check('initial-password-123', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->public_key);

        $this->assertTrue(
            OrganisationMember::query()
                ->where('organisation_id', $workspace->id)
                ->where('user_id', $user->id)
                ->where('role', OrganisationRole::Member->value)
                ->exists(),
        );
    }

    public function test_self_hosted_tier_also_qualifies(): void
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        $workspace->forceFill(['tier' => PlanTier::SelfHosted->value])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'ops@company.com',
                'name' => 'Ops',
                'password' => 'ops-password-123',
            ])
            ->assertCreated();
    }

    public function test_free_tier_returns_403_feature_not_available(): void
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        $workspace->forceFill(['tier' => PlanTier::Free->value, 'seat_cap' => 1])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'nope@company.com',
                'name' => 'No',
                'password' => 'password-123',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'feature_not_available');

        $this->assertFalse(User::query()->where('email', 'nope@company.com')->exists());
    }

    public function test_non_admin_member_is_forbidden(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $regular = UserFactory::create();

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $regular->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $admin->id,
            'joined_at' => now(),
        ]);

        $this->actingAs($regular)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'nope@company.com',
                'name' => 'No',
                'password' => 'password-123',
            ])
            ->assertForbidden();
    }

    public function test_email_taken_returns_422_with_code(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();

        $existing = UserFactory::create(['email' => 'dup@company.com']);

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'dup@company.com',
                'name' => 'Dup',
                'password' => 'password-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'email_taken');
    }

    public function test_seat_cap_exceeded_returns_422_with_code(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $workspace->forceFill(['seat_cap' => 1])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice',
                'password' => 'password-123',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'seat_cap_exceeded');

        $this->assertFalse(User::query()->where('email', 'alice@company.com')->exists());
    }

    public function test_validation_rejects_short_password(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice',
                'password' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_project_mode_applies_project_permission_immediately_defers_vault_keys(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $this->migrateDefaultVault($project);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice',
                'password' => 'password-123',
                'projects' => [
                    [
                        'project_id' => $project->id,
                        'mode' => 'project',
                        'project_role' => MemberRole::Editor->value,
                    ],
                ],
            ])
            ->assertCreated();

        // Project role applied right away; vault keys deferred.
        $response->assertJsonPath('projects_added.0.mode', 'project')
            ->assertJsonPath('projects_added.0.project_role', 'editor')
            ->assertJsonPath('projects_added.0.project_id', $project->id)
            ->assertJsonPath('deferred_vault_grants.0.project_id', $project->id)
            ->assertJsonPath('deferred_vault_grants.0.vault_count', 1);

        $user = User::query()->where('email', 'alice@company.com')->firstOrFail();

        // Project-level permission exists now — user sees the project.
        $this->assertTrue(
            ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', ResourceType::Project->value)
                ->where('resource_id', $project->id)
                ->where('role', MemberRole::Editor->value)
                ->exists(),
        );

        $deferred = \App\Models\Permissions\DeferredAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->firstOrFail();
        $this->assertSame('project', $deferred->mode);
        $this->assertCount(1, $deferred->resources);
    }

    public function test_resources_mode_applies_board_bucket_immediately_defers_vault(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $this->migrateDefaultVault($project);

        $board = $project->boards()->first();
        $vault = $project->vaults()->first();

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'bob@company.com',
                'name' => 'Bob',
                'password' => 'password-123',
                'projects' => [
                    [
                        'project_id' => $project->id,
                        'mode' => 'resources',
                        'resources' => [
                            ['type' => 'board', 'id' => $board->id, 'role' => MemberRole::Editor->value],
                            ['type' => 'vault', 'id' => $vault->id, 'role' => MemberRole::Viewer->value],
                        ],
                    ],
                ],
            ])
            ->assertCreated();

        $response->assertJsonPath('projects_added.0.mode', 'resources')
            ->assertJsonPath('projects_added.0.resources_count', 2)
            ->assertJsonPath('deferred_vault_grants.0.vault_count', 1);

        $user = User::query()->where('email', 'bob@company.com')->firstOrFail();

        // Board applied immediately.
        $this->assertTrue(
            ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', ResourceType::Board->value)
                ->where('resource_id', $board->id)
                ->exists(),
        );

        // Vault deferred — no resource_permissions row yet.
        $this->assertFalse(
            ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->exists(),
        );

        $deferred = \App\Models\Permissions\DeferredAccessGrant::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->firstOrFail();
        $this->assertSame('resources', $deferred->mode);
        $this->assertCount(1, $deferred->resources);
        $this->assertSame($vault->id, (int) $deferred->resources[0]['vault_id']);
        $this->assertSame('viewer', $deferred->resources[0]['role']);
    }

    public function test_resources_mode_without_vaults_creates_no_deferred_row(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();
        $board = $project->boards()->first();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'carol@company.com',
                'name' => 'Carol',
                'password' => 'password-123',
                'projects' => [
                    [
                        'project_id' => $project->id,
                        'mode' => 'resources',
                        'resources' => [
                            ['type' => 'board', 'id' => $board->id, 'role' => MemberRole::Editor->value],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('deferred_vault_grants', []);

        $user = User::query()->where('email', 'carol@company.com')->firstOrFail();
        $this->assertFalse(
            \App\Models\Permissions\DeferredAccessGrant::query()
                ->where('user_id', $user->id)
                ->exists(),
        );
    }

    public function test_provisioned_user_gets_personal_workspace_by_default(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'alice@company.com',
                'name' => 'Alice Smith',
                'password' => 'password-123',
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'alice@company.com')->firstOrFail();

        // Personal workspace bootstrap — mirrors POST /register.
        $this->assertTrue(
            Organisation::query()
                ->where('owner_id', $user->id)
                ->where('is_personal', true)
                ->exists(),
        );
        $personalProject = \App\Models\Project\Project::query()
            ->where('owner_id', $user->id)
            ->where('is_personal', true)
            ->firstOrFail();
        $this->assertSame('Personal', $personalProject->name);
        // Default board/vault/bucket were bootstrapped.
        $this->assertTrue($personalProject->boards()->exists());
        $this->assertTrue($personalProject->vaults()->exists());
        $this->assertTrue($personalProject->expenseBuckets()->exists());
    }

    public function test_create_personal_workspace_false_skips_bootstrap(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'dave@company.com',
                'name' => 'Dave',
                'password' => 'password-123',
                'create_personal_workspace' => false,
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'dave@company.com')->firstOrFail();
        $this->assertFalse(
            Organisation::query()
                ->where('owner_id', $user->id)
                ->where('is_personal', true)
                ->exists(),
        );
    }

    public function test_failed_project_validation_rolls_back_user_creation(): void
    {
        [$admin, $workspace] = $this->businessWorkspace();
        $project = ProjectFactory::forOwner($admin);
        $project->forceFill(['organisation_id' => $workspace->id])->save();

        // Non-existent board id — triggers invalid_resource from the shared
        // validator. User row must not be left behind.
        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'rollback@company.com',
                'name' => 'Rollback',
                'password' => 'password-123',
                'projects' => [
                    [
                        'project_id' => $project->id,
                        'mode' => 'resources',
                        'resources' => [
                            ['type' => 'board', 'id' => 999999, 'role' => 'editor'],
                        ],
                    ],
                ],
            ])
            ->assertStatus(422);

        $this->assertFalse(User::query()->where('email', 'rollback@company.com')->exists());
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/workspaces/999/provision-user', [
            'email' => 'x@x.com',
            'name' => 'x',
            'password' => 'xxxxxxxx',
        ])->assertUnauthorized();
    }

    /**
     * @return array{0: User, 1: Organisation}
     */
    private function businessWorkspace(): array
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        // ProjectFactory leaves the workspace on the Team plan which
        // is the lowest paid tier that supports direct provisioning.
        // Set explicitly so a factory tweak doesn't silently drift
        // this test off the provisioning-eligible path.
        $workspace->forceFill([
            'tier' => PlanTier::Team->value,
            'seat_cap' => PlanTier::Team->defaultSeatCap(),
        ])->save();

        return [$admin, $workspace];
    }

    /**
     * Force-migrate the default vault so the provisioning flow picks
     * it up as "this vault needs a wrapped key deferred".
     */
    private function migrateDefaultVault($project): void
    {
        $vault = $project->vaults()->firstOrFail();
        $vault->forceFill(['migrated_at' => now()])->save();
    }
}

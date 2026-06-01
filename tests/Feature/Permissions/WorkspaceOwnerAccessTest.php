<?php

namespace Tests\Feature\Permissions;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\PlanTier;
use App\Enums\ResourceType;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Locks in the "workspace owner always has full access" invariant
 * (workspaces.owner_id is an implicit Owner on every project + child
 * resource, present and future, and the grant is immutable).
 *
 * Companion invariants tested:
 *   • Non-owner creates a project in a workspace they share with the
 *     owner → the owner is auto-granted Owner on the project (and
 *     therefore on every child resource via cascade).
 *   • migrate-key / rotate-key set-equality includes the workspace
 *     owner — without this the client would 422 on the first migrate
 *     call against a non-owner-created project.
 *   • The owner row cannot be removed or demoted from any project or
 *     child resource (workspace_owner_immutable).
 *   • Adding the owner as a direct member of any child resource is
 *     refused with the same code — they already hold the cascading
 *     project-level grant.
 */
class WorkspaceOwnerAccessTest extends TestCase
{
    public function test_non_owner_member_creating_project_grants_workspace_owner(): void
    {
        [$owner, $workspace, $member] = $this->workspaceWithMember();

        // The member creates a project in the workspace. Project
        // owner = creator (member). Workspace owner = $owner. The
        // bootstrapper must auto-grant $owner on the project.
        $project = $this->createProjectAs($member, $workspace);

        $row = ResourcePermission::query()
            ->where('user_id', $owner->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->first();

        $this->assertNotNull($row, 'Workspace owner should have a project-level resource_permissions row.');
        $this->assertSame(MemberRole::Owner->value, $row->role instanceof \BackedEnum ? $row->role->value : (string) $row->role);
    }

    public function test_migrate_key_set_equality_requires_workspace_owner_in_grants(): void
    {
        [$owner, $workspace, $member] = $this->workspaceWithMember();
        $project = $this->createProjectAs($member, $workspace);

        /** @var Vault $vault */
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();

        // Submitting only the creator (member) in the grants list
        // must 422 — the workspace owner is implicit Owner and the
        // set-equality check refuses without them.
        $this->actingAs($member)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $member->id, 'encrypted_key' => 'k-member'],
                ],
                'credentials' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.grants.0', 'Vault grants do not match the set of authorized members.');

        // Submitting BOTH grants succeeds and writes a wrapped key
        // for the workspace owner alongside the creator's.
        $this->actingAs($member)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $member->id, 'encrypted_key' => 'k-member'],
                    ['user_id' => $owner->id, 'encrypted_key' => 'k-owner'],
                ],
                'credentials' => [],
            ])
            ->assertOk()
            ->assertJsonPath('key_version', 1);

        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $owner->id,
            'key_version' => 1,
            'encrypted_key' => 'k-owner',
        ]);
    }

    public function test_workspace_owner_cannot_be_demoted_from_project(): void
    {
        [$owner, $workspace, $member] = $this->workspaceWithMember();
        $project = $this->createProjectAs($member, $workspace);

        // Member tries to change the workspace owner's role to Editor
        // via PATCH /projects/{p}/members/{user} — must 403.
        $this->actingAs($member)
            ->patchJson("/api/v1/projects/{$project->id}/members/{$owner->id}", [
                'role' => MemberRole::Editor->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'workspace_owner_immutable');

        // And the row is unchanged.
        $row = ResourcePermission::query()
            ->where('user_id', $owner->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->firstOrFail();
        $this->assertSame(MemberRole::Owner->value, $row->role instanceof \BackedEnum ? $row->role->value : (string) $row->role);
    }

    public function test_workspace_owner_cannot_be_removed_from_project(): void
    {
        [$owner, $workspace, $member] = $this->workspaceWithMember();
        $project = $this->createProjectAs($member, $workspace);

        $this->actingAs($member)
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$owner->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'workspace_owner_immutable');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $owner->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
        ]);
    }

    public function test_adding_workspace_owner_as_direct_board_member_is_refused(): void
    {
        [$owner, $workspace, $member] = $this->workspaceWithMember();
        $project = $this->createProjectAs($member, $workspace);
        $board = $project->boards()->where('is_default', true)->firstOrFail();

        $this->actingAs($member)
            ->postJson("/api/v1/task-boards/{$board->id}/members", [
                'email' => $owner->email,
                'role' => MemberRole::Editor->value,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'workspace_owner_immutable');
    }

    public function test_personal_workspace_unaffected(): void
    {
        // Personal workspace — creator IS the workspace owner. The
        // workspace-owner grant collapses to the existing
        // project-creator grant; no duplicate row, no double-grant
        // 422 on conflict (updateOrCreate semantics).
        $user = UserFactory::create();
        $project = ProjectFactory::forOwner($user);

        $rows = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->count();

        $this->assertSame(1, $rows, 'Exactly one project-level row when project creator IS the workspace owner.');
    }

    /**
     * @return array{0: User, 1: Organisation, 2: User}
     */
    private function workspaceWithMember(): array
    {
        $owner = UserFactory::create();
        // Use a non-personal workspace so we exercise the
        // owner != creator path. ProjectFactory builds personal-ish
        // setups; spin one up directly.
        $workspace = Organisation::create([
            'owner_id' => $owner->id,
            'name' => 'Test Workspace',
            'slug' => 'ws-'.bin2hex(random_bytes(4)),
            'is_personal' => false,
            'tier' => PlanTier::Team->value,
            'seat_cap' => PlanTier::Team->defaultSeatCap(),
        ]);
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => OrganisationRole::Admin->value,
            'invited_by' => null,
        ]);

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
            'invited_by' => $owner->id,
        ]);

        return [$owner, $workspace, $member];
    }

    private function createProjectAs(User $creator, Organisation $workspace): Project
    {
        $response = $this->actingAs($creator)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Project '.bin2hex(random_bytes(3)),
            ])
            ->assertStatus(201);

        return Project::query()->findOrFail((int) $response->json('project.id'));
    }
}

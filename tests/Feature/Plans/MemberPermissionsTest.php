<?php

namespace Tests\Feature\Plans;

use App\Enums\OrganisationRole;
use App\Models\Identity\OrganisationMember;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class MemberPermissionsTest extends TestCase
{
    public function test_member_can_create_project_by_default(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
        ]);

        $this->actingAs($member)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Member-created project',
            ])
            ->assertCreated();
    }

    public function test_member_cannot_create_project_when_toggle_off(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;
        $workspace->update(['members_can_create_projects' => false]);

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
        ]);

        $this->actingAs($member)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Should be denied',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organisation_id']);
    }

    public function test_admin_can_always_create_project_regardless_of_toggle(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;
        $workspace->update(['members_can_create_projects' => false]);

        // Owner is implicitly admin.
        $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Admin override',
            ])
            ->assertCreated();
    }

    public function test_member_can_invite_by_default(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
        ]);

        $this->actingAs($member)
            ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
                'email' => 'invitee@example.com',
                'role' => 'member',
            ])
            ->assertCreated();
    }

    public function test_member_cannot_invite_when_toggle_off(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;
        $workspace->update(['members_can_invite_members' => false]);

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
        ]);

        $this->actingAs($member)
            ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
                'email' => 'invitee@example.com',
                'role' => 'member',
            ])
            ->assertForbidden();
    }

    public function test_patch_workspace_toggles_member_permissions(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;

        $this->actingAs($owner)
            ->patchJson("/api/v1/workspaces/{$workspace->id}", [
                'members_can_create_projects' => false,
                'members_can_invite_members' => false,
            ])
            ->assertOk()
            ->assertJsonPath('workspace.members_can_create_projects', false)
            ->assertJsonPath('workspace.members_can_invite_members', false);
    }

    public function test_member_cannot_toggle_permissions(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = $project->organisation;

        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => OrganisationRole::Member->value,
        ]);

        $this->actingAs($member)
            ->patchJson("/api/v1/workspaces/{$workspace->id}", [
                'members_can_create_projects' => false,
            ])
            ->assertForbidden();
    }
}

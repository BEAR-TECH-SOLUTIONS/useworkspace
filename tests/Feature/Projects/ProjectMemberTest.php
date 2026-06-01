<?php

namespace Tests\Feature\Projects;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ProjectMemberTest extends TestCase
{
    public function test_owner_can_invite_member(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = $this->ownedProject($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'email' => $invitee->email,
                'role' => 'editor',
            ]);

        $response->assertCreated()
            ->assertJsonPath('member.user.email', $invitee->email)
            ->assertJsonPath('member.role', 'editor');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'role' => MemberRole::Editor->value,
        ]);
    }

    public function test_non_owner_cannot_invite(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $newcomer = UserFactory::create();
        $project = $this->ownedProject($owner);

        // Make $editor an editor on the project.
        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'email' => $editor->email,
                'role' => 'editor',
            ])
            ->assertCreated();

        $this->actingAs($editor)
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'email' => $newcomer->email,
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_remove_member(): void
    {
        $owner = UserFactory::create();
        $member = UserFactory::create();
        $project = $this->ownedProject($owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'email' => $member->email,
                'role' => 'editor',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('resource_permissions', [
            'user_id' => $member->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_cannot_remove_owner(): void
    {
        $owner = UserFactory::create();
        $project = $this->ownedProject($owner);

        // Original-creator removal is 403 original_owner_immutable
        // per the project-settings spec (tightened from 422 when the
        // delete endpoint moved to original_owner_id gating).
        $this->actingAs($owner)
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$owner->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'original_owner_immutable');
    }

    public function test_public_key_lookup_returns_users_public_key(): void
    {
        $alice = UserFactory::create();
        $bob = UserFactory::create();

        $this->actingAs($alice)
            ->getJson('/api/v1/users/by-email/'.urlencode($bob->email).'/public-key')
            ->assertOk()
            ->assertJsonPath('user.email', $bob->email)
            ->assertJsonPath('user.public_key', $bob->public_key);
    }

    private function ownedProject(User $owner): Project
    {
        $org = Organisation::create([
            'owner_id' => $owner->id,
            'name' => 'Org '.bin2hex(random_bytes(3)),
            'slug' => 'org-'.Str::random(8),
        ]);

        OrganisationMember::create([
            'organisation_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $org->id,
                'name' => 'Project '.bin2hex(random_bytes(3)),
            ])
            ->assertCreated();

        return Project::findOrFail($response->json('project.id'));
    }
}

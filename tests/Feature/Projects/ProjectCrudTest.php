<?php

namespace Tests\Feature\Projects;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Identity\PersonalProjectFactory;
use Illuminate\Support\Str;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ProjectCrudTest extends TestCase
{
    public function test_owner_can_create_project_and_resource_permission_is_seeded(): void
    {
        $owner = UserFactory::create();
        $org = $this->makeOrganisation($owner);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $org->id,
                'name' => 'Acme Site',
                'color' => '#ff0099',
            ]);

        $response->assertCreated()
            ->assertJsonPath('project.name', 'Acme Site')
            ->assertJsonPath('project.color', '#ff0099');

        $projectId = $response->json('project.id');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $owner->id,
            'project_id' => $projectId,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $projectId,
            'role' => MemberRole::Owner->value,
        ]);

        // Default board, vault, expense bucket all seeded.
        $this->assertDatabaseHas('task_boards', ['project_id' => $projectId, 'is_default' => true]);
        $this->assertDatabaseHas('vaults', ['project_id' => $projectId, 'is_default' => true]);
        $this->assertDatabaseHas('expense_buckets', ['project_id' => $projectId, 'is_default' => true]);
    }

    public function test_outsider_cannot_view_project(): void
    {
        $owner = $this->bootstrapUser();
        $outsider = $this->bootstrapUser();
        $project = $this->ownedProject($owner);

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertForbidden();
    }

    public function test_owner_can_update_project(): void
    {
        $owner = $this->bootstrapUser();
        $project = $this->ownedProject($owner);

        $this->actingAs($owner)
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('project.name', 'Renamed');
    }

    public function test_viewer_cannot_update_project(): void
    {
        $owner = $this->bootstrapUser();
        $viewer = $this->bootstrapUser();
        $project = $this->ownedProject($owner);

        ResourcePermission::create([
            'user_id' => $viewer->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($viewer)
            ->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_personal_project_cannot_be_deleted(): void
    {
        $user = $this->bootstrapUser();
        $personal = $user->ownedProjects()->where('is_personal', true)->firstOrFail();

        $this->actingAs($user)
            ->deleteJson("/api/v1/projects/{$personal->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $personal->id]);
    }

    public function test_owner_can_delete_non_personal_project_after_2fa_verification(): void
    {
        $owner = $this->bootstrapUser();
        $project = $this->ownedProject($owner);

        // Project delete is gated by 2FA (CLAUDE.md §7). Walk the owner
        // through enrol → confirm so the verification cache flag is set,
        // then the delete should succeed.
        $enroll = $this->actingAs($owner)->postJson('/api/v1/auth/2fa/enroll')->json();
        $totp = app(TotpService::class);
        $reflect = new \ReflectionClass($totp);
        $method = $reflect->getMethod('generateCode');
        $method->setAccessible(true);
        $code = $method->invoke($totp, $enroll['secret'], intdiv(time(), TotpService::PERIOD));

        $this->actingAs($owner)
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => $code])
            ->assertOk();

        $this->actingAs($owner->refresh())
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }

    public function test_project_delete_is_blocked_without_2fa(): void
    {
        $owner = $this->bootstrapUser();
        $project = $this->ownedProject($owner);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_required');
    }

    public function test_index_returns_only_projects_user_can_access(): void
    {
        $alice = $this->bootstrapUser();
        $bob = $this->bootstrapUser();

        $shared = $this->ownedProject($alice);
        $alicePrivate = $this->ownedProject($alice);
        $bobPrivate = $this->ownedProject($bob);

        // Share `shared` with bob via an explicit grant.
        ResourcePermission::create([
            'user_id' => $bob->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $shared->id,
            'project_id' => $shared->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $alice->id,
        ]);

        $bobIds = collect($this->actingAs($bob)->getJson('/api/v1/projects')->json('data'))
            ->pluck('id')->all();

        $this->assertContains($shared->id, $bobIds);
        $this->assertContains($bobPrivate->id, $bobIds);
        $this->assertNotContains($alicePrivate->id, $bobIds);
    }

    private function bootstrapUser(): User
    {
        $user = UserFactory::create();
        app(PersonalProjectFactory::class)->bootstrap($user);

        return $user;
    }

    private function makeOrganisation(User $owner): Organisation
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

        return $org;
    }

    private function ownedProject(User $owner): Project
    {
        $org = $this->makeOrganisation($owner);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $org->id,
                'name' => 'Project '.bin2hex(random_bytes(3)),
            ])
            ->assertCreated();

        return Project::findOrFail($response->json('project.id'));
    }
}

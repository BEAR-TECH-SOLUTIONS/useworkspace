<?php

namespace Tests\Feature\Admin;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class AdminWorkspacesTest extends TestCase
{
    public function test_non_admin_cannot_list_workspaces(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/workspaces')
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_list_workspaces(): void
    {
        $this->getJson('/api/v1/admin/workspaces')->assertStatus(401);
    }

    public function test_admin_can_list_workspaces_paginated(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        ProjectFactory::forOwner(UserFactory::create());
        ProjectFactory::forOwner(UserFactory::create());

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/workspaces?per_page=10')
            ->assertOk()
            ->assertJsonStructure([
                'workspaces' => [['id', 'name', 'tier', 'seat_cap', 'owner_id', 'member_count', 'project_count']],
                'pagination' => ['page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_admin_can_filter_workspaces_by_tier(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $project = ProjectFactory::forOwner(UserFactory::create());
        Organisation::query()->whereKey($project->organisation_id)->update(['tier' => 'free']);

        ProjectFactory::forOwner(UserFactory::create()); // factory default = team

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/workspaces?tier=free')
            ->assertOk();

        foreach ($response->json('workspaces') as $row) {
            $this->assertSame('free', $row['tier']);
        }
    }

    public function test_admin_can_show_workspace_with_members(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/workspaces/{$project->organisation_id}")
            ->assertOk()
            ->assertJsonPath('workspace.id', $project->organisation_id)
            ->assertJsonStructure(['workspace' => ['owner', 'members', 'plan_limits']])
            ->assertJsonPath('workspace.owner.id', $owner->id);
    }

    public function test_admin_can_override_tier_and_seat_cap(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $project = ProjectFactory::forOwner(UserFactory::create());

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/workspaces/{$project->organisation_id}", [
                'tier' => PlanTier::Team->value,
                'seat_cap' => 75,
                'plan_limits' => ['max_members' => 75, 'can_provision_users' => true],
            ])
            ->assertOk()
            ->assertJsonPath('workspace.tier', 'team')
            ->assertJsonPath('workspace.seat_cap', 75)
            ->assertJsonPath('workspace.plan_limits.max_members', 75);
    }

    public function test_admin_cannot_delete_personal_workspace(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $project = ProjectFactory::forOwner(UserFactory::create());
        Organisation::query()->whereKey($project->organisation_id)->update(['is_personal' => true]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/workspaces/{$project->organisation_id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'personal_workspace_protected');

        $this->assertNotNull(Organisation::query()->whereKey($project->organisation_id)->first());
    }

    public function test_admin_can_delete_non_personal_workspace(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $project = ProjectFactory::forOwner(UserFactory::create());
        Organisation::query()->whereKey($project->organisation_id)->update(['is_personal' => false]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/workspaces/{$project->organisation_id}")
            ->assertStatus(204);

        $this->assertNull(Organisation::query()->whereKey($project->organisation_id)->first());
    }

    public function test_admin_can_list_licenses_under_admin_namespace(): void
    {
        // License management lives under /admin/licenses now — same
        // controller as the user/workspace surfaces, same cloud-admin
        // gate. Non-admin → 403, admin → 200.
        $user = UserFactory::create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/licenses')
            ->assertStatus(403);

        $admin = UserFactory::create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/licenses')
            ->assertOk();
    }
}

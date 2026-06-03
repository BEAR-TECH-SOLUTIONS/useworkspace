<?php

namespace Tests\Feature\Plans;

use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use Illuminate\Support\Str;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Plan-cap enforcement on the cloud edition. Tests build their own
 * workspaces (bypassing ProjectFactory) so the plan column starts at
 * exactly the value under test — the default factory uses `team`
 * which would mask Free-plan limits.
 */
class PlanEnforcerTest extends TestCase
{
    public function test_free_plan_allows_first_project_and_blocks_second(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'free');

        // First project succeeds.
        $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Project one',
            ])
            ->assertCreated();

        // Second tips over the cap.
        $response = $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Project two',
            ])
            ->assertStatus(422);

        $this->assertSame('plan_limit_projects', $response->json('code'));
    }

    public function test_plan_limits_override_widens_project_cap(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'free', overrides: ['max_projects' => 3]);

        for ($i = 1; $i <= 3; $i++) {
            $this->actingAs($owner)
                ->postJson('/api/v1/projects', [
                    'organisation_id' => $workspace->id,
                    'name' => "Project {$i}",
                ])
                ->assertCreated();
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/projects', [
                'organisation_id' => $workspace->id,
                'name' => 'Project four',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'plan_limit_projects');
    }

    public function test_unlimited_override_disables_project_cap(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'free', overrides: ['max_projects' => null]);

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($owner)
                ->postJson('/api/v1/projects', [
                    'organisation_id' => $workspace->id,
                    'name' => "Project {$i}",
                ])
                ->assertCreated();
        }
    }

    public function test_free_plan_blocks_invite_at_member_cap(): void
    {
        $owner = UserFactory::create();
        // Free plan caps at 2 members; owner row fills one slot. The
        // first invite (pending counts toward cap) fills the second,
        // and the third should be refused.
        $workspace = $this->workspaceFor($owner, plan: 'free');

        // First invite consumes the second seat.
        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
                'email' => 'one@example.com',
                'role' => 'member',
            ])
            ->assertCreated();

        // Second invite trips the plan_limit_members guard.
        $response = $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
                'email' => 'two@example.com',
                'role' => 'member',
            ])
            ->assertStatus(422);

        $this->assertSame('plan_limit_members', $response->json('code'));
    }

    public function test_entrepreneur_plan_allows_invite_under_cap(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'entrepreneur');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
                'email' => 'someone@example.com',
                'role' => 'member',
            ])
            ->assertCreated();
    }

    public function test_entrepreneur_plan_blocks_provisioning(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'entrepreneur');

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'new.user@example.com',
                'name' => 'New User',
                'password' => 'CorrectHorseBattery42!',
                'role' => 'member',
            ])
            ->assertStatus(422);

        $this->assertSame('plan_limit_provision_users', $response->json('code'));
    }

    public function test_team_plan_allows_provisioning(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspaceFor($owner, plan: 'team');

        $this->actingAs($owner)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'new.user'.bin2hex(random_bytes(2)).'@example.com',
                'name' => 'New User',
                'password' => 'CorrectHorseBattery42!',
                'role' => 'member',
            ])
            ->assertCreated();
    }

    /**
     * Build a workspace directly so the plan column lands as specified
     * — ProjectFactory defaults to `team`, which would hide Free-plan
     * caps from these tests.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function workspaceFor($owner, string $plan, ?array $overrides = null): Organisation
    {
        $workspace = Organisation::create([
            'owner_id' => $owner->id,
            'name' => 'Plan WS '.bin2hex(random_bytes(3)),
            'slug' => 'plan-ws-'.Str::random(8),
            'tier' => 'business',
            'seat_cap' => 1000,
            'plan' => $plan,
            'plan_limits' => $overrides,
        ]);

        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);

        return $workspace->refresh();
    }
}

<?php

namespace Tests\Feature\Workspaces;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Pairs with ProvisioningEntitlementTest + BillingCheckoutTest.
 *
 * GET /workspaces/{w}/resource-tree powers the member-access
 * picker UI on every plan that supports inviting OR provisioning
 * with per-resource scopes — i.e. every plan except possibly Free.
 * The endpoint has no plan-tier gate by spec (admin/owner only),
 * so this test walks every PlanTier and asserts an admin gets a
 * 200 regardless of plan.
 *
 * Locks down the regression vector from the bug report: a policy
 * change (e.g. the H17 personal-workspace block on manageMembers)
 * silently propagating to this endpoint and locking out
 * legitimate admin reads with a generic 403.
 */
class ResourceTreeEntitlementTest extends TestCase
{
    public function test_admin_on_every_plan_tier_can_load_resource_tree(): void
    {
        foreach (PlanTier::cases() as $tier) {
            [$admin, $workspace] = $this->workspaceOn($tier);

            $response = $this->actingAs($admin)
                ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree");

            $this->assertSame(
                200,
                $response->status(),
                "Tier {$tier->value} admin should get 200 from resource-tree, got HTTP {$response->status()}: ".$response->getContent(),
            );
            $response->assertJsonStructure(['projects']);
        }
    }

    public function test_admin_of_personal_workspace_can_load_resource_tree(): void
    {
        // Concrete regression for the bug report: even when the
        // workspace happens to be is_personal=true (e.g. the
        // owner upgraded their personal workspace to Team for
        // testing), the picker GET must still succeed. The
        // mutating provision/invite endpoints have their own
        // personal_workspace_not_provisionable rejection — this
        // read does not.
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        $workspace->forceFill([
            'is_personal' => true,
            'tier' => PlanTier::Team->value,
            'seat_cap' => PlanTier::Team->defaultSeatCap(),
        ])->save();

        $this->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk()
            ->assertJsonStructure(['projects']);
    }

    public function test_non_admin_member_is_rejected_with_generic_403(): void
    {
        [$admin, $workspace] = $this->workspaceOn(PlanTier::Team);

        // Add a regular member who shouldn't see the picker.
        $member = UserFactory::create();
        OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertStatus(403);
    }

    public function test_non_member_is_rejected(): void
    {
        [, $workspace] = $this->workspaceOn(PlanTier::Team);
        $stranger = UserFactory::create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: Organisation}
     */
    private function workspaceOn(PlanTier $tier): array
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $workspace->forceFill([
            // Default to a non-personal workspace so the loop tests
            // the tier-availability axis specifically. The personal
            // case has its own dedicated test above.
            'is_personal' => false,
            'tier' => $tier->value,
            'seat_cap' => $tier->defaultSeatCap(),
        ])->save();

        return [$admin, $workspace];
    }
}

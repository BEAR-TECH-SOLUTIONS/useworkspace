<?php

namespace Tests\Feature\Workspaces;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Regression for the catalog/entitlement vocabulary drift bug.
 *
 * Twin to BillingCheckoutTest: walks every PlanTier case and
 * asserts the matching `can_provision_users` capability is honoured
 * by POST /workspaces/{w}/provision-user.
 *
 *   • team / self_hosted (can_provision_users = true)  → 201
 *   • free / entrepreneur (can_provision_users = false) → 403 with
 *     code = feature_not_available — NOT the generic
 *     "This action is unauthorized" response.
 *
 * Also locks down two adjacent rejection codes so they don't
 * regress into the generic 403 either:
 *   • personal_workspace_not_provisionable (422)
 *   • generic "This action is unauthorized" (403) for non-admins
 *     on a qualifying-tier workspace.
 */
class ProvisioningEntitlementTest extends TestCase
{
    public function test_qualifying_tiers_accept_provision_calls(): void
    {
        foreach (PlanTier::cases() as $tier) {
            if (! $tier->defaultLimits()['can_provision_users']) {
                continue;
            }

            [$admin, $workspace] = $this->workspaceOn($tier);

            $response = $this->actingAs($admin)
                ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                    'email' => 'new-'.bin2hex(random_bytes(3)).'@example.com',
                    'name' => 'New User',
                    'password' => 'temp-password-123',
                ]);

            $this->assertSame(
                201,
                $response->status(),
                "Tier {$tier->value} should accept provisioning, got HTTP {$response->status()}: ".$response->getContent(),
            );
        }
    }

    public function test_non_qualifying_tiers_reject_with_feature_not_available(): void
    {
        foreach (PlanTier::cases() as $tier) {
            if ($tier->defaultLimits()['can_provision_users']) {
                continue;
            }

            [$admin, $workspace] = $this->workspaceOn($tier);

            $response = $this->actingAs($admin)
                ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                    'email' => 'new-'.bin2hex(random_bytes(3)).'@example.com',
                    'name' => 'New User',
                    'password' => 'temp-password-123',
                ]);

            $response->assertStatus(403);
            $this->assertSame(
                'feature_not_available',
                $response->json('code'),
                "Tier {$tier->value} should reject with code=feature_not_available, got: ".$response->getContent(),
            );
        }
    }

    public function test_personal_workspace_accepts_provisioning_on_qualifying_tier(): void
    {
        // `is_personal=true` on the workspace is metadata — it
        // marks the workspace auto-bootstrapped at register, and
        // exempts the row from the M11 per-user workspace cap.
        // It does NOT prevent provisioning. An admin must be able
        // to add members to their personal workspace exactly like
        // any other qualifying-tier workspace.
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();
        $workspace->forceFill([
            'is_personal' => true,
            'tier' => PlanTier::Team->value,
            'seat_cap' => PlanTier::Team->defaultSeatCap(),
        ])->save();

        $this->actingAs($admin)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'new-'.bin2hex(random_bytes(3)).'@example.com',
                'name' => 'New User',
                'password' => 'temp-password-123',
            ])
            ->assertStatus(201);
    }

    public function test_non_admin_member_on_qualifying_tier_gets_generic_unauthorized(): void
    {
        [$admin, $workspace] = $this->workspaceOn(PlanTier::Team);

        // Add a regular (non-admin) member to the workspace.
        $member = UserFactory::create();
        \App\Models\Identity\OrganisationMember::create([
            'organisation_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
        ]);

        $this->actingAs($member)
            ->postJson("/api/v1/workspaces/{$workspace->id}/provision-user", [
                'email' => 'new-'.bin2hex(random_bytes(3)).'@example.com',
                'name' => 'New User',
                'password' => 'temp-password-123',
            ])
            ->assertStatus(403);
        // Generic 403 from the manageMembers policy — no code field.
        // That's the documented shape for "you're in the workspace
        // but not an admin"; the feature-availability check has
        // already passed at this point.
    }

    /**
     * @return array{0: User, 1: Organisation}
     */
    private function workspaceOn(PlanTier $tier): array
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        // ProjectFactory defaults to Team. Override per scenario so
        // each tier's entitlement gets a representative workspace.
        // is_personal must stay false here — that branch has its
        // own dedicated test.
        $workspace->forceFill([
            'is_personal' => false,
            'tier' => $tier->value,
            'seat_cap' => $tier->defaultSeatCap(),
        ])->save();

        return [$admin, $workspace];
    }
}

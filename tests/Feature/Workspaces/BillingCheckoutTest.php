<?php

namespace Tests\Feature\Workspaces;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Regression for the catalog/checkout vocabulary drift bug.
 *
 * The GET /api/v1/plans catalog and POST /billing/checkout both
 * speak PlanTier ids (`free`, `entrepreneur`, `team`, `self_hosted`).
 * They used to disagree — the checkout endpoint validated against a
 * separate legacy WorkspaceTier enum (`solo`, `team`, `business`)
 * so every catalog id except `team` round-tripped to 422. The two
 * enums have since been collapsed into PlanTier; this test guards
 * against the drift re-emerging the next time a plan is renamed.
 *
 * The NullBillingDriver responds with 501 `billing_not_configured`
 * in environments where Stripe isn't wired — that's exactly what a
 * valid request should produce here. Anything 422 means the body
 * was rejected at validation, i.e. the bug has returned.
 */
class BillingCheckoutTest extends TestCase
{
    public function test_checkout_accepts_every_self_serve_plan_tier(): void
    {
        [$admin, $workspace] = $this->workspace();

        $selfServe = PlanTier::selfServeCheckoutCases();
        $this->assertNotEmpty($selfServe, 'PlanTier::selfServeCheckoutCases should not be empty.');

        foreach ($selfServe as $plan) {
            $response = $this->actingAs($admin)
                ->postJson("/api/v1/workspaces/{$workspace->id}/billing/checkout", [
                    'tier' => $plan->value,
                ]);

            // 200 (real driver) or 501 (NullBillingDriver) are both
            // acceptable signals that the request *passed* validation.
            // 422 means it was rejected as malformed — the original
            // bug. Anything else is a separate regression worth
            // surfacing here.
            $this->assertContains(
                $response->status(),
                [200, 501],
                "Tier {$plan->value} should be accepted by checkout, got HTTP {$response->status()}: ".$response->getContent(),
            );

            if ($response->status() === 422) {
                $this->fail("Tier {$plan->value} was rejected as invalid input: ".$response->getContent());
            }
        }
    }

    public function test_checkout_rejects_non_self_serve_plan_tiers(): void
    {
        [$admin, $workspace] = $this->workspace();

        foreach (PlanTier::cases() as $plan) {
            if ($plan->isSelfServeCheckout()) {
                continue;
            }

            $this->actingAs($admin)
                ->postJson("/api/v1/workspaces/{$workspace->id}/billing/checkout", [
                    'tier' => $plan->value,
                ])
                ->assertStatus(422);
        }
    }

    public function test_checkout_rejects_unknown_tier_strings(): void
    {
        [$admin, $workspace] = $this->workspace();

        // Legacy ids from before the plan-name unification, plus
        // common typos. The catalog never emits any of these — the
        // checkout endpoint must 422 on all of them.
        foreach (['solo', 'business', 'enterprise', '', 'TEAM'] as $bogus) {
            $this->actingAs($admin)
                ->postJson("/api/v1/workspaces/{$workspace->id}/billing/checkout", [
                    'tier' => $bogus,
                ])
                ->assertStatus(422);
        }
    }

    /**
     * @return array{0: User, 1: Organisation}
     */
    private function workspace(): array
    {
        $admin = UserFactory::create();
        $project = ProjectFactory::forOwner($admin);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        return [$admin, $workspace];
    }
}

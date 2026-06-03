<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Services\Workspaces\WorkspaceBillingService;
use Illuminate\Support\Str;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * WorkspaceBillingService::setTier covers the canonical PlanTier
 * column on `organisations.tier`. Previously there was a parallel
 * `plan` column that this service dual-wrote; the dual-write is
 * gone (single column, single enum) so these tests now exercise the
 * unified surface directly.
 */
class TierPlanBridgeTest extends TestCase
{
    public function test_set_tier_updates_dates_and_seat_cap(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspace($owner, tier: 'free');

        $this->app->make(WorkspaceBillingService::class)
            ->setTier($workspace, PlanTier::Team);

        $workspace->refresh();

        $this->assertSame('team', $workspace->tier->value);
        $this->assertSame(PlanTier::Team->defaultSeatCap(), (int) $workspace->seat_cap);
        $this->assertNotNull($workspace->plan_started_at);
        $this->assertNotNull($workspace->plan_renews_at);
        // Monthly billing for paid non-self-hosted plans.
        $this->assertSame(
            $workspace->plan_started_at->copy()->addMonth()->toDateString(),
            $workspace->plan_renews_at->toDateString(),
        );
    }

    public function test_self_hosted_tier_renews_yearly(): void
    {
        $owner = UserFactory::create();
        $workspace = $this->workspace($owner, tier: 'free');

        $this->app->make(WorkspaceBillingService::class)
            ->setTier($workspace, PlanTier::SelfHosted);

        $workspace->refresh();
        $this->assertSame('self_hosted', $workspace->tier->value);
        $this->assertSame(
            $workspace->plan_started_at->copy()->addYear()->toDateString(),
            $workspace->plan_renews_at->toDateString(),
        );
    }

    public function test_self_hosted_tier_seat_cap_matches_team_not_10000(): void
    {
        // Regression: buying the self-hosted plan used to bump the
        // cloud workspace's seat_cap to 10,000 (PlanTier::SelfHosted
        // declared an unlimited cap). The customer pays for the
        // self-hosted install, NOT an uncapped cloud workspace —
        // cloud-side caps must mirror the Team plan.
        $owner = UserFactory::create();
        $workspace = $this->workspace($owner, tier: 'free');

        $this->app->make(WorkspaceBillingService::class)
            ->setTier($workspace, PlanTier::SelfHosted);

        $workspace->refresh();
        $this->assertSame(50, $workspace->seat_cap);
        $this->assertSame(50, PlanTier::SelfHosted->defaultLimits()['max_members']);
        $this->assertTrue(PlanTier::SelfHosted->defaultLimits()['can_provision_users']);
    }

    public function test_downgrade_below_current_member_count_is_rejected(): void
    {
        $owner = UserFactory::create();
        // Workspace with 4 members on Team (seat_cap 50). Downgrade
        // to Free (seat_cap 2) must be refused; both the seat_cap
        // and plan_limit_members guards consider it over capacity.
        $workspace = $this->workspace($owner, tier: 'team', seatCap: 50);

        for ($i = 0; $i < 3; $i++) {
            OrganisationMember::create([
                'organisation_id' => $workspace->id,
                'user_id' => UserFactory::create()->id,
                'role' => 'member',
            ]);
        }

        try {
            $this->app->make(WorkspaceBillingService::class)
                ->setTier($workspace->refresh(), PlanTier::Free);
            $this->fail('Expected ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $codes = (array) $e->validator->errors()->get('code');
            $this->assertNotEmpty($codes, 'Expected a code in the validation errors');
            $this->assertContains(
                $codes[0],
                ['workspace_over_cap', 'plan_limit_members'],
            );
        }

        // Tier unchanged.
        $this->assertSame('team', $workspace->refresh()->tier->value);
    }

    private function workspace($owner, string $tier, int $seatCap = 50): Organisation
    {
        $org = Organisation::create([
            'owner_id' => $owner->id,
            'name' => 'Bridge WS '.bin2hex(random_bytes(3)),
            'slug' => 'bridge-ws-'.Str::random(8),
            'tier' => $tier,
            'seat_cap' => $seatCap,
        ]);

        OrganisationMember::create([
            'organisation_id' => $org->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);

        return $org->refresh();
    }
}

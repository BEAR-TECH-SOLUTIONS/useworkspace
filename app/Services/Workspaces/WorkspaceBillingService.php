<?php

namespace App\Services\Workspaces;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pure domain layer for billing state. The HTTP-level Stripe
 * integration (checkout session, portal session, webhook signature
 * verification) stubs out in `WorkspaceBillingController` until the
 * Stripe sprint lands — but the seat-cap-on-downgrade guard here is
 * unconditional so when the webhook *does* fire with a downgrade event,
 * the domain will reject it before ever reducing the cap.
 */
class WorkspaceBillingService
{
    /**
     * Apply a new tier to the workspace. Re-materialises `seat_cap`
     * from the tier's default seat cap, plus optional Team-tier
     * seat overflow.
     *
     * @throws ValidationException 422 workspace_over_cap when the new
     *         cap would be lower than the current member count — the
     *         caller (admin, or eventually the Stripe webhook handler)
     *         must remove members first.
     */
    public function setTier(Organisation $workspace, PlanTier $newTier, int $extraSeats = 0): void
    {
        $extraSeats = max(0, $extraSeats);
        // Only the Team plan currently supports seat overflow above
        // the default cap; other plans ignore the requested extras.
        $newCap = $newTier->defaultSeatCap() + ($newTier === PlanTier::Team ? $extraSeats : 0);

        DB::transaction(function () use ($workspace, $newTier, $newCap): void {
            // Re-read the workspace under a row lock so member count
            // and cap update happen against a stable snapshot.
            /** @var Organisation $fresh */
            $fresh = Organisation::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $memberCount = OrganisationMember::query()
                ->where('organisation_id', $fresh->id)
                ->count();

            if ($memberCount > $newCap) {
                throw ValidationException::withMessages([
                    'tier' => [
                        "Downgrade rejected: workspace has {$memberCount} members but {$newTier->value} allows {$newCap}.",
                    ],
                    'code' => ['workspace_over_cap'],
                    'current_member_count' => [$memberCount],
                    'new_seat_cap' => [$newCap],
                ])->status(422);
            }

            // The plan's PlanEnforcer cap is independent of the
            // seat_cap math above — either tripping is enough to
            // reject a downgrade.
            $planMaxMembers = $newTier->defaultLimits()['max_members'];
            if ($planMaxMembers !== null && $memberCount > $planMaxMembers) {
                throw ValidationException::withMessages([
                    'tier' => [
                        "Downgrade rejected: workspace has {$memberCount} members but plan {$newTier->value} caps at {$planMaxMembers}.",
                    ],
                    'code' => ['plan_limit_members'],
                ])->status(422);
            }

            $now = Carbon::now();
            $fresh->tier = $newTier->value;
            $fresh->seat_cap = $newCap;
            $fresh->plan_started_at = $now;
            $fresh->plan_renews_at = $this->renewalFor($now, $newTier);
            $fresh->save();
        });
    }

    private function renewalFor(Carbon $startedAt, PlanTier $plan): Carbon
    {
        // Self-hosted ships annually; everything else monthly. The
        // sandbox driver respects this; a real Paddle/Stripe driver
        // will overwrite with the actual subscription period_end.
        return $plan === PlanTier::SelfHosted
            ? $startedAt->copy()->addYear()
            : $startedAt->copy()->addMonth();
    }
}

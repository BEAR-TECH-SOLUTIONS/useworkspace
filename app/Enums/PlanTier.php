<?php

namespace App\Enums;

/**
 * Canonical billing plan applied to an `organisations` row.
 *
 * Stored on `organisations.tier` (the `workspace_tier` Postgres enum
 * — same vocabulary, same values). PlanEnforcer (cloud) /
 * LicenseEnforcer (self-hosted) read it to materialise caps; the
 * desktop client reads it to render the upgrade sheet. Limits ship
 * as the default cap table here; the `plan_limits` JSONB column on
 * `organisations` may widen these caps for a specific workspace
 * (negotiated overrides on cloud, license-payload-driven widening
 * on self-hosted). A `null` cap is "unlimited".
 */
enum PlanTier: string
{
    case Free = 'free';
    case Entrepreneur = 'entrepreneur';
    case Team = 'team';
    case SelfHosted = 'self_hosted';

    /**
     * Default seat cap. Mirrors the `max_members` cap from
     * {@see self::defaultLimits()} but expressed as a non-nullable
     * int — SelfHosted's "unlimited" gets clamped to a large finite
     * number so the seat-cap check on `organisations.seat_cap`
     * stays active (a zero cap would silently let anything through).
     */
    public function defaultSeatCap(): int
    {
        return match ($this) {
            self::Free => 1,
            self::Entrepreneur => 10,
            self::Team => 50,
            // SelfHosted's cloud workspace is treated as a Team-tier
            // org for cap purposes — the customer is paying for the
            // self-hosted install (license slot + image), not for an
            // uncapped cloud workspace. Member growth on the cloud
            // side stays inside the same envelope as Team; uncapped
            // member growth happens on the install they run.
            self::SelfHosted => 50,
        };
    }

    /**
     * Does this tier support admin-driven direct user provisioning
     * (POST /workspaces/{w}/provision-user)? Free/Entrepreneur stay
     * on the invitation-email flow; Team and Self-Hosted skip it.
     */
    public function supportsDirectProvisioning(): bool
    {
        return match ($this) {
            self::Team, self::SelfHosted => true,
            default => false,
        };
    }

    /**
     * Is this plan eligible for self-serve checkout? Free isn't (no
     * money changing hands). SelfHosted now is: subscribing to it
     * through Paddle auto-issues a license slot the install later
     * claims, so it's a paid self-serve path like the others.
     * Used by the checkout FormRequest to keep its accepted set in
     * lockstep with the GET /plans catalog — adding a new self-serve
     * plan just means flipping this method.
     */
    public function isSelfServeCheckout(): bool
    {
        return match ($this) {
            self::Entrepreneur, self::Team, self::SelfHosted => true,
            self::Free => false,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function selfServeCheckoutCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $plan): bool => $plan->isSelfServeCheckout(),
        ));
    }

    /**
     * Default cap table for the plan. Self-hosted reads caps from the
     * verified license payload at runtime — the values here are the
     * fallback when no license is yet loaded.
     *
     * @return array{max_projects: ?int, max_members: ?int, can_provision_users: bool}
     */
    public function defaultLimits(): array
    {
        return match ($this) {
            self::Free => [
                'max_projects' => 1,
                'max_members' => 1,
                'can_provision_users' => false,
            ],
            self::Entrepreneur => [
                'max_projects' => null,
                'max_members' => 10,
                'can_provision_users' => false,
            ],
            self::Team => [
                'max_projects' => null,
                'max_members' => 50,
                'can_provision_users' => true,
            ],
            // SelfHosted mirrors Team on the cloud side — buying the
            // self-hosted plan gives Team-equivalent cloud limits PLUS
            // an unclaimed license slot for the install. The install
            // itself enforces caps via LicenseEnforcer (which treats
            // an absent payload field as "unlimited"), so uncapped
            // member growth lives there, not here.
            self::SelfHosted => [
                'max_projects' => null,
                'max_members' => 50,
                'can_provision_users' => true,
            ],
        };
    }

    /**
     * Catalog metadata for the upgrade-plan sheet on the desktop
     * client. Prices stored as integer cents in USD; the client
     * formats them. Self-hosted ships as annual; everything else
     * monthly. Update here AND in your billing provider's product
     * configuration in lockstep — the API exposes these values via
     * GET /api/v1/plans (cloud only).
     *
     * @return array{display_name: string, price_cents: int, billing_interval: 'month'|'year'}
     */
    public function catalog(): array
    {
        return match ($this) {
            self::Free => [
                'display_name' => 'Free',
                'price_cents' => 0,
                'billing_interval' => 'month',
            ],
            self::Entrepreneur => [
                'display_name' => 'Entrepreneur',
                'price_cents' => 999,
                'billing_interval' => 'month',
            ],
            self::Team => [
                'display_name' => 'Team',
                'price_cents' => 3999,
                'billing_interval' => 'month',
            ],
            self::SelfHosted => [
                'display_name' => 'Self-hosted',
                'price_cents' => 19900,
                'billing_interval' => 'year',
            ],
        };
    }
}

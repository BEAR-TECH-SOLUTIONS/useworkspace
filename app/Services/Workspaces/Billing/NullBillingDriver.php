<?php

namespace App\Services\Workspaces\Billing;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;

/**
 * Production default until Stripe is wired. Every method throws —
 * the controller catches and returns 501 billing_not_configured.
 */
class NullBillingDriver implements BillingDriver
{
    public function createCheckout(Organisation $workspace, PlanTier $tier, int $extraSeats = 0): array
    {
        throw new BillingNotConfiguredException;
    }

    public function createPortal(Organisation $workspace): array
    {
        throw new BillingNotConfiguredException;
    }

    public function processWebhook(string $rawBody, string $signatureHeader): void
    {
        throw new BillingNotConfiguredException;
    }

    public function cancelSubscription(Organisation $workspace): array
    {
        throw new BillingNotConfiguredException;
    }
}

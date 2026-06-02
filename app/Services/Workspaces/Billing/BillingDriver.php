<?php

namespace App\Services\Workspaces\Billing;

use App\Enums\PlanTier;
use App\Models\Identity\Organisation;

/**
 * Billing driver contract. The HTTP layer stays the same shape the
 * client already builds against (`POST /workspaces/{w}/billing/checkout`
 * returns `{ checkout_url }`); swapping Stripe in is a single binding
 * change inside `AppServiceProvider::register`.
 *
 * Every method should throw `BillingNotConfiguredException` for
 * environments that don't have billing wired (the `none` driver). The
 * controller translates that into a 501 response.
 */
interface BillingDriver
{
    /**
     * Begin an upgrade. Returns a URL the client redirects the admin
     * to; in sandbox, that URL completes the flow when visited.
     *
     * @return array{checkout_url: string, session_id: string}
     */
    public function createCheckout(Organisation $workspace, PlanTier $tier, int $extraSeats = 0): array;

    /**
     * Return a URL into the provider's customer portal so the admin can
     * manage payment methods, invoices, and cancel.
     *
     * @return array{portal_url: string}
     */
    public function createPortal(Organisation $workspace): array;

    /**
     * Process a signed webhook payload. The driver owns signature
     * verification and event dispatch — handlers eventually land in
     * `WorkspaceBillingService::setTier` / status updates.
     */
    public function processWebhook(string $rawBody, string $signatureHeader): void;

    /**
     * Cancel the workspace's active subscription. Real billing
     * providers cancel at the next billing period so the customer
     * keeps access until then; the sandbox driver cancels immediately.
     *
     * The driver persists the effective date on the workspace
     * (`cancel_scheduled_at`) so a page reload doesn't need to
     * round-trip to the provider. Returns the same value plus the
     * current status for the controller's response.
     *
     * @return array{cancel_scheduled_at: ?string, status: string}
     */
    public function cancelSubscription(Organisation $workspace): array;
}

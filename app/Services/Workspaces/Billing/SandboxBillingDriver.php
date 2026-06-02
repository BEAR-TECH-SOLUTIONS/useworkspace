<?php

namespace App\Services\Workspaces\Billing;

use App\Enums\WorkspaceBillingStatus;
use App\Enums\PlanTier;
use App\Models\Identity\Organisation;
use App\Services\Workspaces\WorkspaceBillingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Synchronous test driver that exercises the complete payment
 * lifecycle end-to-end without hitting Stripe. When
 * `config('billing.driver') === 'sandbox'`, enables:
 *
 *   1. Checkout  → returns a sandbox URL the client redirects to.
 *   2. Complete  → hitting that URL calls setTier, marks the
 *                  workspace active/trialing, emits a synthetic
 *                  webhook event.
 *   3. Portal    → returns a URL to the sandbox portal page.
 *   4. Cancel    → portal's cancel action downgrades to free (rejected
 *                  with workspace_over_cap if the workspace would end
 *                  up below its current member count — mirrors the
 *                  real Stripe subscription.deleted path).
 *   5. Webhook   → accepts unsigned JSON payloads describing any of
 *                  the real Stripe events we handle; useful for
 *                  simulating `invoice.payment_failed` (past_due) or
 *                  drift scenarios in tests.
 *
 * The sandbox flow goes through exactly the same domain code
 * (`WorkspaceBillingService::setTier`) that the real Stripe driver
 * will call, so passing sandbox tests is a strong signal the Stripe
 * sprint will land green.
 *
 * NEVER bind this in production — `config/billing.php` keeps the
 * driver behind an env switch.
 */
class SandboxBillingDriver implements BillingDriver
{
    private const CACHE_PREFIX = 'billing.sandbox.session.';

    public function __construct(private readonly WorkspaceBillingService $billing) {}

    public function createCheckout(Organisation $workspace, PlanTier $tier, int $extraSeats = 0): array
    {
        $sessionId = 'sbx_'.Str::random(32);

        Cache::put(
            self::CACHE_PREFIX.$sessionId,
            [
                'workspace_id' => (int) $workspace->id,
                'tier' => $tier->value,
                'extra_seats' => $extraSeats,
                'created_at' => now()->toIso8601String(),
            ],
            (int) config('billing.sandbox.session_ttl', 3600),
        );

        return [
            'checkout_url' => URL::to("/api/v1/billing/sandbox/checkout/{$sessionId}"),
            'session_id' => $sessionId,
        ];
    }

    public function createPortal(Organisation $workspace): array
    {
        return [
            'portal_url' => URL::to("/api/v1/billing/sandbox/portal/{$workspace->id}"),
        ];
    }

    /**
     * Finalise a sandbox checkout session. Applies `setTier`, updates
     * billing status, and emits the synthetic
     * `customer.subscription.created` path through our own webhook
     * dispatch so both flows converge on the same domain logic.
     *
     * @return array{workspace_id:int,tier:string,seat_cap:int}
     *
     * @throws ValidationException when the session is unknown/expired,
     *         or when WorkspaceBillingService rejects the tier change.
     */
    public function completeCheckout(string $sessionId): array
    {
        $session = Cache::get(self::CACHE_PREFIX.$sessionId);
        if ($session === null) {
            throw ValidationException::withMessages([
                'session' => ['Unknown or expired sandbox session.'],
                'code' => ['sandbox_session_not_found'],
            ])->status(404);
        }

        /** @var Organisation $workspace */
        $workspace = Organisation::query()->whereKey($session['workspace_id'])->firstOrFail();

        $tier = PlanTier::from($session['tier']);
        $this->billing->setTier($workspace, $tier, (int) ($session['extra_seats'] ?? 0));

        $workspace->refresh();
        $workspace->billing_status = WorkspaceBillingStatus::Active->value;
        $workspace->billing_customer_id = $workspace->billing_customer_id ?: 'sbx_cus_'.Str::random(16);
        $workspace->billing_subscription_id = 'sbx_sub_'.Str::random(16);
        $workspace->save();

        Cache::forget(self::CACHE_PREFIX.$sessionId);

        return [
            'workspace_id' => (int) $workspace->id,
            'tier' => $workspace->tier?->value,
            'seat_cap' => (int) $workspace->seat_cap,
        ];
    }

    /**
     * Portal "cancel subscription" action. Sandbox cancels immediately
     * (no billing-period to honour), so cancel_scheduled_at stays null
     * and the workspace returns to Free on the spot. Domain layer
     * rejects with `workspace_over_cap` if Free would fall below the
     * current member count.
     *
     * @return array{cancel_scheduled_at: ?string, status: string}
     */
    public function cancelSubscription(Organisation $workspace): array
    {
        $this->billing->setTier($workspace, PlanTier::Free, 0);

        $workspace->refresh();
        $workspace->billing_status = WorkspaceBillingStatus::Canceled->value;
        $workspace->billing_subscription_id = null;
        $workspace->cancel_scheduled_at = null;
        $workspace->save();

        return [
            'cancel_scheduled_at' => null,
            'status' => WorkspaceBillingStatus::Canceled->value,
        ];
    }

    /**
     * Accept a synthetic webhook payload that mimics a Stripe event
     * shape. No signature verification — sandbox is trust-local.
     *
     * Expected payload:
     *   { "type": "customer.subscription.updated",
     *     "workspace_id": 42,
     *     "tier": "team",
     *     "extra_seats": 0 }
     */
    public function processWebhook(string $rawBody, string $signatureHeader): void
    {
        $payload = json_decode($rawBody, true);
        if (! is_array($payload) || ! isset($payload['type'], $payload['workspace_id'])) {
            throw ValidationException::withMessages([
                'payload' => ['Malformed sandbox webhook payload.'],
            ])->status(400);
        }

        /** @var Organisation $workspace */
        $workspace = Organisation::query()->whereKey($payload['workspace_id'])->firstOrFail();

        match ($payload['type']) {
            'customer.subscription.created',
            'customer.subscription.updated' => $this->handleUpsertEvent($workspace, $payload),
            'customer.subscription.deleted' => $this->cancelSubscription($workspace),
            'invoice.payment_failed' => $this->markPastDue($workspace),
            default => throw ValidationException::withMessages([
                'type' => ["Unsupported sandbox event type: {$payload['type']}."],
            ])->status(422),
        };
    }

    private function handleUpsertEvent(Organisation $workspace, array $payload): void
    {
        if (! isset($payload['tier'])) {
            throw ValidationException::withMessages([
                'tier' => ['Tier is required for subscription upsert events.'],
            ])->status(422);
        }

        $tier = PlanTier::from((string) $payload['tier']);
        $extraSeats = (int) ($payload['extra_seats'] ?? 0);

        $this->billing->setTier($workspace, $tier, $extraSeats);

        $workspace->refresh();
        $workspace->billing_status = WorkspaceBillingStatus::Active->value;
        $workspace->save();
    }

    private function markPastDue(Organisation $workspace): void
    {
        $workspace->billing_status = WorkspaceBillingStatus::PastDue->value;
        $workspace->save();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PlanTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\CreateBillingCheckoutRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\Identity\Organisation;
use App\Services\Workspaces\Billing\BillingDriver;
use App\Services\Workspaces\Billing\BillingNotConfiguredException;
use App\Services\Workspaces\Billing\SandboxBillingDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Billing surface. Delegates to the injected `BillingDriver` —
 * `NullBillingDriver` in production until Stripe is wired (501),
 * `SandboxBillingDriver` for end-to-end tests that exercise the full
 * lifecycle through `WorkspaceBillingService::setTier`.
 *
 * Sandbox-only endpoints (`/billing/sandbox/*`) are registered
 * unconditionally but short-circuit to 404 if the active driver
 * isn't the sandbox — that keeps the URL shape stable in OpenAPI
 * without leaking the feature in production.
 */
class WorkspaceBillingController extends Controller
{
    public function __construct(private readonly BillingDriver $driver) {}

    public function checkout(CreateBillingCheckoutRequest $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        try {
            $tier = PlanTier::from($request->string('tier')->toString());
            $result = $this->driver->createCheckout($workspace, $tier, (int) $request->input('extra_seats', 0));

            return response()->json($result);
        } catch (BillingNotConfiguredException $e) {
            return $this->notConfigured();
        }
    }

    public function portal(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        try {
            return response()->json($this->driver->createPortal($workspace));
        } catch (BillingNotConfiguredException $e) {
            return $this->notConfigured();
        }
    }

    /**
     * Current billing state for a workspace — what the customer sees
     * in their "Subscription" panel. Pure read from the org row; no
     * round-trip to the billing provider, so it's safe to poll.
     */
    public function summary(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        return response()->json([
            'billing' => $this->billingState($workspace),
        ]);
    }

    /**
     * Trigger a cancellation. Real providers cancel at the next
     * billing period (customer keeps paid access until then); the
     * sandbox cancels immediately. The effective date is persisted on
     * the workspace and surfaced in the response so the client can
     * render "Subscription ends on …" without polling the provider.
     */
    public function cancel(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        try {
            $this->driver->cancelSubscription($workspace);

            return response()->json([
                'billing' => $this->billingState($workspace->refresh()),
            ]);
        } catch (BillingNotConfiguredException $e) {
            return $this->notConfigured();
        }
    }

    /**
     * Shape returned by GET /billing and POST /billing/cancel. The
     * client uses `has_subscription` to gate the "Cancel" /
     * "Manage payment method" actions and `cancel_scheduled` to
     * render the "Subscription ends on …" notice.
     *
     * @return array<string, mixed>
     */
    private function billingState(Organisation $workspace): array
    {
        return [
            'tier' => $workspace->tier?->value,
            'status' => $workspace->billing_status?->value,
            'seat_cap' => (int) $workspace->seat_cap,
            'subscription_id' => $workspace->billing_subscription_id,
            'started_at' => $workspace->plan_started_at?->toIso8601String(),
            'renews_at' => $workspace->plan_renews_at?->toIso8601String(),
            'cancel_scheduled_at' => $workspace->cancel_scheduled_at?->toIso8601String(),
            'trial_ends_at' => $workspace->trial_ends_at?->toIso8601String(),
            'has_subscription' => $workspace->billing_subscription_id !== null,
            'cancel_scheduled' => $workspace->cancel_scheduled_at !== null,
        ];
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            // Paddle sends `Paddle-Signature`; the controller used
            // to read `Stripe-Signature` from when this code
            // targeted Stripe, which silently broke verification
            // because `Paddle-Signature` was never inspected and
            // the verifier threw on the empty string.
            $this->driver->processWebhook(
                $request->getContent(),
                (string) $request->header('Paddle-Signature', ''),
            );

            return response()->json(['received' => true]);
        } catch (BillingNotConfiguredException $e) {
            return $this->notConfigured();
        }
    }

    /**
     * Sandbox-only: finalise a checkout session the sandbox driver
     * created. Applies setTier, flips billing status to `active`,
     * drops the cached session.
     */
    public function sandboxCheckoutComplete(Request $request, string $session): JsonResponse
    {
        $sandbox = $this->requireSandbox();

        $result = $sandbox->completeCheckout($session);

        /** @var Organisation $workspace */
        $workspace = Organisation::query()->whereKey($result['workspace_id'])->firstOrFail();

        return response()->json([
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }


    /**
     * Sandbox-only: the "cancel subscription" action in the mock
     * portal. Mirrors the Stripe subscription.deleted event — admin
     * gate, workspace_over_cap guard from setTier applies.
     */
    public function sandboxCancel(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        $sandbox = $this->requireSandbox();
        $sandbox->cancelSubscription($workspace);

        return response()->json([
            'workspace' => new WorkspaceResource($workspace->refresh()),
        ]);
    }

    private function requireSandbox(): SandboxBillingDriver
    {
        if (! $this->driver instanceof SandboxBillingDriver) {
            abort(404);
        }

        return $this->driver;
    }

    private function notConfigured(): JsonResponse
    {
        return response()->json([
            'message' => 'Billing is not configured on this environment.',
            'code' => 'billing_not_configured',
        ], 501);
    }
}

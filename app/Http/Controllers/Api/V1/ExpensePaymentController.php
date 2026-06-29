<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpensePaymentRequest;
use App\Http\Resources\Expenses\ExpensePaymentResource;
use App\Http\Resources\Expenses\ExpenseResource;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpensePayment;
use App\Services\Expenses\ExpensePaymentService;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expense Payments spec §2–4. Record payments, list history, undo the
 * most recent one. Due-date advancement always works from the old
 * `next_due_date`, not from `paid_at`, so late payments don't cause
 * calendar drift.
 */
class ExpensePaymentController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly ExpensePaymentService $payments,
    ) {}

    /**
     * POST /expenses/{expense}/pay — mark as paid.
     */
    public function pay(StoreExpensePaymentRequest $request, Expense $expense): JsonResponse
    {
        $user = $request->user();
        $this->perms->authorize($user, Abilities::UPDATE, $expense);

        $cycle = $expense->billing_cycle instanceof BillingCycle
            ? $expense->billing_cycle
            : BillingCycle::from((string) $expense->billing_cycle);

        // One-time already-paid guard: next_due_date is NULL after the
        // first payment; a second pay would have no date to advance from.
        if ($cycle === BillingCycle::OneTime && $expense->next_due_date === null) {
            return response()->json([
                'message' => 'This one-time expense has already been paid.',
                'code' => 'already_paid',
            ], 409);
        }

        $paidAt = $request->input('paid_at')
            ? Carbon::parse($request->input('paid_at'))
            : Carbon::today();

        $amount = $request->filled('amount')
            ? (float) $request->input('amount')
            : (float) $expense->amount;

        // Record + advance is shared with the auto-pay command via the
        // service so both paths stay byte-for-byte consistent.
        [$expense, $payment] = $this->payments->record(
            $expense,
            $paidAt,
            $amount,
            $request->input('note'),
            $user->id,
        );

        return response()->json([
            'expense' => new ExpenseResource($expense),
            'payment' => (new ExpensePaymentResource($payment->load('creator')))->toArray($request),
        ], 201);
    }

    /**
     * GET /expenses/{expense}/payments — paginated history.
     */
    public function index(Request $request, Expense $expense): JsonResponse
    {
        $this->perms->authorize($request->user(), Abilities::VIEW, $expense);

        $perPage = min((int) $request->query('per_page', 25), 100);

        $paginator = ExpensePayment::query()
            ->with('creator')
            ->where('expense_id', $expense->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => ExpensePaymentResource::collection($paginator->items()),
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * DELETE /expenses/{expense}/payments/{payment} — undo the latest.
     */
    public function destroy(Request $request, Expense $expense, ExpensePayment $payment): JsonResponse
    {
        abort_unless((int) $payment->expense_id === (int) $expense->id, 404);

        $this->perms->authorize($request->user(), Abilities::UPDATE, $expense);

        // Only the most recent payment can be deleted — prevents
        // rewriting history out of order.
        $latestId = ExpensePayment::query()
            ->where('expense_id', $expense->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->value('id');

        if ((int) $latestId !== (int) $payment->id) {
            return response()->json([
                'message' => 'Only the most recent payment can be deleted.',
                'code' => 'not_latest_payment',
            ], 409);
        }

        $cycle = $expense->billing_cycle instanceof BillingCycle
            ? $expense->billing_cycle
            : BillingCycle::from((string) $expense->billing_cycle);

        $expense = DB::transaction(function () use ($expense, $payment, $cycle): Expense {
            $payment->delete();

            if ($cycle === BillingCycle::OneTime) {
                // Restore next_due_date to the date this payment was
                // recorded against — the expense is "unpaid" again.
                $expense->next_due_date = $payment->paid_at;
            } else {
                // Reverse the cycle shift from the current next_due_date.
                $currentDue = $expense->next_due_date !== null
                    ? Carbon::parse($expense->next_due_date)
                    : null;
                $expense->next_due_date = $currentDue !== null
                    ? $cycle->reverse($currentDue)?->toDateString()
                    : null;
            }

            $expense->save();

            return $expense->refresh();
        });

        return response()->json([
            'expense' => new ExpenseResource($expense),
        ]);
    }
}

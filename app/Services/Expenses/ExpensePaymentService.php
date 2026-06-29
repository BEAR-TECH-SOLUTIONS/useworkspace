<?php

namespace App\Services\Expenses;

use App\Enums\BillingCycle;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpensePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for recording an expense payment. Used by both
 * the manual pay endpoint (ExpensePaymentController) and the scheduled
 * auto-pay command (AutoPayExpenses) so payment history, totals, and
 * due-date advancement stay identical across the two paths.
 *
 * Due-date advancement always works from the OLD `next_due_date`, never
 * from `paid_at`, so a late (or batched) payment can't cause calendar
 * drift (Expense Payments spec §2 bullet 4).
 */
class ExpensePaymentService
{
    /**
     * Record one payment against $expense and advance its `next_due_date`
     * by exactly one billing cycle (left null for one-time expenses).
     *
     * @return array{0: Expense, 1: ExpensePayment} the refreshed expense and the new payment
     */
    public function record(Expense $expense, Carbon $paidAt, float $amount, ?string $note, int $createdBy): array
    {
        $cycle = $expense->billing_cycle instanceof BillingCycle
            ? $expense->billing_cycle
            : BillingCycle::from((string) $expense->billing_cycle);

        return DB::transaction(function () use ($expense, $cycle, $paidAt, $amount, $note, $createdBy): array {
            $payment = ExpensePayment::create([
                'expense_id' => $expense->id,
                'paid_at' => $paidAt->toDateString(),
                'amount' => $amount,
                'currency' => $expense->currency,
                'note' => $note,
                'created_by' => $createdBy,
            ]);

            $currentDue = $expense->next_due_date !== null
                ? Carbon::parse($expense->next_due_date)
                : null;

            $newDue = $currentDue !== null ? $cycle->advance($currentDue) : null;

            $expense->next_due_date = $newDue?->toDateString();
            $expense->save();

            return [$expense->refresh(), $payment];
        });
    }
}

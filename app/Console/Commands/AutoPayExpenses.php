<?php

namespace App\Console\Commands;

use App\Enums\BillingCycle;
use App\Models\Expenses\Expense;
use App\Services\Expenses\ExpensePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * #220 — record payments automatically for recurring expenses flagged
 * `auto_mark_paid`. For each such expense whose `next_due_date` has
 * arrived (or passed), record a payment for that cycle and advance the
 * due date by one cycle from the OLD date, looping until the due date is
 * back in the future so a multi-cycle-overdue expense catches up.
 *
 * These expenses are deliberately excluded from `expenses:roll-due-dates`
 * (which advances due dates without recording payments) — here the
 * payment IS what advances the date, so the two must not both touch it.
 */
class AutoPayExpenses extends Command
{
    protected $signature = 'expenses:auto-pay';

    protected $description = 'Record payments for recurring expenses set to auto-mark-paid whose due date has arrived.';

    /**
     * Defensive cap on cycles recorded for a single expense in one run.
     * The loop always terminates on its own (advance() strictly moves the
     * date forward toward today), so this only fires for a pathologically
     * mis-set due date — in which case we log rather than spin.
     */
    private const MAX_CATCHUP = 1000;

    public function __construct(private readonly ExpensePaymentService $payments)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::today();
        $cyclesPaid = 0;

        Expense::query()
            ->where('auto_mark_paid', true)
            ->where('billing_cycle', '!=', BillingCycle::OneTime->value)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $today)
            ->chunkById(500, function ($expenses) use (&$cyclesPaid, $today): void {
                foreach ($expenses as $expense) {
                    $cyclesPaid += $this->catchUp($expense, $today);
                }
            });

        $this->info("Auto-paid cycles: {$cyclesPaid}");

        return self::SUCCESS;
    }

    /**
     * Record one payment per elapsed cycle until the expense's due date is
     * in the future again. Returns the number of cycles paid.
     */
    private function catchUp(Expense $expense, Carbon $today): int
    {
        $count = 0;

        while ($count < self::MAX_CATCHUP) {
            $due = $expense->next_due_date;
            if ($due === null) {
                break;
            }

            $dueDate = Carbon::parse($due);
            if ($dueDate->gt($today)) {
                break;
            }

            // paid_at is the cycle's own due date; the service advances
            // next_due_date by one cycle from that same date (no drift).
            [$expense] = $this->payments->record(
                $expense,
                $dueDate,
                (float) $expense->amount,
                null,
                (int) $expense->created_by,
            );

            $count++;
        }

        if ($count >= self::MAX_CATCHUP) {
            $this->warn("Expense {$expense->id} hit the {$count}-cycle catch-up cap; left at next_due_date={$expense->next_due_date}.");
        }

        return $count;
    }
}

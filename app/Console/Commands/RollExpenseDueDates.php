<?php

namespace App\Console\Commands;

use App\Enums\BillingCycle;
use App\Models\Expenses\Expense;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Advance `next_due_date` on recurring expenses whose due date has
 * passed. One-time expenses are left alone — they stay in history as a
 * past item. An expense that's been skipped for multiple cycles is
 * rolled forward cycle-by-cycle until it lands on or after today, so
 * the projection endpoints always reflect the next *future* occurrence.
 */
class RollExpenseDueDates extends Command
{
    protected $signature = 'expenses:roll-due-dates';

    protected $description = 'Roll next_due_date forward on recurring expenses whose due date has passed.';

    public function handle(): int
    {
        $today = Carbon::today();
        $rolled = 0;

        Expense::query()
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $today)
            ->where('billing_cycle', '!=', BillingCycle::OneTime->value)
            ->chunkById(500, function ($expenses) use (&$rolled, $today): void {
                foreach ($expenses as $expense) {
                    $newDate = $this->advance($expense, $today);
                    if ($newDate === null) {
                        continue;
                    }

                    $expense->forceFill(['next_due_date' => $newDate])->save();
                    $rolled++;
                }
            });

        $this->info("Rolled forward: {$rolled}");

        return self::SUCCESS;
    }

    private function advance(Expense $expense, Carbon $today): ?Carbon
    {
        $cycle = $expense->billing_cycle instanceof BillingCycle
            ? $expense->billing_cycle
            : BillingCycle::from((string) $expense->billing_cycle);

        $date = Carbon::parse($expense->next_due_date)->startOfDay();

        while ($date->lt($today)) {
            $date = $cycle->advance($date);

            if ($date === null) {
                return null;
            }
        }

        return $date;
    }
}

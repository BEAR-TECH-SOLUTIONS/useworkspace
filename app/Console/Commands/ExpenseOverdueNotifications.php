<?php

namespace App\Console\Commands;

use App\Enums\BillingCycle;
use App\Enums\NotificationType;
use App\Models\Expenses\Expense;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * #213A — notify bucket viewers when a recurring expense's most recently
 * elapsed cycle was never marked paid.
 *
 * `next_due_date` is always the next *future* occurrence (the hourly
 * `expenses:roll-due-dates` job keeps it there), so "overdue" can't be
 * read off `next_due_date < today`. Instead the most recently elapsed
 * due date is `next_due_date` minus one cycle; if no payment has been
 * recorded on or after that date, the cycle was missed.
 *
 * Auto-pay expenses are excluded — `expenses:auto-pay` settles them, so
 * they're never overdue (#220). One notification per missed cycle:
 * dedupe is keyed on the elapsed due date stored in `metadata.due_date`.
 */
class ExpenseOverdueNotifications extends Command
{
    protected $signature = 'notifications:expense-overdue';

    protected $description = 'Notify bucket viewers about recurring expenses whose last cycle went unpaid.';

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $created = 0;

        Expense::query()
            ->with('bucket')
            ->where('billing_cycle', '!=', BillingCycle::OneTime->value)
            ->where('auto_mark_paid', false)
            ->whereNotNull('next_due_date')
            ->chunkById(200, function ($expenses) use (&$created, $today, $now): void {
                foreach ($expenses as $expense) {
                    $created += $this->emitForExpense($expense, $today, $now);
                }
            });

        $this->info("Created {$created} expense_overdue notifications.");

        return self::SUCCESS;
    }

    private function emitForExpense(Expense $expense, Carbon $today, Carbon $now): int
    {
        if ($expense->bucket === null) {
            return 0;
        }

        $cycle = $expense->billing_cycle instanceof BillingCycle
            ? $expense->billing_cycle
            : BillingCycle::from((string) $expense->billing_cycle);

        // The most recently elapsed occurrence is one cycle before the
        // next (future) due date.
        $elapsed = $cycle->reverse(Carbon::parse($expense->next_due_date));
        if ($elapsed === null) {
            return 0;
        }
        $elapsed = $elapsed->startOfDay();

        // Nothing has elapsed yet (the next occurrence is the first one).
        if ($elapsed->gte($today)) {
            return 0;
        }

        // The expense didn't exist at that occurrence — not a missed cycle.
        if ($elapsed->lt(Carbon::parse($expense->created_at)->startOfDay())) {
            return 0;
        }

        // Paid? A payment on or after the elapsed due date settles that
        // cycle (the manual/auto pay flow records paid_at >= the due date).
        $paid = $expense->payments()
            ->where('paid_at', '>=', $elapsed->toDateString())
            ->exists();
        if ($paid) {
            return 0;
        }

        $viewerIds = $this->notifications->bucketViewerIds($expense->bucket);
        if ($viewerIds === []) {
            return 0;
        }

        $elapsedDate = $elapsed->toDateString();
        $ctx = $this->notifications->expenseOverdueContext($expense, $elapsed, $now);

        $created = 0;
        foreach ($viewerIds as $uid) {
            // Once per missed cycle: skip if this user already has an
            // expense_overdue row for this expense and elapsed due date.
            $already = Notification::query()
                ->where('user_id', $uid)
                ->where('type', NotificationType::ExpenseOverdue->value)
                ->where('resource_type', 'expense')
                ->where('resource_id', $expense->id)
                ->where('metadata->due_date', $elapsedDate)
                ->exists();

            if ($already) {
                continue;
            }

            $row = $this->notifications->create(
                userId: $uid,
                type: NotificationType::ExpenseOverdue,
                title: $ctx['title'],
                body: $ctx['body'],
                workspace: $ctx['workspace'],
                project: $ctx['project'],
                resourceType: 'expense',
                resourceId: $expense->id,
                metadata: [
                    'bucket_id' => $expense->bucket_id,
                    'due_date' => $elapsedDate,
                ],
            );

            if ($row !== null) {
                $created++;
            }
        }

        return $created;
    }
}

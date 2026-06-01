<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Expenses\Expense;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily job for spec §4 notification type 6 — expenses with a
 * next_due_date inside the next 3 days. Recipients are every user
 * with view access on the parent bucket (project members + any
 * Pattern B direct bucket grantees), resolved via
 * NotificationService::bucketViewerIds().
 *
 * Dedup window: 24h.
 */
class ExpenseDueSoonNotifications extends Command
{
    protected $signature = 'notifications:expense-due-soon';

    protected $description = 'Notify bucket viewers about expenses due within the next 3 days.';

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $windowEnd = $now->copy()->startOfDay()->addDays(3)->endOfDay();

        $created = 0;

        Expense::query()
            ->with('bucket')
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today, $windowEnd])
            ->chunkById(200, function ($expenses) use (&$created, $now): void {
                foreach ($expenses as $expense) {
                    $created += $this->emitForExpense($expense, $now);
                }
            });

        $this->info("Created {$created} expense_due_soon notifications.");

        return self::SUCCESS;
    }

    private function emitForExpense(Expense $expense, Carbon $now): int
    {
        if ($expense->bucket === null) {
            return 0;
        }

        $viewerIds = $this->notifications->bucketViewerIds($expense->bucket);
        if ($viewerIds === []) {
            return 0;
        }

        $ctx = $this->notifications->expenseDueContext($expense, $now);

        $created = 0;
        foreach ($viewerIds as $uid) {
            $row = $this->notifications->createIfNotRecent(
                userId: $uid,
                type: NotificationType::ExpenseDueSoon,
                resourceType: 'expense',
                resourceId: $expense->id,
                within: new \DateInterval('PT24H'),
                title: $ctx['title'],
                body: $ctx['body'],
                workspace: $ctx['workspace'],
                project: $ctx['project'],
                metadata: [
                    'bucket_id' => $expense->bucket_id,
                    'due_date' => $expense->next_due_date->toDateString(),
                ],
            );

            if ($row !== null) {
                $created++;
            }
        }

        return $created;
    }
}

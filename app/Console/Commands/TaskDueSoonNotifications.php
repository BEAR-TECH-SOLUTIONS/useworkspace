<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Tasks\TaskItem;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily job that notifies assignees about tasks whose due_date falls
 * inside the next 2 days (spec §4 notification type 4).
 *
 * Dedup window: 24h. If a `task_due_soon` notification already exists
 * for the same (user, task) within 24h, skip — NotificationService
 * does the lookup so the cron stays a thin loop.
 *
 * Scope: excludes completed and archived tasks, and tasks whose due
 * date has already passed (those fire the overdue notification in a
 * separate command).
 */
class TaskDueSoonNotifications extends Command
{
    protected $signature = 'notifications:task-due-soon';

    protected $description = 'Notify assignees about tasks due within the next 2 days.';

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $windowEnd = $now->copy()->startOfDay()->addDays(2)->endOfDay();

        $created = 0;

        TaskItem::query()
            ->with(['column.board', 'assignees'])
            ->where('is_completed', false)
            ->where('is_archived', false)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$today, $windowEnd])
            ->chunkById(200, function ($tasks) use (&$created, $now): void {
                foreach ($tasks as $task) {
                    $created += $this->emitForTask($task, $now);
                }
            });

        $this->info("Created {$created} task_due_soon notifications.");

        return self::SUCCESS;
    }

    private function emitForTask(TaskItem $task, Carbon $now): int
    {
        $assigneeIds = $task->assignees->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($assigneeIds === []) {
            return 0;
        }

        [$project, $workspace] = $this->notifications->projectContextFor($task);
        $boardName = $task->column?->board?->name;
        $columnName = $task->column?->name;
        $relative = $this->notifications->relativeDate(Carbon::parse($task->due_date), $now);

        $body = trim(($task->priority?->value ?? 'normal').' priority · '
            .($boardName !== null ? $boardName.' / '.$columnName : 'untitled board'));

        $created = 0;
        foreach ($assigneeIds as $uid) {
            $row = $this->notifications->createIfNotRecent(
                userId: $uid,
                type: NotificationType::TaskDueSoon,
                resourceType: 'task',
                resourceId: $task->id,
                within: new \DateInterval('PT24H'),
                title: '"'.$task->title.'" is due '.$relative,
                body: $body,
                workspace: $workspace,
                project: $project,
                metadata: [
                    'board_id' => $task->column?->board_id,
                    'due_date' => $task->due_date->toDateString(),
                ],
            );

            if ($row !== null) {
                $created++;
            }
        }

        return $created;
    }
}

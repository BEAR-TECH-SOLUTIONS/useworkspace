<?php

namespace App\Console\Commands;

use App\Enums\NotificationType;
use App\Models\Tasks\TaskItem;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily job for spec §4 notification type 5 — tasks whose due_date is
 * strictly in the past and have not been completed or archived.
 *
 * Dedup window: 3 days. Overdue tasks are noisy by definition, so we
 * keep the drumbeat quieter than "due soon" — one reminder every three
 * days until the task is resolved.
 */
class TaskOverdueNotifications extends Command
{
    protected $signature = 'notifications:task-overdue';

    protected $description = 'Notify assignees about tasks whose due_date has passed.';

    public function __construct(private readonly NotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $today = Carbon::now()->startOfDay();

        $created = 0;

        TaskItem::query()
            ->with(['column.board', 'assignees'])
            ->where('is_completed', false)
            ->where('is_archived', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->chunkById(200, function ($tasks) use (&$created): void {
                foreach ($tasks as $task) {
                    $created += $this->emitForTask($task);
                }
            });

        $this->info("Created {$created} task_overdue notifications.");

        return self::SUCCESS;
    }

    private function emitForTask(TaskItem $task): int
    {
        $assigneeIds = $task->assignees->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if ($assigneeIds === []) {
            return 0;
        }

        [$project, $workspace] = $this->notifications->projectContextFor($task);
        $boardName = $task->column?->board?->name;

        $body = 'Due '.$task->due_date->toDateString()
            .($boardName !== null ? ' · '.$boardName : '');

        $created = 0;
        foreach ($assigneeIds as $uid) {
            $row = $this->notifications->createIfNotRecent(
                userId: $uid,
                type: NotificationType::TaskOverdue,
                resourceType: 'task',
                resourceId: $task->id,
                within: new \DateInterval('P3D'),
                title: '"'.$task->title.'" is overdue',
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

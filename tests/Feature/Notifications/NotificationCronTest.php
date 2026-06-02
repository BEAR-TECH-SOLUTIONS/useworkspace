<?php

namespace Tests\Feature\Notifications;

use App\Enums\BillingCycle;
use App\Enums\ExpenseCategory;
use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Enums\ResourceType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Notification;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class NotificationCronTest extends TestCase
{
    public function test_task_due_soon_notifies_assignees_within_window(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $inWindow = $this->seedTask($owner, $column, 'In window', [
            'due_date' => Carbon::now()->addDay()->toDateString(),
        ]);
        $inWindow->assignees()->attach($assignee->id);

        $outside = $this->seedTask($owner, $column, 'Outside', [
            'due_date' => Carbon::now()->addDays(5)->toDateString(),
        ]);
        $outside->assignees()->attach($assignee->id);

        $this->artisan('notifications:task-due-soon')->assertExitCode(0);

        $rows = Notification::query()
            ->where('user_id', $assignee->id)
            ->where('type', NotificationType::TaskDueSoon->value)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($inWindow->id, (int) $rows[0]->resource_id);
        $this->assertStringContainsString('tomorrow', $rows[0]->title);
    }

    public function test_task_due_soon_dedup_skips_recent_duplicates(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $task = $this->seedTask($owner, $column, 'Dup', [
            'due_date' => Carbon::now()->addDay()->toDateString(),
        ]);
        $task->assignees()->attach($assignee->id);

        // Pre-seed a recent due-soon row — the cron must skip it.
        Notification::create([
            'user_id' => $assignee->id,
            'type' => NotificationType::TaskDueSoon->value,
            'title' => 'Old ping',
            'resource_type' => 'task',
            'resource_id' => $task->id,
            'metadata' => [],
            'is_read' => false,
            'created_at' => Carbon::now()->subHours(2),
        ]);

        $this->artisan('notifications:task-due-soon')->assertExitCode(0);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $assignee->id)
                ->where('type', NotificationType::TaskDueSoon->value)
                ->count(),
        );
    }

    public function test_task_due_soon_skips_completed_and_archived(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $this->seedTask($owner, $column, 'Completed', [
            'due_date' => Carbon::now()->addDay()->toDateString(),
            'is_completed' => true,
        ])->assignees()->attach($assignee->id);

        $this->seedTask($owner, $column, 'Archived', [
            'due_date' => Carbon::now()->addDay()->toDateString(),
            'is_archived' => true,
        ])->assignees()->attach($assignee->id);

        $this->artisan('notifications:task-due-soon')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->where('user_id', $assignee->id)->count());
    }

    public function test_task_overdue_notifies_for_past_due_tasks(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $overdue = $this->seedTask($owner, $column, 'Late', [
            'due_date' => Carbon::now()->subDays(2)->toDateString(),
        ]);
        $overdue->assignees()->attach($assignee->id);

        // Same-day due must NOT be overdue.
        $today = $this->seedTask($owner, $column, 'Today', [
            'due_date' => Carbon::now()->toDateString(),
        ]);
        $today->assignees()->attach($assignee->id);

        $this->artisan('notifications:task-overdue')->assertExitCode(0);

        $rows = Notification::query()
            ->where('user_id', $assignee->id)
            ->where('type', NotificationType::TaskOverdue->value)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame($overdue->id, (int) $rows[0]->resource_id);
    }

    public function test_task_overdue_dedup_window_is_3_days(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $task = $this->seedTask($owner, $column, 'Late', [
            'due_date' => Carbon::now()->subDays(5)->toDateString(),
        ]);
        $task->assignees()->attach($assignee->id);

        // Pre-seed a 2-day-old overdue row — still inside the 3-day
        // window, cron must skip.
        Notification::create([
            'user_id' => $assignee->id,
            'type' => NotificationType::TaskOverdue->value,
            'title' => 'Older ping',
            'resource_type' => 'task',
            'resource_id' => $task->id,
            'metadata' => [],
            'is_read' => false,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        $this->artisan('notifications:task-overdue')->assertExitCode(0);

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $assignee->id)
                ->where('type', NotificationType::TaskOverdue->value)
                ->count(),
        );

        // A 4-day-old row is outside the window; a new notification should land.
        Notification::query()->where('user_id', $assignee->id)->delete();
        Notification::create([
            'user_id' => $assignee->id,
            'type' => NotificationType::TaskOverdue->value,
            'title' => 'Very old ping',
            'resource_type' => 'task',
            'resource_id' => $task->id,
            'metadata' => [],
            'is_read' => false,
            'created_at' => Carbon::now()->subDays(4),
        ]);

        $this->artisan('notifications:task-overdue')->assertExitCode(0);

        $this->assertSame(
            2,
            Notification::query()
                ->where('user_id', $assignee->id)
                ->where('type', NotificationType::TaskOverdue->value)
                ->count(),
        );
    }

    public function test_expense_due_soon_notifies_every_bucket_viewer(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        // Pattern B user — direct bucket grant, no project-level row.
        $patternB = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $patternB->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $bucket->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        // Outsider with no access — must NOT get a notification.
        $outsider = UserFactory::create();

        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'AWS invoice',
            'category' => ExpenseCategory::Hosting->value,
            'amount' => 120.5,
            'currency' => 'USD',
            'billing_cycle' => BillingCycle::Monthly->value,
            'vendor' => 'AWS',
            'next_due_date' => Carbon::now()->addDays(2)->toDateString(),
            'created_by' => $owner->id,
        ]);

        $this->artisan('notifications:expense-due-soon')->assertExitCode(0);

        $this->assertTrue(
            Notification::query()
                ->where('user_id', $owner->id)
                ->where('resource_id', $expense->id)
                ->exists(),
        );
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $patternB->id)
                ->where('resource_id', $expense->id)
                ->exists(),
        );
        $this->assertFalse(
            Notification::query()
                ->where('user_id', $outsider->id)
                ->exists(),
        );

        $row = Notification::query()->where('user_id', $patternB->id)->firstOrFail();
        $this->assertStringContainsString('AWS', $row->body);
        $this->assertSame($bucket->id, (int) $row->metadata['bucket_id']);
    }

    /**
     * @return array{0: User, 1: TaskBoard, 2: TaskColumn}
     */
    private function boardWithColumn(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->first();

        return [$owner, $board, $column];
    }

    private function seedTask(User $owner, TaskColumn $column, string $title, array $overrides = []): TaskItem
    {
        return TaskItem::create(array_merge([
            'project_id' => $column->board->project_id,
            'column_id' => $column->id,
            'title' => $title,
            'priority' => 'medium',
            'position' => 10000,
            'created_by' => $owner->id,
        ], $overrides));
    }
}

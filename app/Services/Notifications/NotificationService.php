<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Events\NotificationCreated;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Notification;
use App\Models\Project\Project;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Single write path for the notifications plane.
 *
 * Denormalization rule (spec §3): actor_name, workspace_name, and
 * project_name are snapshotted at write time. Renaming a project later
 * must not rewrite notification titles/context.
 *
 * Never notify the actor about their own actions — callers pass the
 * actor and a recipient list; this service strips the actor from the
 * recipients before inserting.
 *
 * Cron dedup (spec §4): system-generated notifications (no actor) check
 * for an existing row on the same (user, type, resource) within a type-
 * specific window before inserting. `recordIfNotRecent()` returns null
 * when a row already exists in the window.
 */
class NotificationService
{
    /**
     * Create one notification row and broadcast it on the user's private
     * channel. Returns the created row, or null if it was skipped.
     */
    public function create(
        int $userId,
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?User $actor = null,
        ?Organisation $workspace = null,
        ?Project $project = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        array $metadata = [],
    ): ?Notification {
        // Spec §2 — skip self-notification. Returning null (rather than
        // throwing) keeps the callsite one-liner: no special-casing for
        // "is the actor also the recipient".
        if ($actor !== null && (int) $actor->id === $userId) {
            return null;
        }

        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type->value,
            'title' => $title,
            'body' => $body,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'workspace_id' => $workspace?->id,
            'workspace_name' => $workspace?->name,
            'project_id' => $project?->id,
            'project_name' => $project?->name,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'metadata' => $metadata,
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);

        NotificationCreated::dispatch($notification);

        return $notification;
    }

    /**
     * Create a notification for many recipients in one pass. Silently
     * drops the actor from the recipient list and swallows duplicate
     * recipient IDs.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, Notification>
     */
    public function createMany(
        array $userIds,
        NotificationType $type,
        string $title,
        ?string $body = null,
        ?User $actor = null,
        ?Organisation $workspace = null,
        ?Project $project = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        array $metadata = [],
    ): array {
        $targets = array_values(array_unique(array_map('intval', $userIds)));

        if ($actor !== null) {
            $targets = array_values(array_filter(
                $targets,
                static fn (int $id): bool => $id !== (int) $actor->id,
            ));
        }

        $out = [];
        foreach ($targets as $uid) {
            $row = $this->create(
                userId: $uid,
                type: $type,
                title: $title,
                body: $body,
                actor: $actor,
                workspace: $workspace,
                project: $project,
                resourceType: $resourceType,
                resourceId: $resourceId,
                metadata: $metadata,
            );

            if ($row !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Cron-safe create: insert only when no row exists for the same
     * (user, type, resource) within $within. Used by due-soon/overdue
     * jobs (spec §4). Returns the row when inserted, null when skipped.
     */
    public function createIfNotRecent(
        int $userId,
        NotificationType $type,
        string $resourceType,
        int $resourceId,
        \DateInterval $within,
        string $title,
        ?string $body = null,
        ?Organisation $workspace = null,
        ?Project $project = null,
        array $metadata = [],
    ): ?Notification {
        $threshold = Carbon::now()->sub($within);

        $exists = Notification::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->where('created_at', '>=', $threshold)
            ->exists();

        if ($exists) {
            return null;
        }

        return $this->create(
            userId: $userId,
            type: $type,
            title: $title,
            body: $body,
            workspace: $workspace,
            project: $project,
            resourceType: $resourceType,
            resourceId: $resourceId,
            metadata: $metadata,
        );
    }

    /**
     * Relative-date helper for title templates (spec §5). Computed once
     * at write time — "tomorrow" today is still "tomorrow" in the
     * stored title a week later, which is a feature, not a bug: the
     * notification describes a point-in-time event.
     */
    public function relativeDate(Carbon $target, ?Carbon $now = null): string
    {
        $nowDay = ($now ?? Carbon::now())->copy()->startOfDay();
        $targetDay = $target->copy()->startOfDay();

        // Cast to int explicitly — Carbon 3 returns floats from
        // diffInDays, which tripped strict === matches in earlier
        // versions of this method.
        $days = (int) round($nowDay->diffInDays($targetDay, false));

        return match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            -1 => 'yesterday',
            default => $targetDay->toDateString(),
        };
    }

    /**
     * Truncate a comment body to 100 chars for the notification body
     * (spec §3, type task_commented). Uses Str::limit's default "..."
     * ellipsis but capped at 100 total — callers can display the full
     * comment via the comment_id in metadata.
     */
    public function truncateCommentBody(string $body): string
    {
        if (mb_strlen($body) <= 100) {
            return $body;
        }

        return mb_substr($body, 0, 97).'...';
    }

    /**
     * Resolve the project + workspace (organisation) pair for a task.
     * Used by HTTP triggers so callers don't have to wire up both.
     *
     * @return array{0: Project, 1: ?Organisation}
     */
    public function projectContextFor(TaskItem $task): array
    {
        $project = Project::query()->whereKey($task->project_id)->firstOrFail();
        $workspace = Organisation::query()->whereKey($project->organisation_id)->first();

        return [$project, $workspace];
    }

    /**
     * Resolve the board name + column name for a task (used by cron jobs
     * that template `{board_name}` and `{column_name}` into titles).
     *
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    public function boardContextFor(TaskItem $task): array
    {
        $column = TaskColumn::query()->whereKey($task->column_id)->first();
        $board = $column?->board;

        return [$board?->name, $column?->name, $board?->id];
    }

    /**
     * Resolve every user who can view an expense bucket — the recipient
     * list for `expense_due_soon`. That's the project owner + anyone
     * with a project-level grant + anyone with a direct bucket grant.
     * Returns distinct user_ids.
     *
     * @return array<int, int>
     */
    public function bucketViewerIds(ExpenseBucket $bucket): array
    {
        $project = Project::query()->whereKey($bucket->project_id)->first();
        if ($project === null) {
            return [];
        }

        $ids = [(int) $project->owner_id];

        $rows = \DB::table('resource_permissions')
            ->where('project_id', $project->id)
            ->where(function ($q) use ($bucket): void {
                $q->where('resource_type', 'project')
                    ->orWhere(function ($inner) use ($bucket): void {
                        $inner->where('resource_type', 'bucket')
                            ->where('resource_id', $bucket->id);
                    });
            })
            ->pluck('user_id');

        foreach ($rows as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Expense convenience for cron: title + body + workspace/project.
     *
     * @return array{title:string, body:string, workspace:?Organisation, project:?Project}
     */
    public function expenseDueContext(Expense $expense, Carbon $now): array
    {
        $project = Project::query()->whereKey($expense->project_id)->first();
        $workspace = $project !== null
            ? Organisation::query()->whereKey($project->organisation_id)->first()
            : null;

        $relative = $this->relativeDate(Carbon::parse($expense->next_due_date), $now);

        $body = number_format((float) $expense->amount, 2).' '.$expense->currency;
        if (! empty($expense->vendor)) {
            $body .= ' · '.$expense->vendor;
        }

        return [
            'title' => '"'.$expense->name.'" is due '.$relative,
            'body' => $body,
            'workspace' => $workspace,
            'project' => $project,
        ];
    }
}

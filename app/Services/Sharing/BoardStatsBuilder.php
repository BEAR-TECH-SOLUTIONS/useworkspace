<?php

namespace App\Services\Sharing;

use App\Enums\ActivityAction;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds the optional `stats` block for a BoardShareSnapshot. Computed
 * once at share-create time and frozen — does NOT refresh as the
 * underlying board changes. Universal Share Links — Progress Stats addendum.
 */
class BoardStatsBuilder
{
    public const RECENT_ACTIVITY_CAP = 20;

    /**
     * @return array<string, mixed>
     */
    public function build(TaskBoard $board, string $timezone): array
    {
        $generatedAt = Carbon::now();
        $todayStart = Carbon::now($timezone)->startOfDay()->utc();
        $todayEnd = Carbon::now($timezone)->endOfDay()->utc();
        $weekStart = (clone $todayEnd)->copy()->subDays(7);

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'timezone' => $timezone,
            'totals' => $this->totals($board),
            'today' => $this->dayCounts($board, $todayStart, $todayEnd),
            'this_week' => $this->weekCounts($board, $weekStart, $todayEnd),
            'recent_activity' => $this->recentActivity($board, $timezone),
        ];
    }

    /**
     * Pick the resolver-chained timezone string (project tz → user tz →
     * UTC). The chained columns may not exist yet in the schema — Eloquent
     * silently returns null for missing attributes, which the chain handles.
     */
    public function resolveTimezone(TaskBoard $board, User $sharer): string
    {
        $candidates = [
            $board->project?->default_timezone ?? null,
            $sharer->timezone ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                try {
                    new \DateTimeZone($candidate);

                    return $candidate;
                } catch (\Throwable) {
                    // Bad data shouldn't crash share creation — fall through.
                }
            }
        }

        return 'UTC';
    }

    /**
     * @return array<string, int>
     */
    private function totals(TaskBoard $board): array
    {
        $columnIds = TaskColumn::query()
            ->where('board_id', $board->id)
            ->orderBy('position')
            ->pluck('id', 'position');

        $terminalColumnId = $columnIds->count() > 1
            ? $columnIds->last()
            : null;

        $base = TaskItem::query()
            ->whereIn('column_id', $columnIds->values())
            ->where('is_archived', false);

        $total = (clone $base)->count();
        $completed = (clone $base)->where('is_completed', true)->count();
        $today = Carbon::now()->startOfDay();
        $overdue = (clone $base)
            ->where('is_completed', false)
            ->whereNotNull('due_date')
            ->where('due_date', '<', $today)
            ->count();

        // Single-column boards get the spec's fallback: in_progress = total - completed.
        if ($terminalColumnId === null) {
            $inProgress = $total - $completed;
        } else {
            $inProgress = (clone $base)
                ->where('is_completed', false)
                ->where('column_id', '!=', $terminalColumnId)
                ->count();
        }

        return [
            'total_tasks' => $total,
            'completed' => $completed,
            'in_progress' => $inProgress,
            'overdue' => $overdue,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function dayCounts(TaskBoard $board, Carbon $start, Carbon $end): array
    {
        return [
            'completed' => $this->distinctTaskCount($board, ActivityAction::Completed, $start, $end),
            'created' => $this->distinctTaskCount($board, ActivityAction::Created, $start, $end),
            'moved' => $this->distinctTaskCount($board, ActivityAction::Moved, $start, $end),
            'commented' => $this->distinctTaskCount($board, ActivityAction::Commented, $start, $end),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function weekCounts(TaskBoard $board, Carbon $start, Carbon $end): array
    {
        return [
            'completed' => $this->distinctTaskCount($board, ActivityAction::Completed, $start, $end),
            'created' => $this->distinctTaskCount($board, ActivityAction::Created, $start, $end),
        ];
    }

    private function distinctTaskCount(TaskBoard $board, ActivityAction $action, Carbon $start, Carbon $end): int
    {
        return TaskActivity::query()
            ->where('board_id', $board->id)
            ->where('action', $action->value)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('task_item_id')
            ->distinct('task_item_id')
            ->count('task_item_id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(TaskBoard $board, string $timezone): array
    {
        $relevant = [
            ActivityAction::Created->value,
            ActivityAction::Completed->value,
            ActivityAction::Moved->value,
            ActivityAction::Commented->value,
        ];

        // Pull a buffer larger than the cap so the comment-collapse step
        // can't accidentally starve the feed.
        $rows = TaskActivity::query()
            ->with(['user:id,name'])
            ->where('board_id', $board->id)
            ->whereIn('action', $relevant)
            ->whereNotNull('task_item_id')
            ->orderByDesc('created_at')
            ->limit(self::RECENT_ACTIVITY_CAP * 4)
            ->get();

        $taskIds = $rows->pluck('task_item_id')->unique()->all();
        $titles = TaskItem::query()
            ->whereIn('id', $taskIds)
            ->pluck('title', 'id');

        $columnIds = $rows->pluck('meta.to_column_id')->filter()->unique()->all();
        $columnNames = TaskColumn::query()->whereIn('id', $columnIds)->pluck('name', 'id');

        $items = [];
        $commentBuckets = [];

        foreach ($rows as $row) {
            $taskId = (int) $row->task_item_id;
            $at = $row->created_at;
            $atLocal = $at?->copy()->setTimezone($timezone);
            $dayKey = $atLocal?->toDateString() ?? 'unknown';

            if ($row->action->value === ActivityAction::Commented->value) {
                $key = $taskId.'@'.$dayKey;
                if (! isset($commentBuckets[$key])) {
                    $commentBuckets[$key] = [
                        'task_id' => $taskId,
                        'task_title' => (string) ($titles[$taskId] ?? ''),
                        'count' => 0,
                        'latest_at' => $at,
                        'actor_name' => $row->user?->name,
                    ];
                }
                $commentBuckets[$key]['count']++;
                if ($at !== null && $commentBuckets[$key]['latest_at']?->lt($at)) {
                    $commentBuckets[$key]['latest_at'] = $at;
                    $commentBuckets[$key]['actor_name'] = $row->user?->name;
                }

                continue;
            }

            $items[] = [
                'type' => $row->action->value,
                'task_id' => $taskId,
                'task_title' => (string) ($titles[$taskId] ?? ''),
                'actor_name' => $row->user?->name,
                'at' => $at?->toIso8601String(),
                'detail' => $row->action->value === ActivityAction::Moved->value
                    ? ($columnNames[$row->meta['to_column_id'] ?? null] ?? null)
                    : null,
            ];
        }

        foreach ($commentBuckets as $bucket) {
            $items[] = [
                'type' => ActivityAction::Commented->value,
                'task_id' => $bucket['task_id'],
                'task_title' => $bucket['task_title'],
                'actor_name' => $bucket['actor_name'],
                'at' => $bucket['latest_at']?->toIso8601String(),
                'detail' => $bucket['count'].' '.($bucket['count'] === 1 ? 'comment' : 'comments'),
            ];
        }

        usort($items, static fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, self::RECENT_ACTIVITY_CAP);
    }
}

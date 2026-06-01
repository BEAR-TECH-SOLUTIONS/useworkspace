<?php

namespace App\Http\Resources\Sharing;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;

/**
 * Frozen JSON snapshot of a TaskBoard for a public share link.
 *
 * Excludes comments, checklists, activities, assignee user_ids/emails,
 * and `created_by`. Display names only. Per CLAUDE.md §10's
 * "no comments, no checklists" rule.
 */
final class BoardShareSnapshot
{
    /**
     * @param  array<string, mixed>|null  $stats  Optional progress-stats block (Universal Share Links — Progress Stats addendum). Computed by BoardStatsBuilder; passed through verbatim.
     * @return array<string, mixed>
     */
    public static function forResource(TaskBoard $board, ?array $stats = null): array
    {
        $board->load([
            'columns.items.labels:id,name,color',
            'columns.items.assignees:id,name',
        ]);

        $columns = $board->columns
            ->sortBy('position')
            ->values()
            ->map(fn ($column): array => [
                'id' => (int) $column->id,
                'name' => (string) $column->name,
                'color' => $column->color,
                'position' => (float) $column->position,
                'items' => $column->items
                    ->sortBy('position')
                    ->values()
                    ->map(fn (TaskItem $item): array => [
                        'id' => (int) $item->id,
                        'title' => (string) $item->title,
                        'description' => $item->description,
                        'priority' => $item->priority?->value,
                        'position' => (float) $item->position,
                        'due_date' => $item->due_date?->toDateString(),
                        'is_completed' => (bool) $item->is_completed,
                        'label_ids' => $item->labels->pluck('id')->map(fn ($id) => (int) $id)->all(),
                        'assignee_names' => $item->assignees->pluck('name')->all(),
                    ])->all(),
            ])->all();

        $labels = collect();
        foreach ($board->columns as $column) {
            foreach ($column->items as $item) {
                foreach ($item->labels as $label) {
                    $labels->put($label->id, [
                        'id' => (int) $label->id,
                        'name' => (string) $label->name,
                        'color' => $label->color,
                    ]);
                }
            }
        }

        $payload = [
            'id' => (int) $board->id,
            'name' => (string) $board->name,
            'description' => $board->description,
            'columns' => $columns,
            'labels' => $labels->values()->all(),
        ];

        if ($stats !== null) {
            $payload['stats'] = $stats;
        }

        return $payload;
    }
}

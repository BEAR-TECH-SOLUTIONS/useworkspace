<?php

namespace App\Http\Resources\Sharing;

use App\Models\Tasks\TaskItem;

/**
 * Frozen JSON snapshot of a TaskItem for a public share link.
 *
 * Includes read-only checklist items (per spec v2). Excludes comments,
 * activities, assignee user_ids, parent column list. Display names only.
 */
final class TaskShareSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function forResource(TaskItem $task): array
    {
        $task->load([
            'column:id,name',
            'labels:id,name,color',
            'assignees:id,name',
            'checklists:id,task_item_id,text,is_checked,position',
        ]);

        return [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
            'description' => $task->description,
            'priority' => $task->priority?->value,
            'due_date' => $task->due_date?->toDateString(),
            'is_completed' => (bool) $task->is_completed,
            'column_name' => $task->column?->name,
            'label_ids' => $task->labels->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'assignee_names' => $task->assignees->pluck('name')->all(),
            'checklists' => $task->checklists
                ->sortBy('position')
                ->values()
                ->map(fn ($cl): array => [
                    'text' => (string) $cl->text,
                    'is_checked' => (bool) $cl->is_checked,
                    'position' => (float) $cl->position,
                ])->all(),
        ];
    }
}

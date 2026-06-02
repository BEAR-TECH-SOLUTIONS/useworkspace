<?php

namespace App\Services\Activity;

use App\Enums\ActivityAction;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the immutable audit trail described in CLAUDE.md §9.
 *
 * Caller responsibilities:
 *   - Run inside the same DB transaction as the underlying mutation.
 *   - Pass the mutation's actor explicitly (no facades, no Auth::user()).
 */
class ActivityService
{
    /**
     * Record a single action against a task or board.
     *
     * @param  array<string, mixed>  $meta
     */
    public function record(
        User $actor,
        Model $subject,
        ActivityAction $action,
        array $meta = [],
        ?string $field = null,
        mixed $old = null,
        mixed $new = null,
    ): TaskActivity {
        return TaskActivity::create([
            'project_id' => $subject->project_id,
            'board_id' => $this->boardIdFor($subject),
            'task_item_id' => $subject instanceof TaskItem ? $subject->id : null,
            'user_id' => $actor->id,
            'action' => $action->value,
            'field' => $field,
            'old_value' => $this->stringify($old),
            'new_value' => $this->stringify($new),
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    /**
     * Diff a TaskItem update payload and emit one row per non-trivial changed field.
     * Maps `column_id` → ActivityAction::Moved with from/to meta.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $changed
     */
    public function recordTaskUpdate(User $actor, TaskItem $task, array $original, array $changed): void
    {
        $tracked = ['title', 'description', 'priority', 'due_date', 'is_completed', 'column_id'];

        foreach ($tracked as $field) {
            if (! array_key_exists($field, $changed)) {
                continue;
            }

            $old = $original[$field] ?? null;
            $new = $changed[$field];

            if ($this->stringify($old) === $this->stringify($new)) {
                continue;
            }

            if ($field === 'column_id') {
                $this->record($actor, $task, ActivityAction::Moved, [
                    'from_column_id' => $old,
                    'to_column_id' => $new,
                ]);

                continue;
            }

            if ($field === 'is_completed') {
                $this->record(
                    $actor,
                    $task,
                    $new ? ActivityAction::Completed : ActivityAction::Reopened,
                );

                continue;
            }

            $this->record($actor, $task, ActivityAction::Updated, field: $field, old: $old, new: $new);
        }
    }

    /**
     * Record a share-link lifecycle action against a board or task.
     *
     * Returns null when the subject isn't a TaskBoard / TaskItem; the
     * caller is expected to fall back to AuditLogger for those cases
     * (credential/doc/expense shares — see Universal Share Links plan §8).
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordShare(User $actor, Model $subject, ActivityAction $action, array $meta = []): ?TaskActivity
    {
        if (! $subject instanceof TaskBoard && ! $subject instanceof TaskItem) {
            return null;
        }

        return $this->record($actor, $subject, $action, $meta);
    }

    private function boardIdFor(Model $subject): ?int
    {
        if ($subject instanceof TaskBoard) {
            return $subject->id;
        }

        if ($subject instanceof TaskItem) {
            return $subject->column?->board_id ?? $subject->loadMissing('column')->column?->board_id;
        }

        return null;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}

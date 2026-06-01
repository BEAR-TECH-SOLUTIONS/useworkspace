<?php

namespace Tests\Feature\Tasks;

use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskItemCrudTest extends TestCase
{
    public function test_owner_can_create_task_with_default_priority(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/task-items", [
                'column_id' => $column->id,
                'title' => 'Wire up reverb',
            ]);

        $response->assertCreated()
            ->assertJsonPath('task.title', 'Wire up reverb')
            ->assertJsonPath('task.priority', 'medium');

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $response->json('task.id'),
            'action' => 'created',
        ]);
    }

    public function test_update_emits_one_activity_row_per_changed_field(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $task = $this->seedTask($owner, $column);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-items/{$task->id}", [
                'title' => 'Renamed',
                'priority' => 'high',
            ])
            ->assertOk();

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'updated',
            'field' => 'title',
            'old_value' => 'Original',
            'new_value' => 'Renamed',
        ]);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'updated',
            'field' => 'priority',
            'old_value' => 'medium',
            'new_value' => 'high',
        ]);
    }

    public function test_completing_task_emits_completed_activity_and_sets_completed_at(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $task = $this->seedTask($owner, $column);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-items/{$task->id}", ['is_completed' => true])
            ->assertOk();

        $this->assertNotNull(TaskItem::find($task->id)->completed_at);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'completed',
        ]);
    }

    public function test_move_records_moved_with_meta(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $task = $this->seedTask($owner, $column);

        $other = TaskColumn::create(['board_id' => $board->id, 'name' => 'Done', 'position' => 99999]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/move", [
                'column_id' => $other->id,
                'position' => 12345,
            ])
            ->assertOk()
            ->assertJsonPath('task.column_id', $other->id);

        $row = TaskActivity::query()
            ->where('task_item_id', $task->id)
            ->where('action', 'moved')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($column->id, $row->meta['from_column_id']);
        $this->assertSame($other->id, $row->meta['to_column_id']);
    }

    public function test_archive_toggles_and_records_activity(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $task = $this->seedTask($owner, $column);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/archive")
            ->assertOk()
            ->assertJsonPath('task.is_archived', true);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'archived',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/archive")
            ->assertOk()
            ->assertJsonPath('task.is_archived', false);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'unarchived',
        ]);
    }

    public function test_outsider_cannot_create_task(): void
    {
        [, $board, $column] = $this->boardWithColumn();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/task-boards/{$board->id}/task-items", [
                'column_id' => $column->id,
                'title' => 'no',
            ])
            ->assertForbidden();
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

    private function seedTask(User $owner, TaskColumn $column): TaskItem
    {
        return TaskItem::create([
            'project_id' => $column->board->project_id,
            'column_id' => $column->id,
            'title' => 'Original',
            'priority' => 'medium',
            'position' => 10000,
            'created_by' => $owner->id,
        ]);
    }
}

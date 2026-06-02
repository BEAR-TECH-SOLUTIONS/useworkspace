<?php

namespace Tests\Feature\Tasks;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskChecklist;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskChecklistTest extends TestCase
{
    public function test_owner_can_add_checklist_item(): void
    {
        [$owner, $task] = $this->setupTask();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/checklists", [
                'text' => 'Write docs',
            ]);

        $response->assertCreated()
            ->assertJsonPath('checklist.text', 'Write docs')
            ->assertJsonPath('checklist.is_checked', false);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'checklist_added',
        ]);
    }

    public function test_toggling_checklist_records_checked_activity(): void
    {
        [$owner, $task] = $this->setupTask();
        $checklist = TaskChecklist::create([
            'task_item_id' => $task->id,
            'text' => 'Ship it',
            'position' => 10000,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-checklists/{$checklist->id}", ['is_checked' => true])
            ->assertOk()
            ->assertJsonPath('checklist.is_checked', true);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'checklist_checked',
        ]);
    }

    public function test_outsider_cannot_add_checklist(): void
    {
        [, $task] = $this->setupTask();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/task-items/{$task->id}/checklists", ['text' => 'no'])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: TaskItem}
     */
    private function setupTask(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->first();
        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Seed',
            'priority' => 'medium',
            'position' => 10000,
            'created_by' => $owner->id,
        ]);

        return [$owner, $task];
    }
}

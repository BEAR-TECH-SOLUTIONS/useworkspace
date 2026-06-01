<?php

namespace Tests\Feature\Tasks;

use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskAssigneeTest extends TestCase
{
    public function test_owner_can_assign_project_member(): void
    {
        [$owner, $task, $member] = $this->seedWithMember();

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/assignees/{$member->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('task_assignees', [
            'task_item_id' => $task->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'assigned',
        ]);
    }

    public function test_unassign_removes_row_and_records_activity(): void
    {
        [$owner, $task, $member] = $this->seedWithMember();

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/assignees/{$member->id}")
            ->assertNoContent();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-items/{$task->id}/assignees/{$member->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_assignees', [
            'task_item_id' => $task->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'unassigned',
        ]);
    }

    public function test_cannot_assign_non_member(): void
    {
        [$owner, $task] = $this->setupTask();
        $stranger = UserFactory::create();

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/assignees/{$stranger->id}")
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: TaskItem, 2: User}
     */
    private function seedWithMember(): array
    {
        [$owner, $task] = $this->setupTask();

        $member = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => 'project',
            'resource_id' => $task->project_id,
            'project_id' => $task->project_id,
            'role' => 'editor',
            'granted_by' => $owner->id,
        ]);

        return [$owner, $task, $member];
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

<?php

namespace Tests\Feature\Tasks;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskActivityEndpointTest extends TestCase
{
    public function test_task_activity_endpoint_returns_recent_activity(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->first();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/task-items", [
                'column_id' => $column->id,
                'title' => 'Activity test',
            ])
            ->assertCreated();

        $taskId = $response->json('task.id');

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-items/{$taskId}", ['title' => 'Renamed'])
            ->assertOk();

        $activities = $this->actingAs($owner)
            ->getJson("/api/v1/task-items/{$taskId}/activities")
            ->assertOk()
            ->json('data');

        $actions = collect($activities)->pluck('action')->all();

        $this->assertContains('created', $actions);
        $this->assertContains('updated', $actions);
    }

    public function test_board_activity_endpoint_lists_recent_actions(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-boards/{$board->id}", ['name' => 'Refreshed'])
            ->assertOk();

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/activities")
            ->assertOk();

        $this->assertNotEmpty($response->json('data'));
        $this->assertContains('updated', collect($response->json('data'))->pluck('action')->all());
    }

    public function test_outsider_cannot_view_task_activity(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->first();
        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Hidden',
            'priority' => 'medium',
            'position' => 10000,
            'created_by' => $owner->id,
        ]);

        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/task-items/{$task->id}/activities")
            ->assertForbidden();
    }
}

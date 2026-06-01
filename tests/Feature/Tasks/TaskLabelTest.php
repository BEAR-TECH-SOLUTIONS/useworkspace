<?php

namespace Tests\Feature\Tasks;

use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskLabel;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskLabelTest extends TestCase
{
    public function test_owner_can_crud_labels(): void
    {
        [$owner, $project] = $this->seedProject();

        $create = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/task-labels", [
                'name' => 'Bug',
                'color' => '#ff0000',
            ])
            ->assertCreated()
            ->assertJsonPath('label.name', 'Bug');

        $labelId = $create->json('label.id');

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-labels/{$labelId}", ['color' => '#00ff00'])
            ->assertOk()
            ->assertJsonPath('label.color', '#00ff00');

        $names = collect(
            $this->actingAs($owner)->getJson("/api/v1/projects/{$project->id}/task-labels")->json('data')
        )->pluck('name')->all();
        $this->assertContains('Bug', $names);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-labels/{$labelId}")
            ->assertNoContent();
    }

    public function test_attach_and_detach_label_records_activity(): void
    {
        [$owner, $project, $task] = $this->seedTask();

        $label = TaskLabel::create(['project_id' => $project->id, 'name' => 'Urgent', 'color' => '#ff0000']);

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/labels/{$label->id}")
            ->assertNoContent();

        $this->assertDatabaseHas('task_item_labels', [
            'task_item_id' => $task->id,
            'label_id' => $label->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'labeled',
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-items/{$task->id}/labels/{$label->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_item_labels', [
            'task_item_id' => $task->id,
            'label_id' => $label->id,
        ]);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'unlabeled',
        ]);
    }

    public function test_attach_rejects_label_from_different_project(): void
    {
        [$owner, , $task] = $this->seedTask();

        $otherProject = ProjectFactory::forOwner($owner);
        $foreignLabel = TaskLabel::create([
            'project_id' => $otherProject->id,
            'name' => 'Foreign',
            'color' => '#123456',
        ]);

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/labels/{$foreignLabel->id}")
            ->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: Project}
     */
    private function seedProject(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        return [$owner, $project];
    }

    /**
     * @return array{0: User, 1: Project, 2: TaskItem}
     */
    private function seedTask(): array
    {
        [$owner, $project] = $this->seedProject();
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

        return [$owner, $project, $task];
    }
}

<?php

namespace Tests\Feature\Tasks;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskColumnCrudTest extends TestCase
{
    public function test_owner_can_create_column(): void
    {
        [$owner, $board] = $this->boardFor();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/columns", [
                'name' => 'In review',
                'color' => '#aabbcc',
            ]);

        $response->assertCreated()->assertJsonPath('column.name', 'In review');

        $this->assertDatabaseHas('task_activities', [
            'board_id' => $board->id,
            'action' => 'column_created',
        ]);
    }

    public function test_owner_can_rename_column_and_activity_logs_old_and_new(): void
    {
        [$owner, $board] = $this->boardFor();
        $column = $board->columns()->orderBy('position')->first();

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-columns/{$column->id}", ['name' => 'Triage'])
            ->assertOk()
            ->assertJsonPath('column.name', 'Triage');

        $this->assertDatabaseHas('task_activities', [
            'board_id' => $board->id,
            'action' => 'column_renamed',
            'field' => 'name',
            'old_value' => 'To do',
            'new_value' => 'Triage',
        ]);
    }

    public function test_owner_can_reorder_columns(): void
    {
        [$owner, $board] = $this->boardFor();
        $columns = $board->columns()->orderBy('position')->get();

        $payload = [
            'positions' => [
                ['id' => $columns[0]->id, 'position' => 50000],
                ['id' => $columns[1]->id, 'position' => 40000],
                ['id' => $columns[2]->id, 'position' => 30000],
            ],
        ];

        $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/columns/reorder", $payload)
            ->assertOk();

        $this->assertSame(50000.0, (float) TaskColumn::find($columns[0]->id)->position);
        $this->assertSame(30000.0, (float) TaskColumn::find($columns[2]->id)->position);
    }

    public function test_owner_can_delete_column(): void
    {
        [$owner, $board] = $this->boardFor();
        $column = TaskColumn::create(['board_id' => $board->id, 'name' => 'Throwaway', 'position' => 99999]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-columns/{$column->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_columns', ['id' => $column->id]);

        $this->assertDatabaseHas('task_activities', [
            'board_id' => $board->id,
            'action' => 'column_deleted',
        ]);
    }

    public function test_outsider_cannot_create_column(): void
    {
        [, $board] = $this->boardFor();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/task-boards/{$board->id}/columns", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: TaskBoard}
     */
    private function boardFor(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        return [$owner, $board];
    }
}

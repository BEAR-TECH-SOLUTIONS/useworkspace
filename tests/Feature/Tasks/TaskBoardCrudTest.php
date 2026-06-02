<?php

namespace Tests\Feature\Tasks;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskChecklist;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskLabel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskBoardCrudTest extends TestCase
{
    public function test_owner_can_create_board_and_activity_is_recorded(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/task-boards", [
                'name' => 'Sprint 42',
                'description' => 'Live board',
            ]);

        $response->assertCreated()->assertJsonPath('board.name', 'Sprint 42');

        $boardId = $response->json('board.id');

        $this->assertDatabaseHas('task_activities', [
            'board_id' => $boardId,
            'action' => 'created',
            'user_id' => $owner->id,
        ]);
    }

    public function test_outsider_cannot_create_board(): void
    {
        $owner = UserFactory::create();
        $outsider = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $this->actingAs($outsider)
            ->postJson("/api/v1/projects/{$project->id}/task-boards", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_index_lists_only_visible_boards(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $boardA = TaskBoard::create(['project_id' => $project->id, 'name' => 'A', 'created_by' => $owner->id]);
        $boardB = TaskBoard::create(['project_id' => $project->id, 'name' => 'B', 'created_by' => $owner->id]);

        // Scoped user can only see boardB.
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $boardB->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);
        // Scoped user must also have a project-level view to hit the index endpoint.
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        // Project-level Viewer cascades view to all child boards as well, so the
        // scoped user actually sees every board. Verified explicitly here so the
        // cascade rule from §5 doesn't regress.
        $ids = collect($this->actingAs($scoped)->getJson("/api/v1/projects/{$project->id}/task-boards")->json('data'))
            ->pluck('id')->all();

        $this->assertContains($boardA->id, $ids);
        $this->assertContains($boardB->id, $ids);
    }

    public function test_owner_can_update_board(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::create(['project_id' => $project->id, 'name' => 'Old', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-boards/{$board->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('board.name', 'New');

        $this->assertDatabaseHas('task_activities', [
            'board_id' => $board->id,
            'action' => 'updated',
            'field' => 'name',
            'old_value' => 'Old',
            'new_value' => 'New',
        ]);
    }

    public function test_default_board_cannot_be_deleted(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $defaultBoard = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-boards/{$defaultBoard->id}")
            ->assertStatus(422);
    }

    public function test_show_hydrates_task_relations_without_n_plus_one(): void
    {
        $owner = UserFactory::create();
        $assigneeA = UserFactory::create();
        $assigneeB = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $label = TaskLabel::create([
            'project_id' => $project->id,
            'name' => 'Backend',
            'color' => '#abcdef',
        ]);

        // Seed multiple columns × multiple tasks × relations so any N+1 bug
        // would multiply the query count.
        foreach ($board->columns()->orderBy('position')->get() as $colIndex => $column) {
            for ($i = 0; $i < 3; $i++) {
                $task = TaskItem::create([
                    'project_id' => $project->id,
                    'column_id' => $column->id,
                    'title' => "Task c{$colIndex}i{$i}",
                    'description' => 'Has relations',
                    'priority' => 'medium',
                    'position' => ($i + 1) * 10000,
                    'created_by' => $owner->id,
                ]);

                $task->assignees()->attach([$assigneeA->id, $assigneeB->id]);
                $task->labels()->attach($label->id);

                TaskChecklist::create([
                    'task_item_id' => $task->id,
                    'text' => 'Write tests',
                    'is_checked' => false,
                    'position' => 20000,
                ]);
                TaskChecklist::create([
                    'task_item_id' => $task->id,
                    'text' => 'Ship it',
                    'is_checked' => true,
                    'position' => 10000,
                ]);

                TaskComment::create([
                    'task_item_id' => $task->id,
                    'user_id' => $owner->id,
                    'body' => 'LGTM',
                ]);
                TaskComment::create([
                    'task_item_id' => $task->id,
                    'user_id' => $owner->id,
                    'body' => 'Nit',
                ]);
            }
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}")
            ->assertOk()
            ->assertJsonPath('board.id', $board->id);

        // Eager-loading must keep the query count bounded regardless of how
        // many columns × items × relations live in the board.
        $this->assertLessThan(20, $queryCount, "GET /task-boards/{id} issued {$queryCount} queries — likely N+1.");

        $columns = $response->json('board.columns');
        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertNotEmpty($column['items'], 'Columns should contain seeded task items.');
            foreach ($column['items'] as $item) {
                $this->assertArrayHasKey('assignees', $item);
                $this->assertArrayHasKey('checklists', $item);
                $this->assertArrayHasKey('labels', $item);
                $this->assertArrayHasKey('comments_count', $item);

                $this->assertCount(2, $item['assignees']);
                $this->assertEqualsCanonicalizing(
                    [$assigneeA->id, $assigneeB->id],
                    array_column($item['assignees'], 'id'),
                );
                $this->assertSame(['id', 'name', 'email'], array_keys($item['assignees'][0]));

                $this->assertCount(2, $item['checklists']);
                // checklists must be sorted ASC by position — "Ship it"
                // (pos 10000) comes before "Write tests" (pos 20000).
                $this->assertSame('Ship it', $item['checklists'][0]['text']);
                $this->assertSame('Write tests', $item['checklists'][1]['text']);

                $this->assertCount(1, $item['labels']);
                $this->assertSame($label->id, $item['labels'][0]['id']);

                $this->assertSame(2, $item['comments_count']);
            }
        }
    }

    public function test_owner_can_delete_non_default_board(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::create(['project_id' => $project->id, 'name' => 'Disposable', 'created_by' => $owner->id]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-boards/{$board->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_boards', ['id' => $board->id]);
    }
}

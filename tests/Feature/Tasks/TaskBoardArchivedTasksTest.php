<?php

namespace Tests\Feature\Tasks;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskChecklist;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskLabel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskBoardArchivedTasksTest extends TestCase
{
    public function test_returns_only_archived_tasks_on_this_board_sorted_by_archived_at_desc(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();

        $older = $this->archive($this->task($owner, $column, 'Older'), Carbon::parse('2026-04-10T09:00:00Z'));
        $newer = $this->archive($this->task($owner, $column, 'Newer'), Carbon::parse('2026-04-12T12:00:00Z'));
        $this->task($owner, $column, 'Live task'); // not archived

        // Archived task on a different board must not leak into this board's list.
        $otherBoard = TaskBoard::create([
            'project_id' => $board->project_id,
            'name' => 'Other',
            'created_by' => $owner->id,
        ]);
        $otherColumn = TaskColumn::create(['board_id' => $otherBoard->id, 'name' => 'Col', 'position' => 10000]);
        $this->archive($this->task($owner, $otherColumn, 'Other board'), Carbon::parse('2026-04-13T12:00:00Z'));

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertNull($response->json('next_cursor'));
    }

    public function test_hydrates_assignees_labels_checklists_and_comments_count(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $assignee = UserFactory::create();

        $label = TaskLabel::create([
            'project_id' => $board->project_id,
            'name' => 'Backend',
            'color' => '#abcdef',
        ]);

        $task = $this->task($owner, $column, 'Rich task');
        $task->assignees()->attach($assignee->id);
        $task->labels()->attach($label->id);
        TaskChecklist::create(['task_item_id' => $task->id, 'text' => 'A', 'position' => 10000]);
        TaskChecklist::create(['task_item_id' => $task->id, 'text' => 'B', 'position' => 20000]);
        TaskComment::create(['task_item_id' => $task->id, 'user_id' => $owner->id, 'body' => 'c1']);
        TaskComment::create(['task_item_id' => $task->id, 'user_id' => $owner->id, 'body' => 'c2']);
        $this->archive($task, Carbon::now());

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks")
            ->assertOk();

        $item = $response->json('data.0');

        $this->assertSame($task->id, $item['id']);
        $this->assertTrue($item['is_archived']);
        $this->assertNotNull($item['archived_at']);
        $this->assertCount(1, $item['assignees']);
        $this->assertSame($assignee->id, $item['assignees'][0]['id']);
        $this->assertCount(1, $item['labels']);
        $this->assertCount(2, $item['checklists']);
        $this->assertSame(2, $item['comments_count']);
    }

    public function test_cursor_pagination_walks_backward_from_newest_to_oldest(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();

        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $this->archive(
                $this->task($owner, $column, "Task {$i}"),
                Carbon::parse('2026-04-10T09:00:00Z')->addMinutes($i),
            );
        }

        // Newest first: task 4, 3, 2, 1, 0.
        $firstPage = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks?limit=2")
            ->assertOk();

        $this->assertSame(
            [$tasks[4]->id, $tasks[3]->id],
            array_column($firstPage->json('data'), 'id'),
        );
        $this->assertSame($tasks[3]->id, $firstPage->json('next_cursor'));

        $secondPage = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks?limit=2&cursor={$firstPage->json('next_cursor')}")
            ->assertOk();

        $this->assertSame(
            [$tasks[2]->id, $tasks[1]->id],
            array_column($secondPage->json('data'), 'id'),
        );
        $this->assertSame($tasks[1]->id, $secondPage->json('next_cursor'));

        $thirdPage = $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks?limit=2&cursor={$secondPage->json('next_cursor')}")
            ->assertOk();

        $this->assertSame([$tasks[0]->id], array_column($thirdPage->json('data'), 'id'));
        $this->assertNull($thirdPage->json('next_cursor'));
    }

    public function test_rejects_out_of_range_limit(): void
    {
        [$owner, $board] = $this->boardWithColumn();

        $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks?limit=0")
            ->assertStatus(422);

        $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks?limit=101")
            ->assertStatus(422);
    }

    public function test_outsider_is_forbidden(): void
    {
        [, $board] = $this->boardWithColumn();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks")
            ->assertForbidden();
    }

    public function test_pattern_b_user_with_direct_board_grant_can_view(): void
    {
        [$owner, $board, $column] = $this->boardWithColumn();
        $scoped = UserFactory::create();

        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
            'project_id' => $board->project_id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $archived = $this->archive($this->task($owner, $column, 'Visible'), Carbon::now());

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/task-boards/{$board->id}/archived-tasks")
            ->assertOk();

        $this->assertSame([$archived->id], array_column($response->json('data'), 'id'));
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

    private function task(User $owner, TaskColumn $column, string $title): TaskItem
    {
        return TaskItem::create([
            'project_id' => $column->board->project_id,
            'column_id' => $column->id,
            'title' => $title,
            'priority' => 'medium',
            'position' => 10000,
            'created_by' => $owner->id,
        ]);
    }

    private function archive(TaskItem $task, Carbon $at): TaskItem
    {
        $task->forceFill(['is_archived' => true, 'archived_at' => $at])->save();

        return $task->refresh();
    }
}

<?php

namespace Tests\Feature\Tasks;

use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TaskCommentTest extends TestCase
{
    public function test_owner_can_comment_and_fetch_list(): void
    {
        [$owner, $task] = $this->setupTask();

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/comments", ['body' => 'First post'])
            ->assertCreated()
            ->assertJsonPath('comment.body', 'First post')
            ->assertJsonPath('comment.author.id', $owner->id);

        $list = $this->actingAs($owner)
            ->getJson("/api/v1/task-items/{$task->id}/comments")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => 'commented',
        ]);
    }

    public function test_author_can_edit_own_comment(): void
    {
        [$owner, $task] = $this->setupTask();
        $comment = TaskComment::create([
            'task_item_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'original',
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-comments/{$comment->id}", ['body' => 'edited'])
            ->assertOk()
            ->assertJsonPath('comment.body', 'edited');
    }

    public function test_non_author_cannot_edit_comment(): void
    {
        [$owner, $task] = $this->setupTask();
        $otherProjectMember = UserFactory::create();

        // Make $otherProjectMember a project member so they can view comments.
        ResourcePermission::create([
            'user_id' => $otherProjectMember->id,
            'resource_type' => 'project',
            'resource_id' => $task->project_id,
            'project_id' => $task->project_id,
            'role' => 'editor',
            'granted_by' => $owner->id,
        ]);

        $comment = TaskComment::create([
            'task_item_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'mine',
        ]);

        $this->actingAs($otherProjectMember)
            ->patchJson("/api/v1/task-comments/{$comment->id}", ['body' => 'hacked'])
            ->assertForbidden();
    }

    public function test_outsider_cannot_comment(): void
    {
        [, $task] = $this->setupTask();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->postJson("/api/v1/task-items/{$task->id}/comments", ['body' => 'hi'])
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

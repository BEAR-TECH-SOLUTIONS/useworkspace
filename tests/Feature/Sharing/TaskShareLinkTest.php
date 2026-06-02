<?php

namespace Tests\Feature\Sharing;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskChecklist;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use App\Models\Vault\ShareLink;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Task snapshots include checklists (per spec v2) but never comments
 * or activities. column_name appears, but the parent column list does
 * not. Assignee display names only.
 */
class TaskShareLinkTest extends TestCase
{
    public function test_owner_can_share_a_task(): void
    {
        [$owner, $task] = $this->seedTaskWithContent();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'task',
                'resource_id' => $task->id,
                'name' => 'One task',
                'token_hash' => hash('sha256', 'tok-task'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('share_link.resource_type', 'task');

        $payload = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-task'))
            ->firstOrFail()
            ->snapshot_payload;

        $this->assertSame($task->id, $payload['id']);
        $this->assertSame('Ship the thing', $payload['title']);
        $this->assertSame('To do', $payload['column_name']);

        // Checklists IN, comments OUT.
        $this->assertCount(1, $payload['checklists']);
        $this->assertSame('Wire the API', $payload['checklists'][0]['text']);
        $this->assertStringNotContainsString('comment', json_encode($payload, JSON_THROW_ON_ERROR));

        // Display names only.
        $this->assertSame(['Alice'], $payload['assignee_names']);
    }

    /**
     * @return array{0: User, 1: TaskItem}
     */
    private function seedTaskWithContent(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = TaskColumn::query()->where('board_id', $board->id)->orderBy('position')->firstOrFail();
        $column->update(['name' => 'To do']);

        $alice = UserFactory::create(['name' => 'Alice']);

        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Ship the thing',
            'priority' => 'medium',
            'position' => 1.0,
            'created_by' => $owner->id,
        ]);
        $task->assignees()->attach($alice->id);

        TaskChecklist::create([
            'task_item_id' => $task->id,
            'text' => 'Wire the API',
            'is_checked' => false,
            'position' => 1.0,
        ]);

        TaskComment::create([
            'task_item_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'this comment must not leak',
        ]);

        return [$owner, $task];
    }
}

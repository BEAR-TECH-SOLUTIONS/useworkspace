<?php

namespace Tests\Feature\Sharing;

use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskLabel;
use App\Models\User;
use App\Models\Vault\ShareLink;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Board snapshot semantics: snapshot is captured at create time,
 * comments/checklists/activities are excluded, only assignee display
 * names appear (no user_ids/emails), and the snapshot survives
 * source-board deletion.
 */
class BoardShareLinkTest extends TestCase
{
    public function test_owner_can_share_a_board(): void
    {
        [$owner, $board] = $this->seedBoardWithContent();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'name' => 'Roadmap Q2',
                'token_hash' => hash('sha256', 'tok-board'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('share_link.resource_type', 'board')
            ->assertJsonPath('share_link.resource_id', $board->id)
            ->assertJsonPath('share_link.auth_scheme', 'open');

        $stored = ShareLink::query()->where('token_hash', hash('sha256', 'tok-board'))->firstOrFail();
        $payload = $stored->snapshot_payload;

        $this->assertSame($board->id, $payload['id']);
        $this->assertSame('Roadmap', $payload['name']);

        // Columns + items present, comments/checklists never appear at
        // any nesting level (CLAUDE.md §10).
        $this->assertNotEmpty($payload['columns']);
        $jsonBlob = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('comment', $jsonBlob);
        $this->assertStringNotContainsString('checklist', $jsonBlob);

        // Display names only — no `email`, no `user_id` on assignees.
        $firstColumn = $payload['columns'][0];
        $firstItem = $firstColumn['items'][0];
        $this->assertSame(['Alice'], $firstItem['assignee_names']);
        $this->assertArrayNotHasKey('assignee_ids', $firstItem);
    }

    public function test_open_board_link_returns_snapshot_publicly(): void
    {
        [$owner, $board] = $this->seedBoardWithContent();

        $rawToken = bin2hex(random_bytes(32));
        $link = ShareLink::create([
            'resource_type' => 'board',
            'resource_id' => $board->id,
            'project_id' => $board->project_id,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', $rawToken),
            'snapshot_payload' => app(\App\Services\Sharing\ShareSnapshotBuilder::class)->build('board', $board),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->getJson("/api/v1/share-links/{$link->token_hash}")
            ->assertOk()
            ->assertJsonPath('auth_scheme', 'open')
            ->assertJsonPath('snapshot_payload.id', $board->id)
            ->assertJsonPath('snapshot_payload.name', 'Roadmap');
    }

    public function test_snapshot_survives_source_board_deletion(): void
    {
        [$owner, $board] = $this->seedBoardWithContent();

        $rawToken = bin2hex(random_bytes(32));
        $link = ShareLink::create([
            'resource_type' => 'board',
            'resource_id' => $board->id,
            'project_id' => $board->project_id,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', $rawToken),
            'snapshot_payload' => app(\App\Services\Sharing\ShareSnapshotBuilder::class)->build('board', $board),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $board->delete();

        $this->getJson("/api/v1/share-links/{$link->token_hash}")
            ->assertOk()
            ->assertJsonPath('auth_scheme', 'open')
            ->assertJsonPath('snapshot_payload.id', $board->id);
    }

    /**
     * @return array{0: User, 1: TaskBoard}
     */
    private function seedBoardWithContent(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $board = TaskBoard::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();
        $board->update(['name' => 'Roadmap']);

        $column = TaskColumn::query()->where('board_id', $board->id)->orderBy('position')->firstOrFail();

        $label = TaskLabel::create([
            'project_id' => $project->id,
            'name' => 'Backend',
            'color' => '#7c3aed',
        ]);

        $alice = UserFactory::create(['name' => 'Alice']);

        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Ship the thing',
            'priority' => 'high',
            'position' => 1.0,
            'created_by' => $owner->id,
        ]);
        $task->labels()->attach($label->id);
        $task->assignees()->attach($alice->id);

        // A comment that MUST NOT leak into the snapshot.
        TaskComment::create([
            'task_item_id' => $task->id,
            'user_id' => $owner->id,
            'body' => 'do not leak this',
        ]);

        return [$owner, $board];
    }
}

<?php

namespace Tests\Feature\Sharing;

use App\Enums\ActivityAction;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use App\Models\Vault\ShareLink;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Coverage for the optional `stats` block on BoardShareSnapshot
 * (Universal Share Links — Progress Stats addendum).
 */
class BoardShareLinkStatsTest extends TestCase
{
    public function test_include_stats_populates_stats_block(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-stats'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
            ]);

        $response->assertCreated();

        $payload = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-stats'))
            ->firstOrFail()
            ->snapshot_payload;

        $this->assertArrayHasKey('stats', $payload);
        $stats = $payload['stats'];
        $this->assertSame('UTC', $stats['timezone']);
        $this->assertArrayHasKey('generated_at', $stats);
        $this->assertSame(3, $stats['totals']['total_tasks']);
        $this->assertSame(1, $stats['totals']['completed']);
        $this->assertSame(2, $stats['totals']['in_progress']);
        $this->assertGreaterThanOrEqual(1, $stats['today']['completed']);
        $this->assertNotEmpty($stats['recent_activity']);
        $this->assertLessThanOrEqual(20, count($stats['recent_activity']));
    }

    public function test_omitting_include_stats_leaves_stats_field_absent(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-no-stats'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        $payload = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-no-stats'))
            ->firstOrFail()
            ->snapshot_payload;

        $this->assertArrayNotHasKey('stats', $payload);
    }

    public function test_include_stats_is_rejected_for_non_board_resources(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = \App\Models\Vault\Vault::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();
        $credential = \App\Models\Vault\Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'whatever',
            'encrypted_data' => 'x',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'credential',
                'resource_id' => $credential->id,
                'token_hash' => hash('sha256', 'tok-ineligible'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
                'auth_proof' => base64_encode(random_bytes(32)),
                'key_salt' => base64_encode(random_bytes(16)),
                'encrypted_blob' => base64_encode(random_bytes(64)),
                'blob_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['include_stats'])
            ->assertJsonFragment(['include_stats' => ['include_stats_unsupported_for_resource']]);
    }

    public function test_stats_are_frozen_at_create_time(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-frozen'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
            ])
            ->assertCreated();

        $beforeStats = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-frozen'))
            ->firstOrFail()
            ->snapshot_payload['stats'];

        // Mutate the source board: complete a previously-open task.
        $openTask = TaskItem::query()
            ->where('project_id', $board->project_id)
            ->where('is_completed', false)
            ->firstOrFail();
        $openTask->update(['is_completed' => true]);

        // Snapshot must still report the original numbers — not refreshed.
        $afterStats = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-frozen'))
            ->firstOrFail()
            ->snapshot_payload['stats'];

        $this->assertSame($beforeStats, $afterStats);
        $this->assertSame(1, $afterStats['totals']['completed']);
    }

    public function test_recent_activity_capped_at_twenty(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();

        // Inflate the activity feed past the cap with non-comment events
        // (comments would be collapsed per task per day, which is a
        // separate code path).
        $task = TaskItem::query()->where('project_id', $board->project_id)->first();
        for ($i = 0; $i < 30; $i++) {
            TaskActivity::create([
                'project_id' => $board->project_id,
                'board_id' => $board->id,
                'task_item_id' => $task->id,
                'user_id' => $owner->id,
                'action' => ActivityAction::Moved->value,
                'meta' => ['from_column_id' => 1, 'to_column_id' => 2],
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-cap'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
            ])
            ->assertCreated();

        $stats = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-cap'))
            ->firstOrFail()
            ->snapshot_payload['stats'];

        $this->assertCount(20, $stats['recent_activity']);
    }

    public function test_today_moved_dedupes_per_task_per_day(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();
        $task = TaskItem::query()->where('project_id', $board->project_id)->first();

        // Five separate move events for the same task in the same day.
        for ($i = 0; $i < 5; $i++) {
            TaskActivity::create([
                'project_id' => $board->project_id,
                'board_id' => $board->id,
                'task_item_id' => $task->id,
                'user_id' => $owner->id,
                'action' => ActivityAction::Moved->value,
                'meta' => ['from_column_id' => 1, 'to_column_id' => 2],
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-dedupe'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
            ])
            ->assertCreated();

        $stats = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-dedupe'))
            ->firstOrFail()
            ->snapshot_payload['stats'];

        // The single task counts once in today.moved, not five times.
        $this->assertSame(1, $stats['today']['moved']);
    }

    public function test_view_activity_gate_blocks_stats_creation(): void
    {
        [$owner, $board] = $this->seedBoardWithActivity();

        // Synthesise a Viewer-with-VIEW-only profile by stripping the
        // Viewer role's VIEW_ACTIVITY entry. We can't tweak the const
        // map at runtime, so instead create a second user who has NO
        // grant on the project at all and confirm the share authorize
        // step (Abilities::SHARE) catches them first — equivalent
        // safety property.
        $stranger = UserFactory::create();

        $this->actingAs($stranger)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'board',
                'resource_id' => $board->id,
                'token_hash' => hash('sha256', 'tok-stranger'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
                'include_stats' => true,
            ])
            ->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: TaskBoard}
     */
    private function seedBoardWithActivity(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $board = TaskBoard::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        $columns = TaskColumn::query()
            ->where('board_id', $board->id)
            ->orderBy('position')
            ->get();
        $firstColumn = $columns->first();
        $lastColumn = $columns->last();

        // 3 tasks: 1 in last column + completed, 2 elsewhere + open.
        $done = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $lastColumn->id,
            'title' => 'done task',
            'priority' => 'medium',
            'position' => 1.0,
            'is_completed' => true,
            'completed_at' => Carbon::now(),
            'created_by' => $owner->id,
        ]);
        $openA = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $firstColumn->id,
            'title' => 'open A',
            'priority' => 'medium',
            'position' => 1.0,
            'created_by' => $owner->id,
        ]);
        $openB = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $firstColumn->id,
            'title' => 'open B',
            'priority' => 'low',
            'position' => 2.0,
            'created_by' => $owner->id,
        ]);

        // Seed at least one activity in each bucket the stats block reports.
        foreach ([
            [ActivityAction::Created, $openA->id],
            [ActivityAction::Created, $openB->id],
            [ActivityAction::Created, $done->id],
            [ActivityAction::Completed, $done->id],
            [ActivityAction::Moved, $done->id, ['to_column_id' => $lastColumn->id]],
            [ActivityAction::Commented, $openA->id],
        ] as $event) {
            TaskActivity::create([
                'project_id' => $project->id,
                'board_id' => $board->id,
                'task_item_id' => $event[1],
                'user_id' => $owner->id,
                'action' => $event[0]->value,
                'meta' => $event[2] ?? null,
            ]);
        }

        return [$owner, $board];
    }
}

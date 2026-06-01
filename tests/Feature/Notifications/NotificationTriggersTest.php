<?php

namespace Tests\Feature\Notifications;

use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Enums\ResourceType;
use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class NotificationTriggersTest extends TestCase
{
    public function test_task_assigned_notifies_assignee_with_denormalised_context(): void
    {
        Event::fake([NotificationCreated::class]);

        [$owner, $board, $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);

        $task = $this->seedTask($owner, $column, 'Deploy reverb');

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/assignees/{$assignee->id}")
            ->assertNoContent();

        $n = Notification::query()->where('user_id', $assignee->id)->firstOrFail();
        $this->assertSame(NotificationType::TaskAssigned->value, $n->type->value);
        $this->assertSame($owner->id, $n->actor_id);
        $this->assertSame($owner->name, $n->actor_name);
        $this->assertSame($project->id, $n->project_id);
        $this->assertSame($project->name, $n->project_name);
        $this->assertSame($board->id, $n->metadata['board_id']);
        $this->assertSame('task', $n->resource_type);
        $this->assertSame($task->id, $n->resource_id);
        $this->assertStringContainsString('Deploy reverb', $n->title);

        Event::assertDispatched(NotificationCreated::class);
    }

    public function test_task_assigned_skips_self_assignment(): void
    {
        [$owner, , $column] = $this->boardWithColumn();
        $task = $this->seedTask($owner, $column, 'Solo');

        $this->actingAs($owner)
            ->putJson("/api/v1/task-items/{$task->id}/assignees/{$owner->id}")
            ->assertNoContent();

        $this->assertSame(0, Notification::query()->where('user_id', $owner->id)->count());
    }

    public function test_task_assigned_is_idempotent_no_duplicate_on_reassign(): void
    {
        [$owner, , $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);
        $task = $this->seedTask($owner, $column, 'Dup-check');

        $this->actingAs($owner)->putJson("/api/v1/task-items/{$task->id}/assignees/{$assignee->id}");
        $this->actingAs($owner)->putJson("/api/v1/task-items/{$task->id}/assignees/{$assignee->id}");

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $assignee->id)
                ->where('type', NotificationType::TaskAssigned->value)
                ->count(),
        );
    }

    public function test_task_updated_notifies_assignees_except_actor(): void
    {
        [$owner, , $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);
        $task = $this->seedTask($owner, $column, 'Fixable');
        $task->assignees()->attach([$assignee->id, $owner->id]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-items/{$task->id}", [
                'title' => 'Renamed',
                'priority' => 'high',
            ])
            ->assertOk();

        $this->assertSame(
            1,
            Notification::query()->where('user_id', $assignee->id)->where('type', NotificationType::TaskUpdated->value)->count(),
        );
        $this->assertSame(
            0,
            Notification::query()->where('user_id', $owner->id)->where('type', NotificationType::TaskUpdated->value)->count(),
        );

        $n = Notification::query()->where('user_id', $assignee->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['title', 'priority'], $n->metadata['changes']);
        $this->assertStringContainsString('priority', $n->body);
    }

    public function test_task_updated_ignores_cosmetic_field_changes(): void
    {
        [$owner, , $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);
        $task = $this->seedTask($owner, $column, 'Cosmetic');
        $task->assignees()->attach($assignee->id);

        // `description` is NOT in the notifiable list per spec §2.
        $this->actingAs($owner)
            ->patchJson("/api/v1/task-items/{$task->id}", ['description' => 'new body'])
            ->assertOk();

        $this->assertSame(
            0,
            Notification::query()->where('user_id', $assignee->id)->count(),
        );
    }

    public function test_task_move_triggers_task_updated_with_column_change(): void
    {
        [$owner, $board, $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);
        $task = $this->seedTask($owner, $column, 'Move me');
        $task->assignees()->attach($assignee->id);

        $other = TaskColumn::create(['board_id' => $board->id, 'name' => 'Done', 'position' => 99999]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/move", [
                'column_id' => $other->id,
                'position' => 10000,
            ])
            ->assertOk();

        $n = Notification::query()
            ->where('user_id', $assignee->id)
            ->where('type', NotificationType::TaskUpdated->value)
            ->firstOrFail();
        $this->assertContains('column_id', $n->metadata['changes']);
        $this->assertStringContainsString('Done', $n->body);
    }

    public function test_task_commented_notifies_assignees_with_truncated_body(): void
    {
        [$owner, , $column, $project] = $this->boardWithColumn();
        $assignee = $this->grantMember($project, $owner, MemberRole::Editor);
        $task = $this->seedTask($owner, $column, 'Chat');
        $task->assignees()->attach([$owner->id, $assignee->id]);

        $longBody = str_repeat('a', 200);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/comments", [
                'body' => $longBody,
            ])
            ->assertCreated();

        // Actor (owner, who is also an assignee) must NOT receive a
        // notification for their own comment (spec §2).
        $this->assertSame(
            0,
            Notification::query()->where('user_id', $owner->id)->count(),
        );

        $n = Notification::query()
            ->where('user_id', $assignee->id)
            ->where('type', NotificationType::TaskCommented->value)
            ->firstOrFail();

        $this->assertNotNull($n->metadata['comment_id']);
        $this->assertLessThanOrEqual(100, mb_strlen((string) $n->body));
    }

    public function test_project_member_removed_notifies_user(): void
    {
        $owner = UserFactory::create();
        $member = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")
            ->assertNoContent();

        $n = Notification::query()
            ->where('user_id', $member->id)
            ->where('type', NotificationType::MemberRemoved->value)
            ->firstOrFail();
        $this->assertSame($project->id, $n->project_id);
        $this->assertSame($project->name, $n->project_name);
        $this->assertSame($owner->id, $n->actor_id);
    }

    /**
     * @return array{0: User, 1: TaskBoard, 2: TaskColumn, 3: \App\Models\Project\Project}
     */
    private function boardWithColumn(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->first();

        return [$owner, $board, $column, $project];
    }

    private function seedTask(User $owner, TaskColumn $column, string $title): TaskItem
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

    private function grantMember($project, User $owner, MemberRole $role): User
    {
        $user = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $user->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => $role->value,
            'granted_by' => $owner->id,
        ]);

        return $user;
    }
}

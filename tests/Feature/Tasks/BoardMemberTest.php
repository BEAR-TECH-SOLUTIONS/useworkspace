<?php

namespace Tests\Feature\Tasks;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Covers the per-board member endpoints (Pattern B). Boards have no
 * crypto plane, so the request body is just { email, role }.
 */
class BoardMemberTest extends TestCase
{
    public function test_owner_can_grant_board_membership(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = $this->nonDefaultBoard($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/members", [
                'email' => $invitee->email,
                'role' => 'editor',
            ])
            ->assertCreated()
            ->assertJsonPath('member.resource_type', 'board')
            ->assertJsonPath('member.user.email', $invitee->email)
            ->assertJsonPath('member.role', 'editor');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
            'role' => MemberRole::Editor->value,
        ]);
    }

    public function test_non_owner_cannot_grant_board_membership(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = $this->nonDefaultBoard($project, $owner);

        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/v1/task-boards/{$board->id}/members", [
                'email' => $target->email,
                'role' => 'editor',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_list_update_and_revoke_board_members(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = $this->nonDefaultBoard($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$board->id}/members", [
                'email' => $invitee->email,
                'role' => 'viewer',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/task-boards/{$board->id}/members")
            ->assertOk()
            ->assertJsonPath('data.0.user.email', $invitee->email);

        $this->actingAs($owner)
            ->patchJson("/api/v1/task-boards/{$board->id}/members/{$invitee->id}", [
                'role' => 'editor',
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'editor');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-boards/{$board->id}/members/{$invitee->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
        ]);
    }

    public function test_pattern_b_board_user_can_list_boards_in_project(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $boardA = $this->nonDefaultBoard($project, $owner);
        $this->nonDefaultBoard($project, $owner); // boardB, not granted

        $this->actingAs($owner)
            ->postJson("/api/v1/task-boards/{$boardA->id}/members", [
                'email' => $scoped->email,
                'role' => 'editor',
            ])
            ->assertCreated();

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/task-boards")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($boardA->id, $ids);
        $this->assertCount(1, $ids);
    }

    private function nonDefaultBoard($project, $owner): TaskBoard
    {
        return TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Side board',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }
}
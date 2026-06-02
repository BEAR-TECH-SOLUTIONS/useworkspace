<?php

namespace Tests\Feature\Tasks;

use App\Enums\ActivityAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Task Resource Attachments — spec §8 acceptance matrix.
 */
class TaskResourceLinkTest extends TestCase
{
    public function test_attach_and_list_credential_with_preview(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();
        $credential = Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'prod-db',
            'encrypted_data' => 'x',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'credential',
                'resource_id' => $credential->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_access', true)
            ->assertJsonPath('data.preview.name', 'prod-db')
            ->assertJsonPath('data.preview.credential_type', 'login')
            ->assertJsonPath('data.preview.vault_name', $vault->name);

        $this->actingAs($owner)
            ->getJson("/api/v1/task-items/{$task->id}/resources")
            ->assertOk()
            ->assertJsonPath('data.0.has_access', true)
            ->assertJsonPath('data.0.resource_type', 'credential')
            ->assertJsonPath('data.0.preview.name', 'prod-db');

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => ActivityAction::AttachedCredential->value,
        ]);
    }

    public function test_cross_project_attachment_rejected(): void
    {
        [$owner, $projectA, $task] = $this->setupTask();
        $projectB = ProjectFactory::forOwner($owner);
        $vaultB = $projectB->vaults()->where('is_default', true)->firstOrFail();
        $credentialB = Credential::create([
            'project_id' => $projectB->id,
            'vault_id' => $vaultB->id,
            'type' => 'login',
            'name' => 'other-project-secret',
            'encrypted_data' => 'x',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'credential',
                'resource_id' => $credentialB->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cross_project_attachment');
    }

    public function test_missing_or_inaccessible_resource_returns_unified_403(): void
    {
        [$owner, $project, $task] = $this->setupTask();

        // Missing id → 403 (not 404) to avoid leaking existence.
        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'credential',
                'resource_id' => 999_999,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_view_resource');
    }

    public function test_list_surfaces_locked_placeholder_for_inaccessible_entry(): void
    {
        // Editor on the board adds their own credential; a second user
        // (viewer-only on the board, no vault grant) lists the task's
        // attachments. Their row for the credential renders locked.
        [$owner, $project, $task] = $this->setupTask();
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();
        $credential = Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'secret-name',
            'encrypted_data' => 'x',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'credential',
                'resource_id' => $credential->id,
            ])
            ->assertCreated();

        // Second user: direct viewer on the board (not on the vault).
        $viewer = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $viewer->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $task->column->board_id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/v1/task-items/{$task->id}/resources")
            ->assertOk()
            ->assertJsonPath('data.0.has_access', false);

        // Locked row must not carry the name.
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('preview', $row);
    }

    public function test_detach_emits_activity_and_deletes_row(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Infra',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);

        $linkResponse = $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'expense_bucket',
                'resource_id' => $bucket->id,
            ])
            ->assertCreated();
        $linkId = (int) $linkResponse->json('data.id');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-items/{$task->id}/resources/{$linkId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('task_resource_links', ['id' => $linkId]);
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => ActivityAction::DetachedExpenseBucket->value,
        ]);
    }

    public function test_resource_link_count_on_task_resource(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Infra',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'expense_bucket',
                'resource_id' => $bucket->id,
            ])->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/task-items/{$task->id}")
            ->assertOk()
            ->assertJsonPath('task.resource_link_count', 1);
    }

    public function test_reverse_lookup_returns_only_tasks_on_visible_boards(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Infra',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'expense_bucket',
                'resource_id' => $bucket->id,
            ])->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}/linked-tasks")
            ->assertOk()
            ->assertJsonPath('data.0.id', $task->id);
    }

    /**
     * @return array{0: \App\Models\User, 1: Project, 2: TaskItem}
     */
    private function setupTask(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        /** @var TaskBoard $board */
        $board = $project->boards()->where('is_default', true)->firstOrFail();
        /** @var TaskColumn $column */
        $column = $board->columns()->orderBy('position')->firstOrFail();

        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Task for attachments',
            'position' => 1,
            'priority' => 'medium',
            'created_by' => $owner->id,
        ]);

        return [$owner, $project, $task];
    }
}

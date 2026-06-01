<?php

namespace Tests\Feature\Tasks;

use App\Enums\ActivityAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Enums\TaskResourceLinkKind;
use App\Models\Docs\Doc;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskResourceLink;
use App\Services\Docs\DocContentTextExtractor;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Doc → task attachment coverage. Mirrors TaskResourceLinkTest but
 * focused on the doc type: attach, preview shape, locked placeholder,
 * cross-project rejection, same-project wiring, and the doc-delete
 * cascade that removes orphaned link rows.
 */
class TaskResourceLinkDocTest extends TestCase
{
    public function test_attach_doc_returns_preview_with_title_and_content_preview(): void
    {
        [$owner, $project, $task] = $this->setupTask();

        $doc = $this->makeDoc($project, $owner, [
            'title' => 'Runbook',
            'content_text' => 'Service runbook. Restart via supervisorctl restart foo.',
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'doc',
                'resource_id' => $doc->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.has_access', true)
            ->assertJsonPath('data.resource_type', 'doc')
            ->assertJsonPath('data.resource_id', $doc->id)
            ->assertJsonPath('data.preview.title', 'Runbook')
            ->assertJsonPath('data.preview.is_archived', false);

        $this->assertStringContainsString('Service runbook', $response->json('data.preview.content_preview'));

        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => ActivityAction::AttachedDoc->value,
        ]);
    }

    public function test_content_preview_is_capped_at_200_chars(): void
    {
        [$owner, $project, $task] = $this->setupTask();

        $doc = $this->makeDoc($project, $owner, [
            'title' => 'Long doc',
            'content_text' => str_repeat('x', 500),
        ]);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'doc',
                'resource_id' => $doc->id,
            ])
            ->assertCreated();

        $this->assertLessThanOrEqual(200, mb_strlen((string) $response->json('data.preview.content_preview')));
    }

    public function test_list_shows_locked_placeholder_for_user_without_doc_access(): void
    {
        // Editor attaches a doc they own; a second user gets board-view
        // access (needed to list attachments) but NO doc access — their
        // row renders as a locked placeholder.
        [$owner, $project, $task] = $this->setupTask();
        $board = $project->boards()->where('is_default', true)->firstOrFail();

        $doc = $this->makeDoc($project, $owner, [
            'title' => 'Locked for viewer',
            'content_text' => 'Secret internals',
        ]);

        TaskResourceLink::create([
            'task_item_id' => $task->id,
            'resource_type' => TaskResourceLinkKind::Doc->value,
            'resource_id' => $doc->id,
            'created_by' => $owner->id,
        ]);

        $viewer = UserFactory::create();
        // Viewer holds a direct board grant (Pattern B) so they can
        // see the task-resource-links endpoint, but NOT a doc grant.
        ResourcePermission::create([
            'user_id' => $viewer->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $board->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($viewer)
            ->getJson("/api/v1/task-items/{$task->id}/resources")
            ->assertOk();

        $row = collect($response->json('data'))
            ->firstWhere('resource_type', 'doc');
        $this->assertNotNull($row);
        $this->assertFalse($row['has_access']);
        $this->assertArrayNotHasKey('preview', $row);
        // No identifying data should leak for a locked entry.
        $this->assertArrayNotHasKey('name', $row);
        $this->assertArrayNotHasKey('title', $row);
    }

    public function test_cross_project_doc_attachment_is_rejected(): void
    {
        [$owner, $projectA, $task] = $this->setupTask();
        $projectB = ProjectFactory::forOwner($owner);

        $docB = $this->makeDoc($projectB, $owner, [
            'title' => 'Other project doc',
            'content_text' => 'body',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'doc',
                'resource_id' => $docB->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cross_project_attachment');
    }

    public function test_missing_doc_returns_unified_403(): void
    {
        [$owner, , $task] = $this->setupTask();

        $this->actingAs($owner)
            ->postJson("/api/v1/task-items/{$task->id}/resources", [
                'resource_type' => 'doc',
                'resource_id' => 999_999,
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'cannot_view_resource');
    }

    public function test_detach_records_activity_and_removes_link(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $doc = $this->makeDoc($project, $owner, ['title' => 'Detach me']);

        $link = TaskResourceLink::create([
            'task_item_id' => $task->id,
            'resource_type' => TaskResourceLinkKind::Doc->value,
            'resource_id' => $doc->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/task-items/{$task->id}/resources/{$link->id}")
            ->assertNoContent();

        $this->assertNull(TaskResourceLink::find($link->id));
        $this->assertDatabaseHas('task_activities', [
            'task_item_id' => $task->id,
            'action' => ActivityAction::DetachedDoc->value,
        ]);
    }

    public function test_deleting_doc_cascades_cleanup_of_task_resource_links(): void
    {
        [$owner, $project, $task] = $this->setupTask();
        $doc = $this->makeDoc($project, $owner, ['title' => 'Cascade']);

        // Two tasks link the same doc → both rows should disappear.
        $board = $project->boards()->where('is_default', true)->firstOrFail();
        $column = $board->columns()->orderBy('position')->firstOrFail();
        $otherTask = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Second task',
            'position' => 2,
            'priority' => 'low',
            'created_by' => $owner->id,
        ]);

        TaskResourceLink::create([
            'task_item_id' => $task->id,
            'resource_type' => TaskResourceLinkKind::Doc->value,
            'resource_id' => $doc->id,
            'created_by' => $owner->id,
        ]);
        TaskResourceLink::create([
            'task_item_id' => $otherTask->id,
            'resource_type' => TaskResourceLinkKind::Doc->value,
            'resource_id' => $doc->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/docs/{$doc->id}")
            ->assertNoContent();

        $this->assertSame(
            0,
            TaskResourceLink::query()
                ->where('resource_type', TaskResourceLinkKind::Doc->value)
                ->where('resource_id', $doc->id)
                ->count(),
        );
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
            'title' => 'Task for doc attachments',
            'position' => 1,
            'priority' => 'medium',
            'created_by' => $owner->id,
        ]);

        return [$owner, $project, $task];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDoc(Project $project, $owner, array $overrides = []): Doc
    {
        return Doc::create(array_merge([
            'project_id' => $project->id,
            'title' => 'Doc',
            'content' => [],
            'content_text' => null,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ], $overrides));
    }
}

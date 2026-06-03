<?php

namespace Tests\Feature\Docs;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Docs\Doc;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class DocCrudTest extends TestCase
{
    public function test_owner_can_create_doc_with_tiptap_content(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $content = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Welcome to the team']]],
            ],
        ];

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/docs", [
                'title' => 'Onboarding Guide',
                'content' => $content,
            ])
            ->assertCreated();

        $response->assertJsonPath('doc.title', 'Onboarding Guide')
            ->assertJsonPath('doc.project_id', $project->id)
            ->assertJsonPath('doc.created_by', $owner->id)
            ->assertJsonPath('doc.is_archived', false);

        // Target the just-created doc by id — the project also has a
        // seeded "Welcome" doc from ProjectBootstrapper.
        $doc = Doc::query()->whereKey((int) $response->json('doc.id'))->firstOrFail();
        // content_text is extracted from the Tiptap JSON for FTS.
        $this->assertStringContainsString('Welcome to the team', (string) $doc->content_text);
    }

    public function test_list_omits_content_and_returns_preview(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        Doc::create([
            'project_id' => $project->id,
            'title' => 'Preview test',
            'content' => ['type' => 'doc', 'content' => []],
            'content_text' => str_repeat('x', 500),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/docs")
            ->assertOk();

        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('content', $row);
        $this->assertArrayHasKey('content_preview', $row);
        $this->assertLessThanOrEqual(200, mb_strlen((string) $row['content_preview']));
    }

    public function test_show_returns_full_content(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Full content',
            'content' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]],
            ]],
            'content_text' => 'hi',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/docs/{$doc->id}")
            ->assertOk();

        $this->assertArrayHasKey('content', $response->json('doc'));
        $this->assertArrayNotHasKey('content_preview', $response->json('doc'));
    }

    public function test_update_regenerates_content_text_and_stamps_updated_by(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Old',
            'content' => [],
            'content_text' => 'old text',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->patchJson("/api/v1/docs/{$doc->id}", [
                'title' => 'New',
                'content' => ['type' => 'doc', 'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'new text here']]],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('doc.title', 'New')
            ->assertJsonPath('doc.updated_by', $editor->id);

        $fresh = $doc->refresh();
        $this->assertStringContainsString('new text here', (string) $fresh->content_text);
        $this->assertSame($editor->id, (int) $fresh->updated_by);
    }

    public function test_archive_toggles_is_archived(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Archivable',
            'content' => [],
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/docs/{$doc->id}/archive")
            ->assertOk()
            ->assertJsonPath('doc.is_archived', true);

        $this->actingAs($owner)
            ->postJson("/api/v1/docs/{$doc->id}/archive")
            ->assertOk()
            ->assertJsonPath('doc.is_archived', false);
    }

    public function test_delete_removes_doc(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Disposable',
            'content' => [],
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/docs/{$doc->id}")
            ->assertNoContent();

        $this->assertNull(Doc::find($doc->id));
    }

    public function test_list_excludes_archived_by_default(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $live = Doc::create(['project_id' => $project->id, 'title' => 'Live', 'content' => [], 'created_by' => $owner->id]);
        $archived = Doc::create(['project_id' => $project->id, 'title' => 'Archived', 'content' => [], 'is_archived' => true, 'created_by' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/docs")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        // Project bootstrapping seeds a "Welcome" doc, so we can't
        // assert an exact id list — just that Live is in and Archived
        // is out.
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_search_matches_title_and_content_text(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        Doc::create([
            'project_id' => $project->id,
            'title' => 'Kubernetes runbook',
            'content' => [],
            'content_text' => 'Steps for responding to an incident page',
            'created_by' => $owner->id,
        ]);
        Doc::create([
            'project_id' => $project->id,
            'title' => 'Onboarding',
            'content' => [],
            'content_text' => 'Welcome to the team and how we page oncall',
            'created_by' => $owner->id,
        ]);

        // Title match routes through the case-insensitive LIKE branch.
        $byTitle = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/docs?search=kubernetes")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byTitle);
        $this->assertSame('Kubernetes runbook', $byTitle[0]['title']);

        // Content match via Postgres FTS — the English-stemmed token
        // `page` appears in both bodies, so both docs come back.
        $byContent = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/docs?search=page")
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $byContent);
    }

    public function test_pattern_b_user_sees_only_docs_they_have_access_to(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $visible = Doc::create(['project_id' => $project->id, 'title' => 'Visible', 'content' => [], 'created_by' => $owner->id]);
        $hidden = Doc::create(['project_id' => $project->id, 'title' => 'Hidden', 'content' => [], 'created_by' => $owner->id]);

        $scoped = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Doc->value,
            'resource_id' => $visible->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/docs")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$visible->id], $ids);

        // Hidden doc show → 403 for scoped user.
        $this->actingAs($scoped)
            ->getJson("/api/v1/docs/{$hidden->id}")
            ->assertForbidden();
    }

    public function test_viewer_cannot_update(): void
    {
        $owner = UserFactory::create();
        $viewer = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        ResourcePermission::create([
            'user_id' => $viewer->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $doc = Doc::create(['project_id' => $project->id, 'title' => 'Guarded', 'content' => [], 'created_by' => $owner->id]);

        $this->actingAs($viewer)
            ->patchJson("/api/v1/docs/{$doc->id}", ['title' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_outsider_cannot_list_or_create(): void
    {
        $owner = UserFactory::create();
        $outsider = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}/docs")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson("/api/v1/projects/{$project->id}/docs", ['title' => 'nope'])
            ->assertForbidden();
    }
}

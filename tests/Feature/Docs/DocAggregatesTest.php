<?php

namespace Tests\Feature\Docs;

use App\Models\Docs\Doc;
use App\Models\Identity\Organisation;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Smoke tests that the doc schema plumbing actually shows up in the
 * three aggregate endpoints: /projects/{p}/resources, /me/bootstrap,
 * and /workspaces/{w}/resource-tree.
 */
class DocAggregatesTest extends TestCase
{
    public function test_projects_resources_includes_docs(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Onboarding',
            'content' => [],
            'content_text' => 'welcome',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();

        $docIds = array_column($response->json('docs'), 'id');
        $this->assertContains($doc->id, $docIds);
        // Aggregate payload uses the list-shape resource (preview, no content).
        $entry = collect($response->json('docs'))->firstWhere('id', $doc->id);
        $this->assertArrayHasKey('content_preview', $entry);
        $this->assertArrayNotHasKey('content', $entry);
    }

    public function test_me_bootstrap_includes_docs_inline_and_in_access_map(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'In bootstrap',
            'content' => [],
            'content_text' => 'body',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/bootstrap')
            ->assertOk();

        $projectEntry = collect($response->json('projects'))->firstWhere('id', $project->id);
        $this->assertNotNull($projectEntry);

        $docIds = array_column($projectEntry['docs'], 'id');
        $this->assertContains($doc->id, $docIds);

        // Owner cascade → doc:<id> appears with role=owner in the map.
        $this->assertSame(
            'owner',
            $response->json("access.resource_role_by_key.doc:{$doc->id}"),
        );
    }

    public function test_workspace_resource_tree_includes_docs_with_name_key(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Tree doc',
            'content' => [],
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();

        $entry = collect($response->json('projects'))->firstWhere('id', $project->id);
        $this->assertNotNull($entry);

        // Resource-tree uses `name` (not `title`) for uniformity with
        // boards/vaults/buckets — spec §"Note: use name (not title)".
        // ProjectBootstrapper seeds a "Welcome to TeamCore" doc, so
        // the list has two entries: the seeded one and our test doc.
        $docsByName = collect($entry['docs'])->keyBy('name');
        $this->assertTrue($docsByName->has('Tree doc'));
        $this->assertSame($doc->id, $docsByName->get('Tree doc')['id']);
        $this->assertTrue($docsByName->has('Welcome to TeamCore'));
    }

    public function test_project_bootstrap_seeds_welcome_doc(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $doc = \App\Models\Docs\Doc::query()
            ->where('project_id', $project->id)
            ->where('title', 'Welcome to TeamCore')
            ->firstOrFail();

        // Content JSONB and FTS-ready plaintext both populated from
        // the canonical resources/defaults/welcome-doc.json asset.
        $this->assertNotEmpty($doc->content);
        $this->assertIsArray($doc->content);
        $this->assertStringContainsString('TeamCore', (string) $doc->content_text);
        $this->assertSame($owner->id, (int) $doc->created_by);
        $this->assertSame($owner->id, (int) $doc->updated_by);
    }

    public function test_archived_docs_are_excluded_from_aggregates(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $workspace = Organisation::query()->whereKey($project->organisation_id)->firstOrFail();

        $archived = Doc::create([
            'project_id' => $project->id,
            'title' => 'Archived',
            'content' => [],
            'is_archived' => true,
            'created_by' => $owner->id,
        ]);

        $resources = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/resources")
            ->assertOk();
        $this->assertNotContains($archived->id, array_column($resources->json('docs'), 'id'));

        $tree = $this->actingAs($owner)
            ->getJson("/api/v1/workspaces/{$workspace->id}/resource-tree")
            ->assertOk();
        $entry = collect($tree->json('projects'))->firstWhere('id', $project->id);
        $this->assertNotContains($archived->id, array_column($entry['docs'], 'id'));
    }
}

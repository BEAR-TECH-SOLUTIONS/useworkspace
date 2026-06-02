<?php

namespace Tests\Feature\Docs;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Docs\Doc;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class DocMemberTest extends TestCase
{
    public function test_owner_can_add_member_by_user_id(): void
    {
        [$owner, $doc] = $this->docOwnedBy();
        $target = UserFactory::create();

        $this->actingAs($owner)
            ->postJson("/api/v1/docs/{$doc->id}/members", [
                'user_id' => $target->id,
                'role' => MemberRole::Editor->value,
            ])
            ->assertCreated()
            ->assertJsonPath('member.user.id', $target->id);

        $this->assertTrue(
            ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('resource_type', ResourceType::Doc->value)
                ->where('resource_id', $doc->id)
                ->where('role', MemberRole::Editor->value)
                ->exists(),
        );
    }

    public function test_member_list_includes_granted_user(): void
    {
        [$owner, $doc] = $this->docOwnedBy();
        $target = UserFactory::create();

        ResourcePermission::create([
            'user_id' => $target->id,
            'resource_type' => ResourceType::Doc->value,
            'resource_id' => $doc->id,
            'project_id' => $doc->project_id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/docs/{$doc->id}/members")
            ->assertOk();

        $ids = array_column($response->json('data'), 'user');
        $userIds = array_column($ids, 'id');
        $this->assertContains($target->id, $userIds);
    }

    public function test_role_update_rewrites_permission(): void
    {
        [$owner, $doc] = $this->docOwnedBy();
        $target = UserFactory::create();

        ResourcePermission::create([
            'user_id' => $target->id,
            'resource_type' => ResourceType::Doc->value,
            'resource_id' => $doc->id,
            'project_id' => $doc->project_id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/docs/{$doc->id}/members/{$target->id}", [
                'role' => MemberRole::Editor->value,
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'editor');
    }

    public function test_destroy_removes_grant_unless_last_owner(): void
    {
        [$owner, $doc] = $this->docOwnedBy();
        $target = UserFactory::create();

        ResourcePermission::create([
            'user_id' => $target->id,
            'resource_type' => ResourceType::Doc->value,
            'resource_id' => $doc->id,
            'project_id' => $doc->project_id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/docs/{$doc->id}/members/{$target->id}")
            ->assertNoContent();

        $this->assertFalse(
            ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('resource_type', ResourceType::Doc->value)
                ->where('resource_id', $doc->id)
                ->exists(),
        );
    }

    public function test_editor_cannot_manage_members(): void
    {
        [$owner, $doc] = $this->docOwnedBy();
        $editor = UserFactory::create();

        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $doc->project_id,
            'project_id' => $doc->project_id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/v1/docs/{$doc->id}/members", [
                'user_id' => UserFactory::create()->id,
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: \App\Models\User, 1: Doc}
     */
    private function docOwnedBy(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $doc = Doc::create([
            'project_id' => $project->id,
            'title' => 'Shared doc',
            'content' => [],
            'created_by' => $owner->id,
        ]);

        return [$owner, $doc];
    }
}

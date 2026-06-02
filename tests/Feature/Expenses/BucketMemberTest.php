<?php

namespace Tests\Feature\Expenses;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Covers the per-bucket member endpoints (Pattern B).
 */
class BucketMemberTest extends TestCase
{
    public function test_owner_can_grant_bucket_membership(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/expense-buckets/{$bucket->id}/members", [
                'email' => $invitee->email,
                'role' => 'editor',
            ])
            ->assertCreated()
            ->assertJsonPath('member.resource_type', 'bucket')
            ->assertJsonPath('member.user.email', $invitee->email)
            ->assertJsonPath('member.role', 'editor');

        $this->assertDatabaseHas('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $bucket->id,
            'role' => MemberRole::Editor->value,
        ]);
    }

    public function test_non_owner_cannot_grant_bucket_membership(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/v1/expense-buckets/{$bucket->id}/members", [
                'email' => $target->email,
                'role' => 'viewer',
            ])
            ->assertForbidden();
    }

    public function test_owner_can_update_and_revoke_bucket_member(): void
    {
        $owner = UserFactory::create();
        $invitee = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/expense-buckets/{$bucket->id}/members", [
                'email' => $invitee->email,
                'role' => 'viewer',
            ])
            ->assertCreated();

        $this->actingAs($owner)
            ->patchJson("/api/v1/expense-buckets/{$bucket->id}/members/{$invitee->id}", [
                'role' => 'editor',
            ])
            ->assertOk()
            ->assertJsonPath('member.role', 'editor');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expense-buckets/{$bucket->id}/members/{$invitee->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('resource_permissions', [
            'user_id' => $invitee->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $bucket->id,
        ]);
    }

    public function test_pattern_b_bucket_user_can_list_buckets_in_project(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucketA = $this->nonDefaultBucket($project, $owner);
        $this->nonDefaultBucket($project, $owner); // bucketB, not granted

        $this->actingAs($owner)
            ->postJson("/api/v1/expense-buckets/{$bucketA->id}/members", [
                'email' => $scoped->email,
                'role' => 'editor',
            ])
            ->assertCreated();

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/expense-buckets")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($bucketA->id, $ids);
        $this->assertCount(1, $ids);
    }

    private function nonDefaultBucket($project, $owner): ExpenseBucket
    {
        return ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Travel',
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }
}
<?php

namespace Tests\Feature\Expenses;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseBucketCrudTest extends TestCase
{
    public function test_owner_can_create_bucket(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expense-buckets", [
                'name' => 'Hosting',
                'currency' => 'eur',
                'color' => '#aabbcc',
            ]);

        $response->assertCreated()
            ->assertJsonPath('bucket.name', 'Hosting')
            ->assertJsonPath('bucket.currency', 'EUR')
            ->assertJsonPath('bucket.is_default', false);
    }

    public function test_default_bucket_cannot_be_deleted(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $default = ExpenseBucket::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expense-buckets/{$default->id}")
            ->assertStatus(422);
    }

    public function test_owner_can_delete_non_default_bucket(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Disposable',
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expense-buckets/{$bucket->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('expense_buckets', ['id' => $bucket->id]);
    }

    public function test_outsider_cannot_view_bucket(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}")
            ->assertForbidden();
    }

    public function test_scoped_bucket_grant_cannot_mutate_other_buckets(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $bucketA = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'A',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);
        $bucketB = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);

        // Project-level viewer + specific editor on bucketA only.
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);
        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $bucketA->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($scoped)
            ->patchJson("/api/v1/expense-buckets/{$bucketA->id}", ['name' => 'A (edited)'])
            ->assertOk();

        $this->actingAs($scoped)
            ->patchJson("/api/v1/expense-buckets/{$bucketB->id}", ['name' => 'B (hijacked)'])
            ->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Expenses;

use App\Models\Expenses\ExpenseBucket;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseBucketCurrencyTest extends TestCase
{
    public function test_bucket_can_be_created_without_currency(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expense-buckets", [
                'name' => 'No-default-currency bucket',
                'currency' => null,
            ]);

        $response->assertCreated()
            ->assertJsonPath('bucket.name', 'No-default-currency bucket')
            ->assertJsonPath('bucket.currency', null);
    }

    public function test_bucket_currency_can_be_cleared_via_patch(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Will lose currency',
            'currency' => 'USD',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patchJson("/api/v1/expense-buckets/{$bucket->id}", [
                'currency' => null,
            ])
            ->assertOk()
            ->assertJsonPath('bucket.currency', null);

        $this->assertSame(null, $bucket->fresh()->currency);
    }
}

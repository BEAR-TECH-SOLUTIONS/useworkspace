<?php

namespace Tests\Feature\Expenses;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseAnalyticsTest extends TestCase
{
    public function test_summary_returns_correct_totals_for_current_month(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Hosting',
            'category' => 'hosting',
            'amount' => '50.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Domain',
            'category' => 'domain',
            'amount' => '120.00',
            'currency' => 'USD',
            'billing_cycle' => 'yearly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=month&currency=USD")
            ->assertOk();

        $this->assertSame('month', $response->json('period'));
        $this->assertSame('170.00', $response->json('total_amount'));
        $this->assertSame(2, $response->json('total_count'));
    }

    public function test_summary_monthly_recurring_normalizes_quarterly_and_yearly(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        // monthly: 30.00 → 30.00
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'A',
            'category' => 'saas',
            'amount' => '30.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        // quarterly: 90.00 → 30.00
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'B',
            'category' => 'saas',
            'amount' => '90.00',
            'currency' => 'USD',
            'billing_cycle' => 'quarterly',
            'created_by' => $owner->id,
        ]);

        // yearly: 120.00 → 10.00
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'C',
            'category' => 'domain',
            'amount' => '120.00',
            'currency' => 'USD',
            'billing_cycle' => 'yearly',
            'created_by' => $owner->id,
        ]);

        // one_time: excluded from monthly_recurring
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'D',
            'category' => 'hardware',
            'amount' => '500.00',
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=USD")
            ->assertOk();

        // 30 + 30 + 10 = 70.00
        $this->assertSame('70.00', $response->json('monthly_recurring'));
    }

    public function test_trend_returns_correct_month_count(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/trend?months=3&currency=USD")
            ->assertOk();

        $this->assertCount(3, $response->json('months'));
    }

    public function test_upcoming_with_days_filter(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Due soon',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDays(3)->toDateString(),
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Due later',
            'category' => 'saas',
            'amount' => '20.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDays(30)->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/upcoming?days=7")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Due soon', $response->json('data.0.name'));
    }

    public function test_list_period_filter_includes_recurring_and_excludes_old_onetime(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->nonDefaultBucket($project, $owner);

        // Recurring created long ago with no due date → included.
        Expense::forceCreate([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Active subscription',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        // One-time created last year → excluded from current month.
        Expense::forceCreate([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Old purchase',
            'category' => 'hardware',
            'amount' => '500.00',
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'created_by' => $owner->id,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses?period=month")
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('Active subscription', $names);
        $this->assertNotContains('Old purchase', $names);
    }

    public function test_pattern_b_user_only_sees_own_bucket_summary(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $granted = $this->nonDefaultBucket($project, $owner);
        $other = $this->nonDefaultBucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $granted->id,
            'name' => 'Visible',
            'category' => 'saas',
            'amount' => '25.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $other->id,
            'name' => 'Hidden',
            'category' => 'saas',
            'amount' => '100.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Bucket->value,
            'resource_id' => $granted->id,
            'project_id' => $project->id,
            'role' => MemberRole::Viewer->value,
            'granted_by' => $owner->id,
        ]);

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=USD")
            ->assertOk();

        $this->assertSame('25.00', $response->json('total_amount'));
        $this->assertSame(1, $response->json('total_count'));
    }

    private function nonDefaultBucket($project, $owner): ExpenseBucket
    {
        return ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Bucket '.bin2hex(random_bytes(3)),
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }
}

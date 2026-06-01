<?php

namespace Tests\Feature\Expenses;

use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseForecastTest extends TestCase
{
    public function test_monthly_expense_appears_in_every_projected_month(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Hosting',
            'category' => 'hosting',
            'amount' => '100.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=3&currency=USD")
            ->assertOk();

        $months = $response->json('months');
        $this->assertCount(3, $months);

        foreach ($months as $m) {
            $this->assertSame('100.00', $m['projected_total']);
            $this->assertSame(1, $m['expense_count']);
        }
    }

    public function test_quarterly_expense_lands_on_cycle_months_only(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        // Due next month from now — should land at months 1, 4, 7, 10
        // of a 12-month forecast window.
        $nextMonth = now()->addMonth()->startOfMonth();

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Quarterly SaaS',
            'category' => 'saas',
            'amount' => '300.00',
            'currency' => 'USD',
            'billing_cycle' => 'quarterly',
            'next_due_date' => $nextMonth->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=12&currency=USD")
            ->assertOk();

        $months = $response->json('months');
        $this->assertCount(12, $months);

        $hitMonths = collect($months)->filter(fn ($m) => $m['projected_total'] !== '0.00');
        // Should be 4 hits: months 1, 4, 7, 10
        $this->assertCount(4, $hitMonths);

        foreach ($hitMonths as $m) {
            $this->assertSame('300.00', $m['projected_total']);
        }
    }

    public function test_yearly_expense_lands_once_in_12_month_window(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        // Due 6 months from now.
        $dueDate = now()->addMonths(6)->startOfMonth();

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Annual license',
            'category' => 'software',
            'amount' => '1200.00',
            'currency' => 'USD',
            'billing_cycle' => 'yearly',
            'next_due_date' => $dueDate->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=12&currency=USD")
            ->assertOk();

        $hits = collect($response->json('months'))
            ->filter(fn ($m) => $m['projected_total'] !== '0.00');

        $this->assertCount(1, $hits);
        $this->assertSame('1200.00', $hits->first()['projected_total']);
    }

    public function test_one_time_expenses_are_excluded(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'One-off purchase',
            'category' => 'hardware',
            'amount' => '500.00',
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=3&currency=USD")
            ->assertOk();

        foreach ($response->json('months') as $m) {
            $this->assertSame('0.00', $m['projected_total']);
            $this->assertSame(0, $m['expense_count']);
        }
    }

    public function test_bucket_id_filter_scopes_correctly(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucketA = $this->bucket($project, $owner);
        $bucketB = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucketA->id,
            'name' => 'A',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucketB->id,
            'name' => 'B',
            'category' => 'saas',
            'amount' => '90.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=1&bucket_id={$bucketA->id}&currency=USD")
            ->assertOk();

        $this->assertSame('10.00', $response->json('months.0.projected_total'));
    }

    public function test_past_due_date_is_rolled_forward(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        // Quarterly expense with due date 2 months ago. Rolled forward
        // by 3 → lands 1 month from now.
        $pastDue = now()->subMonths(2)->startOfMonth();

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Overdue quarterly',
            'category' => 'saas',
            'amount' => '300.00',
            'currency' => 'USD',
            'billing_cycle' => 'quarterly',
            'next_due_date' => $pastDue->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=6&currency=USD")
            ->assertOk();

        // Should have at least one hit (the rolled-forward occurrence).
        $hits = collect($response->json('months'))
            ->filter(fn ($m) => $m['projected_total'] !== '0.00');

        $this->assertGreaterThanOrEqual(1, $hits->count());
        $this->assertSame('300.00', $hits->first()['projected_total']);
    }

    public function test_breakdown_is_included_for_small_expense_sets(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Small set',
            'category' => 'saas',
            'amount' => '25.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/forecast?months=1&currency=USD")
            ->assertOk();

        $month = $response->json('months.0');
        $this->assertArrayHasKey('breakdown', $month);
        $this->assertCount(1, $month['breakdown']);
        $this->assertSame('Small set', $month['breakdown'][0]['name']);
    }

    private function bucket($project, $owner): ExpenseBucket
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

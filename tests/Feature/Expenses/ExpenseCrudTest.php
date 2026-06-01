<?php

namespace Tests\Feature\Expenses;

use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseCrudTest extends TestCase
{
    public function test_owner_can_create_expense(): void
    {
        [$owner, $project, $bucket] = $this->setupExpense();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'bucket_id' => $bucket->id,
                'name' => 'AWS EC2',
                'description' => 'Production servers',
                'category' => 'hosting',
                'amount' => '249.99',
                'currency' => 'usd',
                'billing_cycle' => 'monthly',
                'vendor' => 'Amazon',
                'next_due_date' => Carbon::today()->addDays(15)->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('expense.name', 'AWS EC2')
            ->assertJsonPath('expense.category', 'hosting')
            ->assertJsonPath('expense.billing_cycle', 'monthly')
            ->assertJsonPath('expense.currency', 'USD')
            ->assertJsonPath('expense.amount', '249.99');

        $this->assertDatabaseHas('expenses', [
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'AWS EC2',
        ]);
    }

    public function test_index_filters_by_category(): void
    {
        [$owner, $project, $bucket] = $this->setupExpense();

        Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id, ['name' => 'EC2', 'category' => 'hosting']));
        Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id, ['name' => 'GitHub', 'category' => 'saas']));

        $names = collect($this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses?category=hosting")
            ->assertOk()
            ->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('EC2', $names);
        $this->assertNotContains('GitHub', $names);
    }

    public function test_upcoming_returns_only_expenses_in_window(): void
    {
        [$owner, $project, $bucket] = $this->setupExpense();

        Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id, [
            'name' => 'Soon',
            'next_due_date' => Carbon::today()->addDays(5),
        ]));
        Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id, [
            'name' => 'Later',
            'next_due_date' => Carbon::today()->addDays(120),
        ]));
        Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id, [
            'name' => 'No date',
            'next_due_date' => null,
        ]));

        $names = collect($this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/upcoming?days=30")
            ->assertOk()
            ->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('Soon', $names);
        $this->assertNotContains('Later', $names);
        $this->assertNotContains('No date', $names);
    }

    public function test_owner_can_update_and_delete_expense(): void
    {
        [$owner, $project, $bucket] = $this->setupExpense();
        $expense = Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id));

        $this->actingAs($owner)
            ->patchJson("/api/v1/expenses/{$expense->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('expense.name', 'Renamed');

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expenses/{$expense->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_outsider_cannot_create_or_view_expense(): void
    {
        [$owner, $project, $bucket] = $this->setupExpense();
        $expense = Expense::create($this->expenseAttrs($owner, $project->id, $bucket->id));

        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'bucket_id' => $bucket->id,
                'name' => 'no',
                'category' => 'other',
                'amount' => '1.00',
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Project, 2: ExpenseBucket}
     */
    private function setupExpense(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        return [$owner, $project, $bucket];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function expenseAttrs(User $owner, int $projectId, int $bucketId, array $overrides = []): array
    {
        return array_merge([
            'project_id' => $projectId,
            'bucket_id' => $bucketId,
            'name' => 'Seeded',
            'category' => 'other',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::today()->addDays(7),
            'created_by' => $owner->id,
        ], $overrides);
    }
}

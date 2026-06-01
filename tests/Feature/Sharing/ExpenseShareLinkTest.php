<?php

namespace Tests\Feature\Sharing;

use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Expenses\ExpensePayment;
use App\Models\User;
use App\Models\Vault\ShareLink;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Expense snapshots include the payments collection. created_by is
 * excluded.
 */
class ExpenseShareLinkTest extends TestCase
{
    public function test_owner_can_share_an_expense_with_payments(): void
    {
        [$owner, $expense] = $this->seedExpenseWithPayments();

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', [
                'resource_type' => 'expense',
                'resource_id' => $expense->id,
                'token_hash' => hash('sha256', 'tok-expense'),
                'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('share_link.resource_type', 'expense');

        $payload = ShareLink::query()
            ->where('token_hash', hash('sha256', 'tok-expense'))
            ->firstOrFail()
            ->snapshot_payload;

        $this->assertSame($expense->id, $payload['id']);
        $this->assertSame('AWS prod hosting', $payload['name']);
        $this->assertSame('USD', $payload['currency']);

        // Two payments, both serialised; created_by absent.
        $this->assertCount(2, $payload['payments']);
        $this->assertArrayNotHasKey('created_by', $payload);
        $this->assertArrayNotHasKey('created_by', $payload['payments'][0]);
    }

    /**
     * @return array{0: User, 1: Expense}
     */
    private function seedExpenseWithPayments(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'AWS prod hosting',
            'category' => 'hosting',
            'amount' => '412.50',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'vendor' => 'Amazon Web Services',
            'next_due_date' => Carbon::now()->addDays(7)->toDateString(),
            'created_by' => $owner->id,
        ]);

        ExpensePayment::create([
            'expense_id' => $expense->id,
            'amount' => '412.50',
            'currency' => 'USD',
            'paid_at' => Carbon::now()->subDays(30)->toDateString(),
            'note' => 'March',
            'created_by' => $owner->id,
        ]);

        ExpensePayment::create([
            'expense_id' => $expense->id,
            'amount' => '412.50',
            'currency' => 'USD',
            'paid_at' => Carbon::now()->subDays(60)->toDateString(),
            'note' => 'February',
            'created_by' => $owner->id,
        ]);

        return [$owner, $expense];
    }
}

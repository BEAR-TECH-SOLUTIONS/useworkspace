<?php

namespace Tests\Feature\Expenses;

use App\Enums\BillingCycle;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * #220 — `expenses:auto-pay` records payments for recurring expenses
 * flagged `auto_mark_paid`, and the create/update API coerces the flag
 * to false for one-time expenses.
 */
class AutoPayExpenseTest extends TestCase
{
    public function test_auto_pay_records_payment_and_advances_due_date(): void
    {
        // "Today" is 2026-06-15; the monthly expense was last due 2026-05-29,
        // i.e. exactly one cycle in the past.
        $this->travelTo(Carbon::parse('2026-06-15'));
        [$owner, $expense] = $this->makeExpense(BillingCycle::Monthly, '2026-05-29', autoMarkPaid: true);

        $this->artisan('expenses:auto-pay')->assertSuccessful();

        $expense->refresh();
        // One cycle paid, due date advanced one month from the OLD due date.
        $this->assertSame(1, $expense->payments()->count());
        $this->assertSame('2026-06-29', $expense->next_due_date->toDateString());

        $payment = $expense->payments()->first();
        // paid_at is the cycle's own due date (no drift), amount snapshotted.
        $this->assertSame('2026-05-29', Carbon::parse($payment->paid_at)->toDateString());
        $this->assertSame($expense->created_by, $payment->created_by);
    }

    public function test_auto_pay_catches_up_multiple_overdue_cycles(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15'));
        [, $expense] = $this->makeExpense(BillingCycle::Monthly, '2026-03-29', autoMarkPaid: true);

        $this->artisan('expenses:auto-pay')->assertSuccessful();

        $expense->refresh();
        // 03-29, 04-29, 05-29 are all <= today → three payments; next is in the future.
        $this->assertSame(3, $expense->payments()->count());
        $this->assertSame('2026-06-29', $expense->next_due_date->toDateString());

        $earliest = $expense->payments()->orderBy('paid_at')->first();
        $this->assertSame('2026-03-29', Carbon::parse($earliest->paid_at)->toDateString());
    }

    public function test_auto_pay_ignores_expenses_with_flag_off(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15'));
        [, $expense] = $this->makeExpense(BillingCycle::Monthly, '2026-05-29', autoMarkPaid: false);

        $this->artisan('expenses:auto-pay')->assertSuccessful();

        $expense->refresh();
        $this->assertSame(0, $expense->payments()->count());
        // Untouched by auto-pay (the hourly roll command handles its date instead).
        $this->assertSame('2026-05-29', $expense->next_due_date->toDateString());
    }

    public function test_auto_pay_ignores_future_due_dates(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15'));
        [, $expense] = $this->makeExpense(BillingCycle::Monthly, '2026-07-01', autoMarkPaid: true);

        $this->artisan('expenses:auto-pay')->assertSuccessful();

        $this->assertSame(0, $expense->refresh()->payments()->count());
    }

    public function test_create_coerces_auto_mark_paid_to_false_for_one_time(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'bucket_id' => $bucket->id,
                'name' => 'One off',
                'category' => 'saas',
                'amount' => '10.00',
                'currency' => 'USD',
                'billing_cycle' => BillingCycle::OneTime->value,
                'auto_mark_paid' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('expense.auto_mark_paid', false);
    }

    public function test_create_persists_auto_mark_paid_for_recurring(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'bucket_id' => $bucket->id,
                'name' => 'Netflix',
                'category' => 'saas',
                'amount' => '15.99',
                'currency' => 'USD',
                'billing_cycle' => BillingCycle::Monthly->value,
                'auto_mark_paid' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('expense.auto_mark_paid', true);
    }

    public function test_switching_to_one_time_drops_auto_mark_paid(): void
    {
        [$owner, $expense] = $this->makeExpense(BillingCycle::Monthly, '2026-05-29', autoMarkPaid: true);

        $this->actingAs($owner)
            ->patchJson("/api/v1/expenses/{$expense->id}", [
                'billing_cycle' => BillingCycle::OneTime->value,
            ])
            ->assertOk()
            ->assertJsonPath('expense.auto_mark_paid', false);

        $this->assertFalse($expense->refresh()->auto_mark_paid);
    }

    private function bucket(Project $project, User $owner): ExpenseBucket
    {
        return ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Test Bucket',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Expense}
     */
    private function makeExpense(BillingCycle $cycle, string $dueDate, bool $autoMarkPaid): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $this->bucket($project, $owner)->id,
            'name' => 'Test Expense',
            'category' => 'saas',
            'amount' => 49.99,
            'currency' => 'USD',
            'billing_cycle' => $cycle->value,
            'next_due_date' => $dueDate,
            'auto_mark_paid' => $autoMarkPaid,
            'created_by' => $owner->id,
        ]);

        return [$owner, $expense];
    }
}

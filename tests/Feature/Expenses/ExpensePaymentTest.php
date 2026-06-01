<?php

namespace Tests\Feature\Expenses;

use App\Enums\BillingCycle;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Expenses\ExpensePayment;
use App\Models\Project\Project;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpensePaymentTest extends TestCase
{
    public function test_pay_advances_monthly_due_date(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'paid_at' => '2026-04-10',
            ])
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', '2026-05-01')
            ->assertJsonPath('payment.amount', number_format((float) $expense->amount, 2, '.', ''))
            ->assertJsonPath('payment.currency', $expense->currency);
    }

    public function test_month_end_handling(): void
    {
        // Jan 31 monthly → Feb 28 (NoOverflow prevents Mar 3).
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-01-31');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', '2026-02-28');
    }

    public function test_weekly_advance(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Weekly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', '2026-04-08');
    }

    public function test_quarterly_advance(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Quarterly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', '2026-07-01');
    }

    public function test_yearly_advance(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Yearly, '2026-04-15');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', '2027-04-15');
    }

    public function test_one_time_sets_null_then_rejects(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::OneTime, '2026-04-15');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', null);

        // Second pay → 409 already_paid.
        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_paid');
    }

    public function test_custom_amount_and_note(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay", [
                'amount' => '123.45',
                'note' => 'Partial payment',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.amount', '123.45')
            ->assertJsonPath('payment.note', 'Partial payment');
    }

    public function test_payment_history_paginated(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-01-01');

        // Record 3 payments.
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($owner)
                ->postJson("/api/v1/expenses/{$expense->id}/pay")
                ->assertCreated();
        }

        $this->actingAs($owner)
            ->getJson("/api/v1/expenses/{$expense->id}/payments?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_delete_latest_reverses_date(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay")
            ->assertCreated();

        $payment = ExpensePayment::query()
            ->where('expense_id', $expense->id)
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expenses/{$expense->id}/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('expense.next_due_date', '2026-04-01');
    }

    public function test_cannot_delete_non_latest_payment(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-01-01');

        $this->actingAs($owner)->postJson("/api/v1/expenses/{$expense->id}/pay")->assertCreated();
        $firstPayment = ExpensePayment::query()->where('expense_id', $expense->id)->oldest('id')->firstOrFail();

        $this->actingAs($owner)->postJson("/api/v1/expenses/{$expense->id}/pay")->assertCreated();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expenses/{$expense->id}/payments/{$firstPayment->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_latest_payment');
    }

    public function test_expense_aggregates_present(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::Monthly, '2026-04-01');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay", ['amount' => '50.00'])
            ->assertCreated();

        $this->actingAs($owner)
            ->getJson("/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonPath('expense.payment_count', 1)
            ->assertJsonPath('expense.total_paid', '50.00');
    }

    public function test_delete_one_time_restores_paid_at(): void
    {
        [$owner, $expense] = $this->setupExpense(BillingCycle::OneTime, '2026-04-15');

        $this->actingAs($owner)
            ->postJson("/api/v1/expenses/{$expense->id}/pay", ['paid_at' => '2026-04-20'])
            ->assertCreated()
            ->assertJsonPath('expense.next_due_date', null);

        $payment = ExpensePayment::query()
            ->where('expense_id', $expense->id)
            ->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson("/api/v1/expenses/{$expense->id}/payments/{$payment->id}")
            ->assertOk()
            ->assertJsonPath('expense.next_due_date', '2026-04-20');
    }

    /**
     * @return array{0: \App\Models\User, 1: Expense}
     */
    private function setupExpense(BillingCycle $cycle, string $dueDate): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Test Bucket',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);
        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Test Expense',
            'category' => 'saas',
            'amount' => 49.99,
            'currency' => 'USD',
            'billing_cycle' => $cycle->value,
            'next_due_date' => $dueDate,
            'created_by' => $owner->id,
        ]);

        return [$owner, $expense];
    }
}

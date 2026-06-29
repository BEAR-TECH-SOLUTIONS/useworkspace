<?php

namespace Tests\Feature\Expenses;

use App\Enums\BillingCycle;
use App\Enums\NotificationType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Expenses\ExpensePayment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * #213A — `notifications:expense-overdue`. Overdue is read from payment
 * history (the elapsed cycle = next_due_date minus one cycle), not from
 * next_due_date being in the past, because the hourly roll job keeps
 * next_due_date in the future.
 */
class ExpenseOverdueNotificationTest extends TestCase
{
    public function test_fires_for_unpaid_elapsed_cycle(): void
    {
        // Created 2026-01-01; "today" is 2026-06-15. Monthly, next due
        // 2026-06-29 → most recent elapsed occurrence is 2026-05-29, which
        // is in the past and after creation, and has no payment.
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::Monthly,
            nextDue: '2026-06-29',
            createdAt: '2026-01-01',
            autoMarkPaid: false,
        );

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $rows = Notification::query()
            ->where('user_id', $owner->id)
            ->where('type', NotificationType::ExpenseOverdue->value)
            ->where('resource_id', $expense->id)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-05-29', $rows->first()->metadata['due_date']);
        $this->assertSame($expense->bucket_id, $rows->first()->metadata['bucket_id']);
    }

    public function test_suppressed_when_cycle_was_paid(): void
    {
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::Monthly,
            nextDue: '2026-06-29',
            createdAt: '2026-01-01',
            autoMarkPaid: false,
        );

        // A payment on/after the elapsed due date settles the cycle.
        ExpensePayment::create([
            'expense_id' => $expense->id,
            'paid_at' => '2026-05-30',
            'amount' => $expense->amount,
            'currency' => $expense->currency,
            'created_by' => $owner->id,
        ]);

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $this->assertSame(0, $this->overdueCount($owner, $expense));
    }

    public function test_suppressed_for_auto_mark_paid(): void
    {
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::Monthly,
            nextDue: '2026-06-29',
            createdAt: '2026-01-01',
            autoMarkPaid: true,
        );

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $this->assertSame(0, $this->overdueCount($owner, $expense));
    }

    public function test_suppressed_for_one_time(): void
    {
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::OneTime,
            nextDue: '2026-05-29',
            createdAt: '2026-01-01',
            autoMarkPaid: false,
        );

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $this->assertSame(0, $this->overdueCount($owner, $expense));
    }

    public function test_not_fired_for_new_expense_whose_first_occurrence_is_future(): void
    {
        // Created 2026-06-10, next due 2026-07-01. The "elapsed" date
        // (2026-06-01) predates creation, so no cycle was actually missed.
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::Monthly,
            nextDue: '2026-07-01',
            createdAt: '2026-06-10',
            autoMarkPaid: false,
        );

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $this->assertSame(0, $this->overdueCount($owner, $expense));
    }

    public function test_deduped_once_per_cycle(): void
    {
        [$owner, $expense] = $this->makeExpense(
            cycle: BillingCycle::Monthly,
            nextDue: '2026-06-29',
            createdAt: '2026-01-01',
            autoMarkPaid: false,
        );

        $this->travelTo(Carbon::parse('2026-06-15'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();
        // Second run on a later day within the same cycle must not re-notify.
        $this->travelTo(Carbon::parse('2026-06-20'));
        $this->artisan('notifications:expense-overdue')->assertSuccessful();

        $this->assertSame(1, $this->overdueCount($owner, $expense));
    }

    private function overdueCount(User $owner, Expense $expense): int
    {
        return Notification::query()
            ->where('user_id', $owner->id)
            ->where('type', NotificationType::ExpenseOverdue->value)
            ->where('resource_id', $expense->id)
            ->count();
    }

    /**
     * @return array{0: User, 1: Expense}
     */
    private function makeExpense(BillingCycle $cycle, string $nextDue, string $createdAt, bool $autoMarkPaid): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'Infra',
            'currency' => 'USD',
            'color' => '#aaa',
            'created_by' => $owner->id,
        ]);

        // Create with a backdated created_at so the elapsed cycle falls
        // within the expense's lifetime.
        $expense = new Expense([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Server',
            'category' => 'saas',
            'amount' => 49.99,
            'currency' => 'USD',
            'billing_cycle' => $cycle->value,
            'next_due_date' => $nextDue,
            'auto_mark_paid' => $autoMarkPaid,
            'created_by' => $owner->id,
        ]);
        $expense->created_at = $createdAt;
        $expense->save();

        return [$owner, $expense->refresh()];
    }
}

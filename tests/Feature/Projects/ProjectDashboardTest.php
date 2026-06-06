<?php

namespace Tests\Feature\Projects;

use App\Enums\ActivityAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ProjectDashboardTest extends TestCase
{
    public function test_returns_correct_my_task_count(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $column = $this->column($project, $owner);

        $this->createAssignedTask($project, $column, $owner, ['title' => 'A']);
        $this->createAssignedTask($project, $column, $owner, ['title' => 'B']);
        $this->createAssignedTask($project, $column, $owner, ['title' => 'C']);
        // Completed task should NOT count.
        $this->createAssignedTask($project, $column, $owner, [
            'title' => 'Done',
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertSame(3, $response->json('stats.my_task_count'));
    }

    public function test_overdue_only_includes_past_due_assigned_to_caller(): void
    {
        $owner = UserFactory::create();
        $other = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $column = $this->column($project, $owner);

        // Overdue + assigned to owner → included.
        $this->createAssignedTask($project, $column, $owner, [
            'title' => 'Mine overdue',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        // Overdue but assigned to someone else → excluded from owner's overdue.
        $otherTask = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Not mine',
            'due_date' => now()->subDay()->toDateString(),
            'created_by' => $owner->id,
        ]);
        DB::table('task_assignees')->insert([
            'task_item_id' => $otherTask->id,
            'user_id' => $other->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $overdue = $response->json('my_tasks.overdue');
        $this->assertCount(1, $overdue);
        $this->assertSame('Mine overdue', $overdue[0]['title']);
    }

    public function test_completed_this_week_counts_all_users(): void
    {
        $owner = UserFactory::create();
        $other = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $column = $this->column($project, $owner);

        // Give other user access.
        ResourcePermission::create([
            'user_id' => $other->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // Owner completes a task.
        TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Owner done',
            'is_completed' => true,
            'completed_at' => now(),
            'created_by' => $owner->id,
        ]);

        // Other user completes a task.
        TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Other done',
            'is_completed' => true,
            'completed_at' => now(),
            'created_by' => $other->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertSame(2, $response->json('stats.completed_this_week'));
    }

    public function test_monthly_burn_normalizes_correctly(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        // 30 monthly + 90/3 quarterly + 120/12 yearly = 30+30+10 = 70
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'M',
            'category' => 'saas',
            'amount' => '30.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Q',
            'category' => 'saas',
            'amount' => '90.00',
            'currency' => 'USD',
            'billing_cycle' => 'quarterly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Y',
            'category' => 'domain',
            'amount' => '120.00',
            'currency' => 'USD',
            'billing_cycle' => 'yearly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertSame('70.00', $response->json('stats.monthly_burn'));
        $this->assertSame('USD', $response->json('stats.monthly_burn_currency'));
        $this->assertSame('native', $response->json('stats.monthly_burn_fx_status'));
    }

    public function test_monthly_burn_converts_mixed_currencies_before_summing(): void
    {
        // Regression for the bug where a project mixing EUR + RUB
        // (or any non-uniform currency) summed the raw amounts and
        // mislabeled the total as whichever currency happened to be
        // most common. The dashboard now FX-converts each amount
        // into the majority currency before summing, so a RUB
        // amortization sits on the same scale as the EUR one.
        $this->fakeFxResponse(['USD' => '2.00']);  // 1 EUR = 2 USD

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'EUR',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        // Majority is EUR (2 rows EUR vs 1 USD). Two EUR monthlies sum
        // to 30 EUR; one 60 USD monthly converts to 30 EUR. Expected:
        // 60.00 EUR total.
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Eur monthly A',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Eur monthly B',
            'category' => 'saas',
            'amount' => '20.00',
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Usd monthly',
            'category' => 'saas',
            'amount' => '60.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertSame('60.00', $response->json('stats.monthly_burn'));
        $this->assertSame('EUR', $response->json('stats.monthly_burn_currency'));
        $this->assertSame('converted', $response->json('stats.monthly_burn_fx_status'));
    }

    public function test_monthly_burn_marks_partial_when_fx_unavailable_for_some_rows(): void
    {
        // Upstream rate-book returns USD only; RUB is missing, so the
        // dashboard drops the RUB row rather than mislabel the sum.
        // Status flips to 'partial' so the client can show a hint
        // that the headline number is a floor.
        $this->fakeFxResponse(['USD' => '2.00']);  // 1 EUR = 2 USD; no RUB

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'EUR',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Eur monthly',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Rub yearly (unsupported FX)',
            'category' => 'domain',
            'amount' => '5355.00',
            'currency' => 'RUB',
            'billing_cycle' => 'yearly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        // Only the EUR row contributes; RUB is dropped.
        $this->assertSame('10.00', $response->json('stats.monthly_burn'));
        $this->assertSame('EUR', $response->json('stats.monthly_burn_currency'));
        $this->assertSame('partial', $response->json('stats.monthly_burn_fx_status'));
    }

    public function test_monthly_burn_excludes_one_time_expenses(): void
    {
        // ONE-TIME is not recurring; it must not contribute to the
        // monthly burn even on a single-currency project. (Sanity
        // check on the WHERE billing_cycle != 'one_time' clause that
        // would otherwise be invisible since pre-existing tests only
        // use recurring rows.)
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Recurring',
            'category' => 'saas',
            'amount' => '15.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'One-time purchase',
            'category' => 'hosting',
            'amount' => '299.00',
            'currency' => 'USD',
            'billing_cycle' => 'one_time',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertSame('15.00', $response->json('stats.monthly_burn'));
    }

    /**
     * Stub exchangeratesapi.io with a deterministic rate-book —
     * matches the harness used by ExpenseFxConversionTest so the
     * same Http::fake contract works here.
     *
     * @param  array<string, int|float|string>  $rates
     */
    private function fakeFxResponse(array $rates): void
    {
        Http::fake([
            'api.exchangeratesapi.io/*' => Http::response([
                'success' => true,
                'timestamp' => now()->timestamp,
                'base' => 'EUR',
                'date' => now()->toDateString(),
                'rates' => $rates,
            ], 200),
        ]);
    }

    public function test_upcoming_expenses_sorted_by_due_date(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'B',
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Later',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDays(10)->toDateString(),
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Sooner',
            'category' => 'saas',
            'amount' => '20.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addDays(3)->toDateString(),
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $names = array_column($response->json('upcoming_expenses'), 'name');
        $this->assertSame(['Sooner', 'Later'], $names);
    }

    public function test_activity_feed_capped_at_limit(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->first();

        for ($i = 0; $i < 25; $i++) {
            TaskActivity::create([
                'project_id' => $project->id,
                'board_id' => $board->id,
                'user_id' => $owner->id,
                'action' => ActivityAction::Created->value,
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $this->assertCount(20, $response->json('recent_activity'));
    }

    public function test_pattern_b_user_only_sees_scoped_data(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        // Create a second board that scoped user gets access to.
        $grantedBoard = TaskBoard::create([
            'project_id' => $project->id,
            'name' => 'Granted board',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
        $grantedCol = TaskColumn::create([
            'board_id' => $grantedBoard->id,
            'name' => 'Col',
            'position' => 10000,
        ]);

        ResourcePermission::create([
            'user_id' => $scoped->id,
            'resource_type' => ResourceType::Board->value,
            'resource_id' => $grantedBoard->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        // Task on granted board.
        $this->createAssignedTask($project, $grantedCol, $scoped, ['title' => 'Visible']);

        // Task on the default board (not granted).
        $defaultBoard = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->first();
        $defaultCol = TaskColumn::query()->where('board_id', $defaultBoard->id)->first();
        $this->createAssignedTask($project, $defaultCol, $scoped, ['title' => 'Hidden']);

        $response = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        // Only the granted board's tasks visible.
        $this->assertSame(1, $response->json('stats.my_task_count'));
        $this->assertSame(1, $response->json('stats.total_task_count'));
    }

    public function test_active_today_only_shows_todays_actors(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->first();

        // Action today.
        TaskActivity::create([
            'project_id' => $project->id,
            'board_id' => $board->id,
            'user_id' => $owner->id,
            'action' => ActivityAction::Created->value,
            'created_at' => now(),
        ]);

        // Action yesterday — should NOT appear in active_today.
        $yesterday = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $yesterday->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);
        TaskActivity::create([
            'project_id' => $project->id,
            'board_id' => $board->id,
            'user_id' => $yesterday->id,
            'action' => ActivityAction::Created->value,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk();

        $activeIds = array_column($response->json('team.active_today'), 'id');
        $this->assertContains($owner->id, $activeIds);
        $this->assertNotContains($yesterday->id, $activeIds);
    }

    // ── Helpers ──

    private function column($project, $owner): TaskColumn
    {
        $board = TaskBoard::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->first();

        return TaskColumn::query()->where('board_id', $board->id)->first();
    }

    private function createAssignedTask($project, TaskColumn $column, $user, array $overrides = []): TaskItem
    {
        $task = TaskItem::create(array_merge([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Task '.bin2hex(random_bytes(3)),
            'created_by' => $user->id,
        ], $overrides));

        DB::table('task_assignees')->insert([
            'task_item_id' => $task->id,
            'user_id' => $user->id,
        ]);

        return $task;
    }
}

<?php

namespace Tests\Feature\Expenses;

use App\Enums\ActivityAction;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Tasks\TaskActivity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Coverage for the payment_type / payment_method_other / url additions
 * to expenses + the polymorphic-token filter on
 * GET /api/v1/expense-buckets/{bucket}.
 */
class ExpensePaymentTypeTest extends TestCase
{
    public function test_create_expense_with_all_new_fields_roundtrips(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => 'card',
                'payment_method_other' => null,
                'url' => 'https://billing.example.com',
            ]));

        $response->assertCreated()
            ->assertJsonPath('expense.payment_type', 'card')
            ->assertJsonPath('expense.payment_method_other', null)
            ->assertJsonPath('expense.url', 'https://billing.example.com');
    }

    public function test_other_without_other_field_is_rejected(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => 'other',
            ]))
            ->assertStatus(422)
            ->assertJsonFragment(['payment_method_other' => ['payment_method_other_only_for_other']]);
    }

    public function test_other_with_empty_string_is_rejected(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => 'other',
                'payment_method_other' => '',
            ]))
            ->assertStatus(422)
            ->assertJsonFragment(['payment_method_other' => ['payment_method_other_only_for_other']]);
    }

    public function test_card_with_other_field_is_rejected(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => 'card',
                'payment_method_other' => 'visa 1234',
            ]))
            ->assertStatus(422)
            ->assertJsonFragment(['payment_method_other' => ['payment_method_other_only_for_other']]);
    }

    public function test_null_type_with_other_field_is_rejected(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => null,
                'payment_method_other' => 'something',
            ]))
            ->assertStatus(422)
            ->assertJsonFragment(['payment_method_other' => ['payment_method_other_only_for_other']]);
    }

    public function test_url_accepts_http_and_https(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        foreach (['https://example.com', 'http://localhost:3000'] as $url) {
            $this->actingAs($owner)
                ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                    'name' => 'expense for '.$url,
                    'url' => $url,
                ]))
                ->assertCreated();
        }
    }

    public function test_url_rejects_dangerous_schemes_and_too_long(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        foreach (['javascript:alert(1)', 'mailto:x@y', 'data:text/html,<script>'] as $bad) {
            $this->actingAs($owner)
                ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                    'name' => 'expense for '.$bad,
                    'url' => $bad,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['url']);
        }

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'url' => 'https://example.com/'.str_repeat('a', 600),
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    public function test_payment_type_change_writes_activity_row_with_arrow_detail(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        $expense = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$bucket->project_id}/expenses", $this->payload($bucket, [
                'payment_type' => 'card',
            ]))
            ->json('expense');

        $this->actingAs($owner)
            ->patchJson("/api/v1/expenses/{$expense['id']}", [
                'payment_type' => 'paypal',
            ])
            ->assertOk();

        $activity = TaskActivity::query()
            ->where('action', ActivityAction::ExpensePaymentTypeSet->value)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(['detail' => 'card → paypal'], $activity->meta);
    }

    public function test_filter_payment_types_returns_union(): void
    {
        [$owner, $bucket] = $this->seedBucket();
        $this->seedExpenseWithType($bucket, 'card');
        $this->seedExpenseWithType($bucket, 'paypal');
        $this->seedExpenseWithType($bucket, 'cash');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}?payment_types=card,paypal");

        $response->assertOk();
        $types = collect($response->json('expenses'))->pluck('payment_type')->all();
        sort($types);
        $this->assertSame(['card', 'paypal'], $types);
    }

    public function test_filter_payment_types_null_returns_only_unassigned(): void
    {
        [$owner, $bucket] = $this->seedBucket();
        $this->seedExpenseWithType($bucket, 'card');
        $this->seedExpenseWithType($bucket, null);
        $this->seedExpenseWithType($bucket, null);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}?payment_types=null");

        $response->assertOk();
        $expenses = $response->json('expenses');
        $this->assertCount(2, $expenses);
        foreach ($expenses as $e) {
            $this->assertNull($e['payment_type']);
        }
    }

    public function test_filter_payment_types_card_and_null_returns_both(): void
    {
        [$owner, $bucket] = $this->seedBucket();
        $this->seedExpenseWithType($bucket, 'card');
        $this->seedExpenseWithType($bucket, null);
        $this->seedExpenseWithType($bucket, 'paypal');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}?payment_types=card,null");

        $response->assertOk();
        $types = collect($response->json('expenses'))->pluck('payment_type')->all();
        $this->assertEqualsCanonicalizing(['card', null], $types);
    }

    public function test_filter_tags_or_within_list(): void
    {
        [$owner, $bucket] = $this->seedBucket();
        $this->seedExpenseWithTags($bucket, ['AWS']);
        $this->seedExpenseWithTags($bucket, ['Stripe']);
        $this->seedExpenseWithTags($bucket, ['Other']);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}?tags=AWS");

        $response->assertOk();
        $this->assertCount(1, $response->json('expenses'));
        $this->assertContains('AWS', $response->json('expenses.0.tags'));
    }

    public function test_filter_tags_and_payment_types_combine(): void
    {
        [$owner, $bucket] = $this->seedBucket();

        // (AWS, card) — match
        $awsCard = $this->seedExpenseWithType($bucket, 'card');
        $awsCard->update(['tags' => ['AWS']]);
        // (Stripe, card) — match
        $stripeCard = $this->seedExpenseWithType($bucket, 'card');
        $stripeCard->update(['tags' => ['Stripe']]);
        // (AWS, paypal) — non-match (filter only allows card)
        $awsPaypal = $this->seedExpenseWithType($bucket, 'paypal');
        $awsPaypal->update(['tags' => ['AWS']]);
        // (Other, card) — non-match (tag filter excludes)
        $otherCard = $this->seedExpenseWithType($bucket, 'card');
        $otherCard->update(['tags' => ['Other']]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/expense-buckets/{$bucket->id}?payment_types=card&tags=AWS,Stripe");

        $response->assertOk();
        $ids = collect($response->json('expenses'))->pluck('id')->sort()->values()->all();
        $expected = collect([$awsCard->id, $stripeCard->id])->sort()->values()->all();
        $this->assertSame($expected, $ids);
    }

    /**
     * @return array{0: User, 1: ExpenseBucket}
     */
    private function seedBucket(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        return [$owner, $bucket];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(ExpenseBucket $bucket, array $overrides = []): array
    {
        return array_merge([
            'bucket_id' => $bucket->id,
            'name' => 'AWS prod hosting',
            'category' => 'hosting',
            'amount' => 412.50,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'next_due_date' => Carbon::now()->addDays(7)->toDateString(),
        ], $overrides);
    }

    private function seedExpenseWithType(ExpenseBucket $bucket, ?string $type): Expense
    {
        return Expense::create([
            'project_id' => $bucket->project_id,
            'bucket_id' => $bucket->id,
            'name' => 'expense '.bin2hex(random_bytes(3)),
            'category' => 'hosting',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'payment_type' => $type,
            'payment_method_other' => $type === 'other' ? 'something' : null,
            'created_by' => $bucket->created_by,
        ]);
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function seedExpenseWithTags(ExpenseBucket $bucket, array $tags): Expense
    {
        $expense = $this->seedExpenseWithType($bucket, null);
        $expense->update(['tags' => $tags]);

        return $expense;
    }
}

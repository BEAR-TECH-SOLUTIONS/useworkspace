<?php

namespace Tests\Feature\Expenses;

use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Expenses\ExpensePayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * GET /projects/{project}/expenses/history — one row per PAYMENT
 * (expense_payments), windowed on paid_at, each FX-converted to a
 * chosen currency. The same expense recurs once per payment.
 */
class ExpenseHistoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_returns_one_row_per_payment_not_per_expense(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'EUR');

        // Spotify is paid twice, Domain once → 3 payment rows total.
        $spotify = $this->expense($project, $bucket, $owner, 'Spotify', '9.99', 'EUR');
        $domain = $this->expense($project, $bucket, $owner, 'Domain', '33.99', 'EUR');

        $this->payment($spotify, $owner, '2025-03-01', '5.99', 'EUR');
        $this->payment($spotify, $owner, '2025-02-01', '5.99', 'EUR');
        $this->payment($domain, $owner, '2025-01-12', '33.99', 'EUR');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-01-01&to=2025-03-31")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(3, $data);

        // Newest paid_at first; Spotify appears twice.
        $this->assertSame(['Spotify', 'Spotify', 'Domain'], array_column($data, 'expense_name'));
        $this->assertSame(['2025-03-01', '2025-02-01', '2025-01-12'], array_column($data, 'paid_at'));

        // Row amount is the PAYMENT snapshot (5.99), not the expense's 9.99.
        $this->assertSame('5.99', $data[0]['amount']);
        $this->assertSame($spotify->id, $data[0]['expense_id']);
        $this->assertSame($spotify->id, $data[1]['expense_id']);
        $this->assertSame($domain->id, $data[2]['expense_id']);

        // Parent expense fields copied onto each row.
        $this->assertSame('saas', $data[0]['category']);
        $this->assertSame('monthly', $data[0]['billing_cycle']);
        $this->assertSame('card', $data[0]['payment_type']);
        $this->assertSame('Spotify', $data[0]['vendor']);

        // total = sum of (native) converted amounts.
        $this->assertSame('45.97', $response->json('total'));
        $this->assertSame('EUR', $response->json('currency'));
        $this->assertSame('native', $response->json('currency_source'));
    }

    public function test_window_filters_on_paid_at_not_created_at(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'USD');

        // Created INSIDE the window, but its only payment is OUTSIDE it.
        $createdInside = $this->expense($project, $bucket, $owner, 'Created inside', '10.00', 'USD', '2025-03-15 12:00:00');
        $this->payment($createdInside, $owner, '2025-01-05', '10.00', 'USD');

        // Created OUTSIDE the window, but paid INSIDE it.
        $paidInside = $this->expense($project, $bucket, $owner, 'Paid inside', '20.00', 'USD', '2025-01-01 12:00:00');
        $this->payment($paidInside, $owner, '2025-03-10', '20.00', 'USD');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Paid inside', $data[0]['expense_name']);
        $this->assertSame('2025-03-10', $data[0]['paid_at']);
    }

    public function test_converted_amount_matches_fx_for_mixed_currency_bucket(): void
    {
        // 1 EUR = 2 USD ⇒ 1 USD = 0.5 EUR.
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'EUR');

        $spotify = $this->expense($project, $bucket, $owner, 'Spotify', '5.99', 'EUR');
        $domain = $this->expense($project, $bucket, $owner, 'Domain', '10.00', 'USD');

        $this->payment($spotify, $owner, '2025-03-01', '5.99', 'EUR'); // native
        $this->payment($domain, $owner, '2025-03-02', '10.00', 'USD'); // → 5.00 EUR

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31&currency=EUR")
            ->assertOk();

        $rows = collect($response->json('data'));
        $this->assertSame('5.00', $rows->firstWhere('expense_name', 'Domain')['converted_amount']);
        $this->assertSame('5.99', $rows->firstWhere('expense_name', 'Spotify')['converted_amount']);
        $this->assertSame('converted', $response->json('currency_source'));
        $this->assertSame('10.99', $response->json('total')); // 5.99 + 5.00
    }

    public function test_currency_source_is_native_when_no_payment_differs(): void
    {
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'EUR');

        $spotify = $this->expense($project, $bucket, $owner, 'Spotify', '5.99', 'EUR');
        $this->payment($spotify, $owner, '2025-03-01', '5.99', 'EUR');
        $this->payment($spotify, $owner, '2025-03-02', '5.99', 'EUR');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31&currency=EUR")
            ->assertOk();

        $this->assertSame('native', $response->json('currency_source'));
        $this->assertSame('11.98', $response->json('total'));
    }

    public function test_distinct_expense_id_count_equals_number_of_paid_items(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'EUR');

        $spotify = $this->expense($project, $bucket, $owner, 'Spotify', '5.99', 'EUR');
        $domain = $this->expense($project, $bucket, $owner, 'Domain', '33.99', 'EUR');
        // A third expense with NO payments must not appear at all.
        $this->expense($project, $bucket, $owner, 'Unpaid', '99.00', 'EUR');

        $this->payment($spotify, $owner, '2025-03-01', '5.99', 'EUR');
        $this->payment($spotify, $owner, '2025-02-01', '5.99', 'EUR');
        $this->payment($domain, $owner, '2025-01-12', '33.99', 'EUR');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-01-01&to=2025-03-31")
            ->assertOk();

        $expenseIds = collect($response->json('data'))->pluck('expense_id');
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(2, $expenseIds->unique()->count()); // Spotify + Domain; Unpaid absent
    }

    public function test_unsupported_target_currency_returns_fx_unsupported_currency(): void
    {
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'USD');

        $expense = $this->expense($project, $bucket, $owner, 'Spotify', '100.00', 'USD');
        $this->payment($expense, $owner, '2025-03-01', '100.00', 'USD');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31&currency=ZZZ")
            ->assertStatus(422);

        $this->assertSame('fx_unsupported_currency', $response->json('code'));
        $this->assertSame('ZZZ', $response->json('currency'));
    }

    public function test_returns_502_when_fx_upstream_unreachable_and_no_cache(): void
    {
        Http::fake([
            'api.exchangeratesapi.io/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused'),
        ]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'USD');

        $expense = $this->expense($project, $bucket, $owner, 'EUR item', '10.00', 'EUR');
        $this->payment($expense, $owner, '2025-03-01', '10.00', 'EUR');

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31&currency=USD")
            ->assertStatus(502);

        $this->assertSame('fx_unavailable', $response->json('code'));
    }

    public function test_non_member_gets_403(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner, 'USD');

        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}/expenses/history?bucket_id={$bucket->id}&from=2025-03-01&to=2025-03-31")
            ->assertStatus(403);
    }

    private function expense($project, ExpenseBucket $bucket, $owner, string $name, string $amount, string $currency, ?string $createdAt = null): Expense
    {
        $attrs = [
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => $name,
            'category' => 'saas',
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => 'monthly',
            'payment_type' => 'card',
            'vendor' => $name,
            'created_by' => $owner->id,
        ];
        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
        }

        return Expense::create($attrs);
    }

    private function payment(Expense $expense, $owner, string $paidAt, string $amount, string $currency): ExpensePayment
    {
        return ExpensePayment::create([
            'expense_id' => $expense->id,
            'paid_at' => $paidAt,
            'amount' => $amount,
            'currency' => $currency,
            'created_by' => $owner->id,
        ]);
    }

    /**
     * @param  array<string, int|float>  $rates
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

    private function bucket($project, $owner, string $currency): ExpenseBucket
    {
        return ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'History bucket '.bin2hex(random_bytes(3)),
            'currency' => $currency,
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }
}

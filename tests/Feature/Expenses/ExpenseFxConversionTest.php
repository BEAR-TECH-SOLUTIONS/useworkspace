<?php

namespace Tests\Feature\Expenses;

use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class ExpenseFxConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_expense_post_without_currency_returns_422(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/expenses", [
                'bucket_id' => $bucket->id,
                'name' => 'Hosting',
                'category' => 'hosting',
                'amount' => '50.00',
                'billing_cycle' => 'monthly',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['currency']);
    }

    public function test_summary_converts_mixed_currency_amounts_to_display_currency(): void
    {
        // 1 EUR = 2 USD, so 1 USD = 0.5 EUR.
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'USD expense',
            'category' => 'saas',
            'amount' => '100.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);
        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'EUR expense',
            'category' => 'saas',
            'amount' => '50.00',
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=EUR")
            ->assertOk();

        // 100 USD * 0.5 (EUR per USD) = 50 EUR; plus 50 EUR native = 100 EUR.
        $this->assertSame('100.00', $response->json('total_amount'));
        $this->assertSame('EUR', $response->json('currency'));
        $this->assertSame('converted', $response->json('currency_source'));
    }

    public function test_summary_currency_source_is_native_when_all_expenses_match_display_currency(): void
    {
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'USD only',
            'category' => 'saas',
            'amount' => '42.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=USD")
            ->assertOk();

        $this->assertSame('native', $response->json('currency_source'));
        $this->assertSame('42.00', $response->json('total_amount'));
    }

    public function test_summary_with_unknown_target_currency_returns_fx_unsupported_currency(): void
    {
        // Upstream returns a successful payload that — like exchangeratesapi
        // does for unknown symbols — does not contain the requested ZZZ.
        // USD must be present so the source-side lookup succeeds and the
        // failure surfaces on the requested ZZZ display currency.
        $this->fakeFxResponse(['USD' => 2]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'USD expense',
            'category' => 'saas',
            'amount' => '100.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=ZZZ")
            ->assertStatus(422);

        $this->assertSame('fx_unsupported_currency', $response->json('code'));
        $this->assertSame('ZZZ', $response->json('currency'));
    }

    public function test_summary_returns_502_when_fx_upstream_unreachable_and_no_cache(): void
    {
        Http::fake([
            'api.exchangeratesapi.io/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
                'connection refused'
            ),
        ]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'EUR expense',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'EUR',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=USD")
            ->assertStatus(502);

        $this->assertSame('fx_unavailable', $response->json('code'));
    }

    public function test_summary_falls_back_to_recent_cache_when_upstream_unreachable(): void
    {
        Cache::put('fx:USD:'.now()->subDays(1)->toDateString(), [
            'USD' => '1',
            'EUR' => '0.5',
        ], now()->addHours(25));

        Http::fake([
            'api.exchangeratesapi.io/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
                'connection refused'
            ),
        ]);

        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = $this->bucket($project, $owner);

        Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'USD expense',
            'category' => 'saas',
            'amount' => '10.00',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/expenses/summary?period=all_time&currency=EUR")
            ->assertOk();

        $this->assertSame('5.00', $response->json('total_amount'));
        $this->assertSame('converted', $response->json('currency_source'));
    }

    /**
     * Stub the exchangeratesapi.io /latest endpoint with a fake "success"
     * payload. Rates are quoted as "1 EUR = X target" (the free-tier
     * upstream is locked to EUR base). The base currency is implicitly 1.
     *
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

    private function bucket($project, $owner): ExpenseBucket
    {
        return ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => 'FX bucket '.bin2hex(random_bytes(3)),
            'currency' => 'USD',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
    }
}

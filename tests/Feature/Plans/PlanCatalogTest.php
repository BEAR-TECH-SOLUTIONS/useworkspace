<?php

namespace Tests\Feature\Plans;

use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    public function test_catalog_returns_all_four_plans_with_prices_and_limits(): void
    {
        $response = $this->getJson('/api/v1/plans')->assertOk();

        $plans = collect($response->json('plans'))->keyBy('id');

        $this->assertCount(4, $plans);

        $this->assertSame(0, $plans['free']['price_cents']);
        $this->assertSame('month', $plans['free']['billing_interval']);
        $this->assertSame(2, $plans['free']['limits']['max_members']);
        $this->assertSame(1, $plans['free']['limits']['max_projects']);

        $this->assertSame(999, $plans['entrepreneur']['price_cents']);
        $this->assertSame(10, $plans['entrepreneur']['limits']['max_members']);
        $this->assertNull($plans['entrepreneur']['limits']['max_projects']);

        $this->assertSame(3999, $plans['team']['price_cents']);
        $this->assertSame(100, $plans['team']['limits']['max_members']);
        $this->assertTrue($plans['team']['limits']['can_provision_users']);

        $this->assertSame(19900, $plans['self_hosted']['price_cents']);
        $this->assertSame('year', $plans['self_hosted']['billing_interval']);
        $this->assertNull($plans['self_hosted']['limits']['max_members']);
    }

    public function test_catalog_is_public(): void
    {
        // No auth header — explicit assertion that the endpoint
        // doesn't accidentally end up behind sanctum.
        $this->getJson('/api/v1/plans')->assertOk();
    }
}

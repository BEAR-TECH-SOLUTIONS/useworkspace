<?php

namespace Tests\Feature\Waitlist;

use App\Models\WaitlistSignup;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    public function test_landing_can_sign_up_with_an_email(): void
    {
        $this->postJson('/api/v1/waitlist', [
            'email' => 'someone@example.com',
            'source' => 'landing_hero',
            'metadata' => ['utm_campaign' => 'launch'],
        ])
            ->assertCreated()
            ->assertJsonStructure(['message']);

        $this->assertDatabaseHas('waitlist_signups', [
            'email' => 'someone@example.com',
            'source' => 'landing_hero',
        ]);

        $row = WaitlistSignup::query()->where('email', 'someone@example.com')->firstOrFail();
        $this->assertSame(['utm_campaign' => 'launch'], $row->metadata);
        $this->assertNotNull($row->ip_hash);
    }

    public function test_email_is_lowercased_and_treated_case_insensitively(): void
    {
        $this->postJson('/api/v1/waitlist', ['email' => 'Mixed@Case.com'])->assertCreated();

        $this->assertSame(
            1,
            WaitlistSignup::query()->where('email', 'mixed@case.com')->count(),
        );

        // Second submission with a different case is silently swallowed
        // (idempotency at the unique index level — no enumeration leak).
        $this->postJson('/api/v1/waitlist', ['email' => 'MIXED@case.com'])->assertCreated();

        $this->assertSame(1, WaitlistSignup::query()->count());
    }

    public function test_duplicate_signups_return_the_same_201_shape(): void
    {
        $body = ['email' => 'dup@example.com'];

        $first = $this->postJson('/api/v1/waitlist', $body);
        $second = $this->postJson('/api/v1/waitlist', $body);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, WaitlistSignup::query()->count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/v1/waitlist', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, WaitlistSignup::query()->count());
    }

    public function test_honeypot_silently_drops_bot_submissions(): void
    {
        $this->postJson('/api/v1/waitlist', [
            'email' => 'bot@example.com',
            'website' => 'http://spam.example.com',
        ])
            ->assertCreated()
            ->assertJsonStructure(['message']);

        // Bot's response was indistinguishable from a real signup, but
        // nothing was written to the DB.
        $this->assertSame(0, WaitlistSignup::query()->count());
    }

    public function test_source_rejects_weird_characters(): void
    {
        $this->postJson('/api/v1/waitlist', [
            'email' => 'someone@example.com',
            'source' => '<script>alert(1)</script>',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source']);
    }

    public function test_per_ip_rate_limit_kicks_in(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/waitlist', ['email' => "user{$i}@example.com"])
                ->assertCreated();
        }

        // 6th in the same minute → 429.
        $this->postJson('/api/v1/waitlist', ['email' => 'user5@example.com'])
            ->assertStatus(429);
    }

    public function test_email_is_required(): void
    {
        $this->postJson('/api/v1/waitlist', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}

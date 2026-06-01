<?php

namespace Tests\Feature\SelfHosted;

use App\Modules\SelfHosted\Models\LicenseState;
use App\Modules\SelfHosted\Services\Licensing\LicenseEnforcer;
use App\Modules\SelfHosted\Services\Licensing\LicenseValidator;
use App\Services\Licensing\Ed25519Signer;
use App\Services\Licensing\Ed25519Verifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Self-hosted runtime checks. These tests flip the active edition to
 * `self_hosted` so the EditionServiceProvider's per-request bindings
 * resolve correctly (PlanLimits → LicenseEnforcer, LicenseGuard
 * activates). The license_state table is shared with the cloud DB
 * because the test environment registers both migration paths.
 */
class SelfHostedRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['teamcore.edition' => 'self_hosted']);
        // LicenseValidator now requires both the token's fingerprint
        // and the expectedFingerprint (derived from TC_DOMAIN) to be
        // set and to match — see audit H13. Stand up a test domain so
        // signValidPayload's "domain:test.local" lines up with what
        // the boot check / LicenseGuard derive.
        $_ENV['TC_DOMAIN'] = 'test.local';
        putenv('TC_DOMAIN=test.local');
        DB::table('license_state')->delete();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_ENV['TC_DOMAIN']);
        putenv('TC_DOMAIN');
        parent::tearDown();
    }

    public function test_boot_check_rejects_missing_token(): void
    {
        // Clear LICENSE_TOKEN for this test only.
        $original = $_ENV['LICENSE_TOKEN'] ?? null;
        unset($_ENV['LICENSE_TOKEN']);
        putenv('LICENSE_TOKEN');

        try {
            $exit = $this->artisan('tc:license:check')->run();
            $this->assertSame(1, $exit);
        } finally {
            if ($original !== null) {
                $_ENV['LICENSE_TOKEN'] = $original;
            }
        }
    }

    public function test_boot_check_rejects_tampered_token(): void
    {
        $token = $this->signValidPayload();
        // Flip a byte in the body.
        [$body, $sig] = explode('.', $token);
        $bytes = base64_decode(strtr($body.str_repeat('=', (4 - strlen($body) % 4) % 4), '-_', '+/'));
        $bytes[0] = chr(ord($bytes[0]) ^ 0x01);
        $newBody = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $tampered = "{$newBody}.{$sig}";

        $this->withLicenseTokenEnv($tampered, function (): void {
            $this->artisan('tc:license:check')->assertFailed();
        });
    }

    public function test_boot_check_persists_state_on_valid_token(): void
    {
        $token = $this->signValidPayload();

        $this->withLicenseTokenEnv($token, function (): void {
            $this->artisan('tc:license:check')->assertSuccessful();
        });

        $row = LicenseState::query()->find(1);
        $this->assertNotNull($row);
        $this->assertNotNull($row->verified_payload);
        $this->assertNotNull($row->verified_at);
    }

    public function test_license_enforcer_raises_plan_limit_members_at_cap(): void
    {
        LicenseState::create([
            'id' => 1,
            'token' => 'placeholder',
            'verified_payload' => [
                'max_members' => 1,
                'max_projects' => null,
                'can_provision_users' => true,
            ],
            'verified_at' => Carbon::now(),
        ]);

        $owner = UserFactory::create();
        // ProjectFactory creates a workspace with member_count=1 (the
        // owner row), so a cap of 1 is already reached.
        $workspace = ProjectFactory::forOwner($owner)->organisation;

        $enforcer = $this->app->make(LicenseEnforcer::class);
        $this->expectException(\App\Exceptions\PlanLimitExceeded::class);

        $enforcer->assertCanAddMember($workspace->refresh());
    }

    public function test_license_enforcer_raises_provision_when_disallowed_by_license(): void
    {
        LicenseState::create([
            'id' => 1,
            'token' => 'placeholder',
            'verified_payload' => [
                'max_members' => null,
                'max_projects' => null,
                'can_provision_users' => false,
            ],
            'verified_at' => Carbon::now(),
        ]);

        $owner = UserFactory::create();
        $workspace = ProjectFactory::forOwner($owner)->organisation;

        $enforcer = $this->app->make(LicenseEnforcer::class);
        try {
            $enforcer->assertCanProvisionUser($workspace->refresh());
            $this->fail('Expected PlanLimitExceeded');
        } catch (\App\Exceptions\PlanLimitExceeded $e) {
            $this->assertSame('plan_limit_provision_users', $e->errorCode);
        }
    }

    public function test_validator_rejects_flipped_bit_token_without_db(): void
    {
        $token = $this->signValidPayload();
        [$body, $sig] = explode('.', $token);
        $bytes = base64_decode(strtr($body.str_repeat('=', (4 - strlen($body) % 4) % 4), '-_', '+/'));
        $bytes[0] = chr(ord($bytes[0]) ^ 0x02);
        $tampered = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=').'.'.$sig;

        $validator = $this->app->make(LicenseValidator::class);
        $result = $validator->validate($tampered);

        $this->assertFalse($result['valid']);
        $this->assertSame('signature_mismatch', $result['reason']);
    }

    public function test_validator_rejects_expired_payload(): void
    {
        $token = $this->signPayload([
            'expires_at' => Carbon::now()->subDay()->toIso8601String(),
            'max_members' => 10,
            'can_provision_users' => true,
        ]);

        $result = $this->app->make(LicenseValidator::class)->validate($token);
        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
    }

    public function test_license_guard_passes_with_valid_cache_under_grace(): void
    {
        $token = $this->signValidPayload();
        $payload = $this->app->make(LicenseValidator::class)
            ->validate($token, 'domain:test.local');

        LicenseState::create([
            'id' => 1,
            'token' => $token,
            'verified_payload' => $payload['payload'],
            'verified_at' => Carbon::now()->subMinutes(30), // fresh, < 1h reverify
            'last_phone_home_at' => Carbon::now()->subDay(),
            'last_phone_home_ok' => true,
        ]);

        $user = UserFactory::create();
        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_license_guard_refuses_when_offline_grace_exceeded(): void
    {
        $token = $this->signValidPayload();
        $payload = $this->app->make(LicenseValidator::class)->validate($token);

        LicenseState::create([
            'id' => 1,
            'token' => $token,
            'verified_payload' => $payload['payload'],
            'verified_at' => Carbon::now()->subMinutes(30),
            // Last attempt was 8 days ago and FAILED. Per spec the
            // grace clock only advances on successful phone-home, so a
            // long string of failures here trips the guard.
            'last_phone_home_at' => Carbon::now()->subDays(8),
            'last_phone_home_ok' => false,
            'last_phone_home_code' => 'unknown',
        ]);

        $user = UserFactory::create();
        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(503);

        $this->assertSame('license_offline_grace_exceeded', $response->json('code'));
    }

    /**
     * Sign a fresh payload with the same Ed25519 keypair the running
     * app is configured with, so the round-trip lands in
     * licensing/public_key.pem on verify. Useful for boot-check
     * scenarios that want a "valid" token.
     */
    private function signValidPayload(): string
    {
        return $this->signPayload([
            'expires_at' => Carbon::now()->addYear()->toIso8601String(),
            'max_members' => 50,
            'max_projects' => null,
            'can_provision_users' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function signPayload(array $overrides): string
    {
        $payload = array_merge([
            'v' => 1,
            'license_id' => 'lic_test_'.bin2hex(random_bytes(4)),
            'organisation_id' => 1,
            'issued_at' => Carbon::now()->toIso8601String(),
            'expires_at' => Carbon::now()->addYear()->toIso8601String(),
            'max_members' => 10,
            'max_projects' => null,
            'can_provision_users' => true,
            'edition' => 'self_hosted',
            'fingerprint' => 'domain:test.local',
        ], $overrides);

        return $this->app->make(Ed25519Signer::class)->sign($payload);
    }

    private function withLicenseTokenEnv(string $token, \Closure $fn): void
    {
        $previous = $_ENV['LICENSE_TOKEN'] ?? null;
        $_ENV['LICENSE_TOKEN'] = $token;
        putenv('LICENSE_TOKEN='.$token);
        try {
            $fn();
        } finally {
            if ($previous === null) {
                unset($_ENV['LICENSE_TOKEN']);
                putenv('LICENSE_TOKEN');
            } else {
                $_ENV['LICENSE_TOKEN'] = $previous;
                putenv('LICENSE_TOKEN='.$previous);
            }
        }
    }
}

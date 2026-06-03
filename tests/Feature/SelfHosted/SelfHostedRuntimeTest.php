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

    public function test_seat_cap_is_not_enforced_on_self_hosted(): void
    {
        // Regression: WorkspaceInvitationService::assertSeatAvailable
        // reads the workspace's seat_cap COLUMN, which on a freshly
        // installed self-hosted instance is 1 (the Free-tier default
        // applied to every new workspace). That's a cloud-billing
        // artifact — the license is the actual seat gate on
        // self-hosted, enforced separately via LicenseEnforcer. Any
        // admin adding their second user would otherwise hit
        // "Workspace seat cap of 1 reached" on the first invite.
        $owner = UserFactory::create();
        $workspace = ProjectFactory::forOwner($owner)->organisation;

        // Force the worst case: cap=1 and one member already present
        // (the owner row). On cloud this would 422.
        $workspace->forceFill(['seat_cap' => 1])->save();

        $invitations = $this->app->make(\App\Services\Workspaces\WorkspaceInvitationService::class);

        // No exception = self-hosted short-circuit fired. PHPUnit
        // requires an explicit assertion for the case to register.
        $invitations->assertSeatAvailableFor($workspace->refresh(), pendingCountsTowardCap: true);
        $this->assertTrue(true);
    }

    public function test_provisioning_is_available_on_self_hosted_regardless_of_workspace_tier(): void
    {
        // Regression: WorkspaceProvisioningService::isAvailableFor used
        // to gate on PlanTier::supportsDirectProvisioning(), which
        // returns false for Free/Entrepreneur. On a self-hosted install
        // the workspace's `tier` is a meaningless artifact (no billing
        // plan — the LICENSE is the gate), so the cloud tier check
        // 403'd legitimate self-hosted admins with the bogus message
        // "Direct user provisioning is not available on this workspace
        // plan." Verifying the short-circuit returns true regardless
        // of the workspace's nominal tier.
        $owner = UserFactory::create();
        $workspace = ProjectFactory::forOwner($owner)->organisation;

        // Force a non-provisioning-tier on the workspace; the cloud
        // gate would refuse this.
        $workspace->forceFill(['tier' => 'free'])->save();

        $available = $this->app->make(\App\Services\Workspaces\WorkspaceProvisioningService::class)
            ->isAvailableFor($workspace->refresh());

        $this->assertTrue($available);
    }

    public function test_license_enforcer_permits_provision_when_field_absent_from_v2_payload(): void
    {
        // Regression: v2 self-serve license tokens carry only identity
        // fields (license_id, instance_id, expires_at, edition) — no
        // can_provision_users, no max_members. The enforcer used to
        // treat the missing key as "false" and throw
        // plan_limit_provision_users, blocking direct provisioning on
        // every v2 install. Self-hosted has no commercial reason to
        // gate provisioning, so an absent field must read as
        // "unrestricted."
        LicenseState::create([
            'id' => 1,
            'token' => 'placeholder',
            // v2 minimal payload — exactly what LicenseService::claim
            // emits today.
            'verified_payload' => [
                'v' => 2,
                'license_id' => 'lic_test_v2',
                'instance_id' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
                'edition' => 'self_hosted',
                'issued_at' => Carbon::now()->toIso8601String(),
                'expires_at' => Carbon::now()->addYear()->toIso8601String(),
            ],
            'verified_at' => Carbon::now(),
        ]);

        $owner = UserFactory::create();
        $workspace = ProjectFactory::forOwner($owner)->organisation;

        $enforcer = $this->app->make(LicenseEnforcer::class);

        // No exception = permitted. PHPUnit needs an explicit
        // assertion to register the test as "ran".
        $enforcer->assertCanProvisionUser($workspace->refresh());
        $this->assertTrue(true);
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

    public function test_auth_me_includes_broadcasting_block_on_self_hosted(): void
    {
        // /auth/me must surface the public Reverb connection details
        // so the desktop client can connect WSS without falling back
        // to its cloud-baked VITE_REVERB_* build-time env (which
        // would attempt to subscribe with the self-hosted bearer on
        // the cloud's cluster — see resolveBroadcastingConfig in the
        // client).
        $this->seedValidLicenseState();
        config([
            'app.url' => 'https://uwsselfhosted.vpconnectsolutions.net',
            'broadcasting.connections.reverb.key' => 'a1b2c3d4e5',
        ]);

        $user = UserFactory::create();
        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame('a1b2c3d4e5', $response->json('broadcasting.app_key'));
        $this->assertSame('uwsselfhosted.vpconnectsolutions.net', $response->json('broadcasting.host'));
        $this->assertSame('https', $response->json('broadcasting.scheme'));
        $this->assertSame(443, $response->json('broadcasting.port'));
        $this->assertSame(
            'https://uwsselfhosted.vpconnectsolutions.net/api/v1/broadcasting/auth',
            $response->json('broadcasting.auth_endpoint'),
        );
    }

    public function test_auth_me_omits_broadcasting_block_on_cloud(): void
    {
        // On cloud the desktop binary uses build-time VITE_REVERB_*
        // values; surfacing a server-supplied block would override
        // those and break a release that pinned a different host.
        config(['teamcore.edition' => 'cloud']);
        config([
            'app.url' => 'https://api.usework.space',
            'broadcasting.connections.reverb.key' => 'cloud-key-1',
        ]);

        $user = UserFactory::create();
        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        // Whole key must be absent — `null` is not equivalent and would
        // make the client treat broadcasting as explicitly disabled.
        $this->assertArrayNotHasKey('broadcasting', $response->json());
    }

    public function test_auth_me_omits_broadcasting_when_reverb_key_unset(): void
    {
        // Self-hosted install where the operator forgot to set
        // REVERB_APP_KEY. Returning a half-config would make
        // pusher-js connect with an empty key and 401 every channel
        // auth call. Better: omit, let the client treat realtime as
        // disabled until the env is fixed.
        $this->seedValidLicenseState();
        config([
            'app.url' => 'https://selfhosted.example.com',
            'broadcasting.connections.reverb.key' => '',
        ]);

        $user = UserFactory::create();
        $response = $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertArrayNotHasKey('broadcasting', $response->json());
    }

    private function seedValidLicenseState(): void
    {
        $token = $this->signValidPayload();
        $payload = $this->app->make(LicenseValidator::class)
            ->validate($token, 'domain:test.local');

        LicenseState::create([
            'id' => 1,
            'token' => $token,
            'verified_payload' => $payload['payload'],
            'verified_at' => Carbon::now()->subMinutes(30),
            'last_phone_home_at' => Carbon::now()->subDay(),
            'last_phone_home_ok' => true,
        ]);
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

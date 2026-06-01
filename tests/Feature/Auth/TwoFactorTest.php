<?php

namespace Tests\Feature\Auth;

use App\Models\Project\Project;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    // Matches the password UserFactory hashes into password_hash. The
    // 2fa/verify + 2fa/disable endpoints require it as a second factor
    // (audit H8), enforced via the CurrentPassword rule.
    private const ACCOUNT_PASSWORD = 'correct-horse-battery-staple';

    public function test_enroll_returns_secret_and_otpauth_uri(): void
    {
        $user = UserFactory::create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/enroll')
            ->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri']);

        $this->assertStringStartsWith('otpauth://totp/', $response->json('otpauth_uri'));
        $this->assertNotEmpty($user->refresh()->two_factor_secret);
        $this->assertFalse($user->refresh()->two_factor_enabled);
    }

    public function test_confirm_with_valid_code_enables_2fa_and_returns_recovery_codes(): void
    {
        $user = UserFactory::create();

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll')->json();
        $code = $this->currentCode($enroll['secret']);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => $code]);

        $response->assertOk()
            ->assertJsonPath('user.two_factor_enabled', true)
            ->assertJsonStructure(['recovery_codes']);

        $this->assertCount(10, $response->json('recovery_codes'));
        $this->assertTrue($user->refresh()->two_factor_enabled);
    }

    public function test_confirm_rejects_invalid_code(): void
    {
        $user = UserFactory::create();
        $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll');

        $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($user->refresh()->two_factor_enabled);
    }

    public function test_verify_sets_cache_flag_and_lets_sensitive_route_through(): void
    {
        [$user, $project] = $this->userWith2faAnd();

        // Before verify → 403 two_factor_verification_required.
        $this->actingAs($user)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_verification_required');

        // Verify.
        $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/verify', ['code' => $this->currentCode($user->two_factor_secret), 'current_password' => self::ACCOUNT_PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['verified_until']);

        // Now the destroy succeeds.
        $this->actingAs($user)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertNoContent();
    }

    public function test_sensitive_route_blocked_without_2fa_at_all(): void
    {
        $user = UserFactory::create();
        $project = ProjectFactory::forOwner($user);

        $this->actingAs($user)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_required');
    }

    public function test_recovery_code_is_single_use(): void
    {
        [$user] = $this->userWith2faAnd();

        $enrollResponse = $this->enrollAndConfirm(UserFactory::create());
        $recoveryCodes = $enrollResponse['codes'];
        $freshUser = $enrollResponse['user']->refresh();

        $first = $this->actingAs($freshUser)
            ->postJson('/api/v1/auth/2fa/recover', ['recovery_code' => $recoveryCodes[0]])
            ->assertOk()
            ->json();

        $this->assertSame(9, $first['remaining_recovery_codes']);

        // Same code a second time should fail.
        $this->actingAs($freshUser->refresh())
            ->postJson('/api/v1/auth/2fa/recover', ['recovery_code' => $recoveryCodes[0]])
            ->assertStatus(422);
    }

    public function test_disable_requires_fresh_verification(): void
    {
        [$user] = $this->userWith2faAnd();

        // Without verify → blocked.
        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/2fa')
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_verification_required');

        // Verify, then disable succeeds.
        $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/verify', ['code' => $this->currentCode($user->two_factor_secret), 'current_password' => self::ACCOUNT_PASSWORD])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson('/api/v1/auth/2fa', ['current_password' => self::ACCOUNT_PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', false);

        $this->assertFalse($user->refresh()->two_factor_enabled);
        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_enroll_rejects_when_already_enabled(): void
    {
        [$user] = $this->userWith2faAnd();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/enroll')
            ->assertStatus(409)
            ->assertJsonPath('code', 'two_factor_already_enabled');
    }

    /**
     * Register a user and walk them through enrol + confirm so downstream
     * tests start from the "2FA enabled, no recent verify" state.
     *
     * @return array{0: User, 1: Project}
     */
    private function userWith2faAnd(): array
    {
        $user = UserFactory::create();
        $project = ProjectFactory::forOwner($user);

        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll')->json();
        $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/confirm', [
                'code' => $this->currentCode($enroll['secret']),
            ])
            ->assertOk();

        // Confirm sets the verify flag, but we want the test to exercise the
        // blocked state, so drop it here.
        Cache::forget('2fa_verified:'.$user->id);

        // Confirm also consumed the current TOTP step (replay guard,
        // audit H7). Clear it so a subsequent verify in the same
        // wall-clock window isn't rejected as a replay — in production
        // confirm and verify land in different windows with different
        // codes.
        User::query()->whereKey($user->id)->update(['last_totp_step' => null]);

        return [$user->refresh(), $project];
    }

    /**
     * @return array{user: User, codes: array<int, string>}
     */
    private function enrollAndConfirm(User $user): array
    {
        $enroll = $this->actingAs($user)->postJson('/api/v1/auth/2fa/enroll')->json();
        $confirm = $this->actingAs($user)
            ->postJson('/api/v1/auth/2fa/confirm', [
                'code' => $this->currentCode($enroll['secret']),
            ])
            ->assertOk()
            ->json();

        return ['user' => $user->refresh(), 'codes' => $confirm['recovery_codes']];
    }

    private function currentCode(string $secret): string
    {
        // We reuse the production TotpService to compute a valid code at
        // test time — mirror the verify path so we're testing the same
        // implementation end-to-end.
        $totp = app(TotpService::class);
        $reflect = new \ReflectionClass($totp);
        $method = $reflect->getMethod('generateCode');
        $method->setAccessible(true);

        return $method->invoke($totp, $secret, intdiv(time(), TotpService::PERIOD));
    }
}

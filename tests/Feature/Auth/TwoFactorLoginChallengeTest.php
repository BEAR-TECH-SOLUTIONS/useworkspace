<?php

namespace Tests\Feature\Auth;

use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\UserFactory;
use Tests\TestCase;

class TwoFactorLoginChallengeTest extends TestCase
{
    public function test_login_without_2fa_returns_200_with_token(): void
    {
        $user = $this->makeUser(twoFactor: false);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_login_with_2fa_returns_202_challenge(): void
    {
        $user = $this->makeUser(twoFactor: true);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])
            ->assertStatus(202)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_token', 'expires_at']);

        $this->assertStringStartsWith('tfc_', $response->json('challenge_token'));
        $this->assertDatabaseCount('two_factor_challenges', 1);
    }

    public function test_challenge_with_valid_totp_returns_token(): void
    {
        $user = $this->makeUser(twoFactor: true);

        $challengeToken = $this->getChallenge($user);

        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $this->currentCode($user->two_factor_secret),
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        // Challenge row consumed.
        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_challenge_with_valid_recovery_code(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $plainCode = $this->seedRecoveryCode($user);
        $challengeToken = $this->getChallenge($user);

        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'recovery_code' => $plainCode,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        // Recovery code consumed — second use should fail.
        $this->assertCount(0, $user->refresh()->two_factor_recovery_codes ?? []);
    }

    public function test_expired_challenge_returns_401(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $challengeToken = $this->getChallenge($user);

        // Manually expire the challenge.
        TwoFactorChallenge::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'challenge_expired');
    }

    public function test_wrong_code_returns_401_and_increments_attempts(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $challengeToken = $this->getChallenge($user);

        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_code');

        $this->assertSame(1, (int) TwoFactorChallenge::query()->value('attempts'));
    }

    public function test_five_wrong_attempts_burns_challenge(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $challengeToken = $this->getChallenge($user);

        // Set attempts to 5 directly.
        TwoFactorChallenge::query()->update(['attempts' => 5]);

        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');

        $this->assertDatabaseCount('two_factor_challenges', 0);
    }

    public function test_must_supply_exactly_one_factor(): void
    {
        $user = $this->makeUser(twoFactor: true);
        $challengeToken = $this->getChallenge($user);

        // Both present.
        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '123456',
            'recovery_code' => 'ABCDE-12345',
        ])->assertStatus(422);

        // Neither present.
        $this->postJson('/api/v1/auth/2fa/challenge', [
            'challenge_token' => $challengeToken,
        ])->assertStatus(422);
    }

    public function test_wrong_password_with_2fa_user_returns_422(): void
    {
        $user = $this->makeUser(twoFactor: true);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function makeUser(bool $twoFactor): User
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();

        return UserFactory::create([
            'two_factor_secret' => $twoFactor ? $secret : null,
            'two_factor_enabled' => $twoFactor,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ]);
    }

    private function getChallenge(User $user): string
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])->assertStatus(202);

        return $response->json('challenge_token');
    }

    private function seedRecoveryCode(User $user): string
    {
        $plain = 'abcde-12345';
        $user->forceFill([
            'two_factor_recovery_codes' => [Hash::make($plain)],
        ])->save();

        return $plain;
    }

    private function currentCode(string $secret): string
    {
        $totp = app(TotpService::class);
        $reflect = new \ReflectionClass($totp);
        $method = $reflect->getMethod('generateCode');
        $method->setAccessible(true);

        return $method->invoke($totp, $secret, intdiv(time(), TotpService::PERIOD));
    }
}

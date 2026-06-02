<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\Support\UserFactory;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'new-secret-1234',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated');

        $this->assertTrue(Hash::check('new-secret-1234', $user->refresh()->password_hash));
    }

    public function test_wrong_current_password_returns_422_with_code(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'wrong',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'new-secret-1234',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_current_password');
    }

    public function test_rejects_new_password_same_as_current(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'correct-horse-battery-staple',
                'password_confirmation' => 'correct-horse-battery-staple',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_password_below_minimum_length(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_rejects_missing_confirmation(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'mismatch-1234',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_other_tokens_are_revoked_current_token_survives(): void
    {
        $user = UserFactory::create();

        $current = $user->createToken('current-device');
        $other = $user->createToken('other-device');

        $this->withHeader('Authorization', 'Bearer '.$current->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'new-secret-1234',
            ])
            ->assertOk();

        // Exactly one token survives — the one the user just used. The
        // other device is logged out on the next request it makes.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotNull($user->tokens()->whereKey($current->accessToken->id)->first());
        $this->assertNull($user->tokens()->whereKey($other->accessToken->id)->first());
    }

    public function test_blocks_when_2fa_enabled_and_not_recently_verified(): void
    {
        $user = UserFactory::create(['two_factor_enabled' => true]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'new-secret-1234',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_verification_required');

        // Password must be unchanged.
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->refresh()->password_hash));
    }

    public function test_succeeds_when_2fa_enabled_and_recently_verified(): void
    {
        $user = UserFactory::create(['two_factor_enabled' => true]);
        Cache::put('2fa_verified:'.$user->id, true, now()->addMinutes(10));

        $this->actingAs($user)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'correct-horse-battery-staple',
                'password' => 'new-secret-1234',
                'password_confirmation' => 'new-secret-1234',
            ])
            ->assertOk();
    }

    public function test_requires_auth(): void
    {
        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'x',
            'password' => 'yyyyyyyy',
            'password_confirmation' => 'yyyyyyyy',
        ])->assertUnauthorized();
    }
}

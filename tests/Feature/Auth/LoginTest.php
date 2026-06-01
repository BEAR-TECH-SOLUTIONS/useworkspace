<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email', 'master_password_set', 'master_password_salt', 'public_key', 'encrypted_private_key', 'private_key_iv'],
            ])
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.master_password_set', true);
    }

    public function test_login_exposes_master_password_not_yet_set(): void
    {
        $user = User::create([
            'name' => 'Pending User',
            'email' => 'pending-'.bin2hex(random_bytes(4)).'@example.com',
            'password_hash' => Hash::make('correct-horse-battery-staple'),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ])
            ->assertOk()
            ->assertJsonPath('user.master_password_set', false)
            ->assertJsonPath('user.master_password_salt', null)
            ->assertJsonPath('user.public_key', null);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_fails_for_unknown_email(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'nobody-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_authenticated_user_can_fetch_me(): void
    {
        $user = $this->makeUser();

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'login-'.bin2hex(random_bytes(4)).'@example.com',
            'password_hash' => Hash::make('correct-horse-battery-staple'),
            'master_password_salt' => base64_encode(random_bytes(16)),
            'master_password_verifier' => base64_encode(random_bytes(32)),
            'public_key' => 'pub',
            'encrypted_private_key' => 'enc',
            'private_key_iv' => 'iv',
        ]);
    }
}

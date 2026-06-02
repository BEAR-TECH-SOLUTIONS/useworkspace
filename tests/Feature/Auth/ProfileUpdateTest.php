<?php

namespace Tests\Feature\Auth;

use Tests\Support\UserFactory;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    public function test_user_can_update_display_name(): void
    {
        $user = UserFactory::create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/me', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.email', $user->email);

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_name_is_trimmed_before_persist(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/me', ['name' => '   Jane Doe   '])
            ->assertOk()
            ->assertJsonPath('user.name', 'Jane Doe');
    }

    public function test_email_in_body_is_silently_ignored(): void
    {
        $user = UserFactory::create();
        $originalEmail = $user->email;

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/me', [
                'name' => 'Still Me',
                'email' => 'hijack@example.com',
            ])
            ->assertOk();

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_rejects_blank_name(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/me', ['name' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_rejects_name_over_100_chars(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->patchJson('/api/v1/auth/me', ['name' => str_repeat('a', 101)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_requires_auth(): void
    {
        $this->patchJson('/api/v1/auth/me', ['name' => 'x'])->assertUnauthorized();
    }
}

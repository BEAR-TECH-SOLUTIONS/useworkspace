<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Identity\PersonalProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class MasterPasswordSetupTest extends TestCase
{
    public function test_me_endpoint_reachable_without_master_password(): void
    {
        $user = UserFactory::createWithoutMasterPassword();

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.master_password_set', false);
    }

    public function test_user_can_set_up_master_password(): void
    {
        $user = UserFactory::createWithoutMasterPassword();

        $payload = $this->validBundle();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', $payload);

        $response->assertOk()
            ->assertJsonPath('user.master_password_set', true)
            ->assertJsonPath('user.public_key', $payload['public_key']);

        /** @var User $fresh */
        $fresh = $user->refresh();
        $this->assertTrue($fresh->hasMasterPassword());
        $this->assertSame($payload['master_password_salt'], $fresh->master_password_salt);
        $this->assertSame($payload['encrypted_private_key'], $fresh->encrypted_private_key);
    }

    public function test_master_password_setup_rejects_second_call(): void
    {
        $user = UserFactory::createWithoutMasterPassword();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', $this->validBundle())
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', $this->validBundle())
            ->assertStatus(409)
            ->assertJsonPath('code', 'master_password_already_set');
    }

    public function test_master_password_setup_validates_required_fields(): void
    {
        $user = UserFactory::createWithoutMasterPassword();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'master_password_salt',
                'master_password_verifier',
                'public_key',
                'encrypted_private_key',
                'private_key_iv',
            ]);
    }

    public function test_non_vault_endpoints_work_without_master_password(): void
    {
        // Master-password-less users can still use boards, expenses, and
        // everything else that doesn't touch the vault. This covers the
        // change in CLAUDE.md where vault is the only gated module.
        $user = UserFactory::createWithoutMasterPassword();
        app(PersonalProjectFactory::class)->bootstrap($user);

        $this->actingAs($user)->getJson('/api/v1/projects')->assertOk();
    }

    public function test_vault_endpoint_returns_409_without_master_password(): void
    {
        $user = UserFactory::createWithoutMasterPassword();
        $project = app(PersonalProjectFactory::class)->bootstrap($user);

        $this->actingAs($user)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertStatus(409)
            ->assertJsonPath('code', 'master_password_required');
    }

    public function test_vault_endpoint_succeeds_after_master_password_setup(): void
    {
        $user = UserFactory::createWithoutMasterPassword();
        $project = app(PersonalProjectFactory::class)->bootstrap($user);

        // Blocked before setup.
        $this->actingAs($user)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertStatus(409);

        // Finish setup and retry.
        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', $this->validBundle())
            ->assertOk();

        $this->actingAs($user->refresh())
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk();
    }

    public function test_user_with_master_password_can_reach_vault(): void
    {
        $user = UserFactory::create(); // pre-populated bundle
        $project = app(PersonalProjectFactory::class)->bootstrap($user);

        $this->actingAs($user)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk();
    }

    /**
     * @return array<string, string>
     */
    private function validBundle(): array
    {
        return [
            'master_password_salt' => base64_encode(random_bytes(16)),
            'master_password_verifier' => base64_encode(random_bytes(32)),
            'public_key' => base64_encode('-----BEGIN PUBLIC KEY-----'.str_repeat('A', 64).'-----END PUBLIC KEY-----'),
            'encrypted_private_key' => base64_encode(random_bytes(256)),
            'private_key_iv' => base64_encode(random_bytes(12)),
        ];
    }
}

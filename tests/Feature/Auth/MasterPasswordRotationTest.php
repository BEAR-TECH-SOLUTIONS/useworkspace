<?php

namespace Tests\Feature\Auth;

use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Covers PUT /api/v1/auth/master-password — rotating the
 * crypto bundle without touching the public key.
 *
 * The companion POST endpoint stays intact for first-time setup
 * (final test asserts it still 409s on an already-configured user).
 */
class MasterPasswordRotationTest extends TestCase
{
    private const ACCOUNT_PASSWORD = 'correct-horse-battery-staple';

    public function test_rotation_overwrites_bundle_but_keeps_public_key(): void
    {
        $user = UserFactory::create();
        $originalPublicKey = $user->public_key;
        $originalSalt = $user->master_password_salt;
        $originalEncryptedPrivateKey = $user->encrypted_private_key;

        $payload = $this->validRotation();

        $response = $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $payload);

        $response->assertOk()
            ->assertJsonPath('user.master_password_set', true)
            // Public key must be byte-identical — every wrapped vault
            // key depends on it.
            ->assertJsonPath('user.public_key', $originalPublicKey);

        $fresh = $user->refresh();
        $this->assertSame($originalPublicKey, $fresh->public_key);
        $this->assertSame($payload['master_password_salt'], $fresh->master_password_salt);
        $this->assertSame($payload['master_password_verifier'], $fresh->master_password_verifier);
        $this->assertSame($payload['encrypted_private_key'], $fresh->encrypted_private_key);
        $this->assertSame($payload['private_key_iv'], $fresh->private_key_iv);

        // And the bundle actually changed (sanity — the new payload
        // is different from what was on the row).
        $this->assertNotSame($originalSalt, $fresh->master_password_salt);
        $this->assertNotSame($originalEncryptedPrivateKey, $fresh->encrypted_private_key);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->putJson('/api/v1/auth/master-password', $this->validRotation())
            ->assertStatus(401);
    }

    public function test_rotation_returns_409_when_master_password_not_yet_set(): void
    {
        $user = UserFactory::createWithoutMasterPassword();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $this->validRotation())
            ->assertStatus(409)
            ->assertJsonPath('code', 'master_password_required');
    }

    public function test_rotation_requires_all_four_crypto_fields(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', [
                'current_password' => self::ACCOUNT_PASSWORD,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'master_password_salt',
                'master_password_verifier',
                'encrypted_private_key',
                'private_key_iv',
            ]);
    }

    public function test_rotation_rejects_non_base64_fields(): void
    {
        $user = UserFactory::create();

        $payload = array_merge($this->validRotation(), [
            'master_password_verifier' => '!! not base64 at all !!',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['master_password_verifier']);
    }

    public function test_rotation_rejects_wrong_byte_width_on_fixed_fields(): void
    {
        $user = UserFactory::create();

        // Salt must decode to exactly 16 bytes; ship a 20-byte payload.
        $payload = array_merge($this->validRotation(), [
            'master_password_salt' => base64_encode(random_bytes(20)),
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['master_password_salt']);
    }

    public function test_rotation_rejects_request_containing_public_key(): void
    {
        $user = UserFactory::create();

        $payload = array_merge($this->validRotation(), [
            'public_key' => base64_encode('different-public-key'),
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['public_key']);
    }

    public function test_rotation_rejects_wrong_current_password(): void
    {
        $user = UserFactory::create();

        $payload = array_merge($this->validRotation(), [
            'current_password' => 'this-is-not-the-right-password',
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_rotation_requires_fresh_2fa_when_2fa_enabled(): void
    {
        $user = UserFactory::create(['two_factor_enabled' => true]);

        $response = $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $this->validRotation());

        $response->assertStatus(403)
            ->assertJsonPath('code', 'two_factor_verification_required');
    }

    public function test_rotation_succeeds_when_2fa_verified_recently(): void
    {
        $user = UserFactory::create(['two_factor_enabled' => true]);

        // Simulate a fresh /auth/2fa/verify call by marking the
        // user-scoped key the verification service falls back to
        // when there's no token id in context (actingAs uses a
        // TransientToken, which has no id).
        app(TwoFactorVerification::class)->mark($user, null);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $this->validRotation())
            ->assertOk();
    }

    public function test_existing_resource_key_grant_survives_rotation(): void
    {
        // The whole point of preserving public_key across a rotation
        // is that resource_keys rows (vault keys wrapped to the
        // user's RSA pub key) keep working. Verify the row is still
        // present and byte-identical after the rotation.
        $user = UserFactory::create();
        $project = ProjectFactory::forOwner($user);
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();

        $grant = ResourceKey::create([
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'encrypted_key' => 'wrapped-vault-key-for-owner',
            'key_version' => 1,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/auth/master-password', $this->validRotation())
            ->assertOk();

        $stillThere = ResourceKey::query()
            ->where('resource_type', ResourceType::Vault->value)
            ->where('resource_id', $vault->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($stillThere, 'Vault key grant must survive a master-password rotation.');
        $this->assertSame($grant->encrypted_key, $stillThere->encrypted_key);
        $this->assertSame($grant->key_version, (int) $stillThere->key_version);
    }

    public function test_post_setup_still_409s_for_already_configured_user(): void
    {
        // Regression — the rotation work must NOT have relaxed the
        // one-time setup contract. POST /auth/master-password on a
        // user who already has a bundle still 409s.
        $user = UserFactory::create();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/master-password', [
                'master_password_salt' => base64_encode(random_bytes(16)),
                'master_password_verifier' => base64_encode(random_bytes(32)),
                'public_key' => 'pub-'.bin2hex(random_bytes(8)),
                'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
                'private_key_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'master_password_already_set');
    }

    /**
     * @return array<string, string>
     */
    private function validRotation(): array
    {
        return [
            'master_password_salt' => base64_encode(random_bytes(16)),
            'master_password_verifier' => base64_encode(random_bytes(32)),
            'encrypted_private_key' => base64_encode(random_bytes(256)),
            'private_key_iv' => base64_encode(random_bytes(12)),
            'current_password' => self::ACCOUNT_PASSWORD,
        ];
    }
}

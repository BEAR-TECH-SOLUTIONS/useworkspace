<?php

namespace Tests\Feature\Vault;

use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Project\Project;
use App\Models\User;
use App\Models\Vault\Vault;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Tests\TestCase;

/**
 * Regression: the default vault created on POST /register must be
 * write-able in one linear flow (migrate-key → store credential)
 * with no intermediate calls.
 *
 * The original bug: VaultKeyController::migrate gated on
 * `EXISTS(resource_keys for vault)` while the credential gates in
 * Store/UpdateCredentialRequest looked at `vaults.migrated_at IS
 * NULL`. The two checks measured different things, so a fresh user
 * could be permanently locked out of writing credentials to their
 * own default vault. The fix aligns both gates on the resource_keys
 * existence check (matching the migration's documented invariant)
 * and makes migrate-key idempotent on identical re-call.
 */
class FreshSignupCredentialFlowTest extends TestCase
{
    public function test_fresh_signup_can_migrate_and_create_credential_in_one_flow(): void
    {
        // 1. Register (bootstraps personal workspace, project, default
        //    board, default vault, default bucket).
        $email = 'flow-'.bin2hex(random_bytes(4)).'@example.com';
        $password = $this->validRegistrationPassword();

        $register = $this->postJson('/api/v1/register', [
            'name' => 'Flow User',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(201);

        $token = (string) $register->json('token');
        $this->assertNotSame('', $token);

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        // 2. Upload the master-password crypto bundle so vault writes
        //    are unblocked by EnsureMasterPasswordSet.
        $this->withToken($token)
            ->postJson('/api/v1/auth/master-password', [
                'master_password_salt' => base64_encode(random_bytes(16)),
                'master_password_verifier' => base64_encode(random_bytes(32)),
                'public_key' => 'pub-'.bin2hex(random_bytes(8)),
                'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
                'private_key_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertOk();

        // 3. Look up the default vault on the personal project.
        /** @var Project $project */
        $project = Project::query()
            ->where('owner_id', $user->id)
            ->where('is_personal', true)
            ->firstOrFail();
        /** @var Vault $vault */
        $vault = Vault::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        // Sanity: out of the box no resource_keys row exists for the
        // default vault — the migration's invariant. If this fails,
        // some bootstrap path is writing a placeholder row and the
        // original bug is reproducing.
        $this->assertFalse(
            ResourceKey::query()->for(ResourceType::Vault, $vault->id)->exists(),
            'Default vault should have no resource_keys rows after register + master-password setup.',
        );

        // 4. POST /vaults/{vault}/migrate-key with a self-grant and
        //    no credentials. This is the documented happy path.
        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    [
                        'user_id' => $user->id,
                        'encrypted_key' => 'wrapped-vault-key-for-self',
                    ],
                ],
                'credentials' => [],
            ])
            ->assertOk()
            ->assertJsonPath('key_version', 1);

        // migrate-key must stamp `vaults.migrated_at` in the same
        // transaction as the resource_keys insert — the client uses
        // this timestamp as the canonical "vault is keyed" signal
        // (resource-tree badge, grant checkbox, share filters).
        $this->assertNotNull(
            $vault->refresh()->migrated_at,
            'migrate-key must set vaults.migrated_at on success.',
        );

        // 5. POST /projects/{project}/credentials with a real-shaped
        //    AES-GCM payload (12-byte iv base64-encoded).
        $this->withToken($token)
            ->postJson("/api/v1/projects/{$project->id}/credentials", [
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'first entry',
                'encrypted_data' => base64_encode('opaque-ciphertext'),
                'iv' => base64_encode(random_bytes(12)),
            ])
            ->assertStatus(201);

        // And the vault now reports a non-null migrated_at everywhere.
        $response = $this->withToken($token)
            ->getJson("/api/v1/vaults/{$vault->id}")
            ->assertOk();
        $this->assertNotNull($response->json('vault.migrated_at'));
    }

    public function test_migrate_key_is_idempotent_on_identical_replay(): void
    {
        // Register + master-password.
        $email = 'replay-'.bin2hex(random_bytes(4)).'@example.com';
        $password = $this->validRegistrationPassword();

        $token = (string) $this->postJson('/api/v1/register', [
            'name' => 'Replay User',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(201)->json('token');

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        $this->withToken($token)
            ->postJson('/api/v1/auth/master-password', [
                'master_password_salt' => base64_encode(random_bytes(16)),
                'master_password_verifier' => base64_encode(random_bytes(32)),
                'public_key' => 'pub-'.bin2hex(random_bytes(8)),
                'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
                'private_key_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertOk();

        /** @var Vault $vault */
        $vault = Vault::query()
            ->whereHas('project', fn ($q) => $q->where('owner_id', $user->id)->where('is_personal', true))
            ->where('is_default', true)
            ->firstOrFail();

        $payload = [
            'grants' => [
                ['user_id' => $user->id, 'encrypted_key' => 'wrapped-for-self'],
            ],
            'credentials' => [],
        ];

        // First call: 200, writes the resource_keys row.
        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", $payload)
            ->assertOk();

        // Identical replay (network retry, lost response on the wire,
        // duplicate-fire in a buggy client) must succeed quietly —
        // not lock the user out with a 409.
        $replay = $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", $payload)
            ->assertOk()
            ->assertJsonPath('key_version', 1);
        $this->assertNotNull($replay->json('vault.migrated_at'));

        // But a DIFFERENT wrap (same user, different ciphertext) is
        // still a real conflict and must 409.
        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $user->id, 'encrypted_key' => 'wrapped-for-self-DIFFERENT'],
                ],
                'credentials' => [],
            ])
            ->assertStatus(409);
    }

    public function test_replay_heals_legacy_state_with_null_migrated_at(): void
    {
        // Regression: vaults that were migrated under the
        // pre-fix code path have a resource_keys row but a NULL
        // `vaults.migrated_at`. An identical-payload replay of
        // migrate-key should silently heal the timestamp instead
        // of stranding the vault in the broken state.
        $email = 'heal-'.bin2hex(random_bytes(4)).'@example.com';
        $password = $this->validRegistrationPassword();

        $token = (string) $this->postJson('/api/v1/register', [
            'name' => 'Heal User',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertStatus(201)->json('token');

        /** @var User $user */
        $user = User::query()->where('email', $email)->firstOrFail();

        $this->withToken($token)
            ->postJson('/api/v1/auth/master-password', [
                'master_password_salt' => base64_encode(random_bytes(16)),
                'master_password_verifier' => base64_encode(random_bytes(32)),
                'public_key' => 'pub-'.bin2hex(random_bytes(8)),
                'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
                'private_key_iv' => base64_encode(random_bytes(12)),
            ])
            ->assertOk();

        /** @var Vault $vault */
        $vault = Vault::query()
            ->whereHas('project', fn ($q) => $q->where('owner_id', $user->id)->where('is_personal', true))
            ->where('is_default', true)
            ->firstOrFail();

        $payload = [
            'grants' => [
                ['user_id' => $user->id, 'encrypted_key' => 'wrapped-for-self'],
            ],
            'credentials' => [],
        ];

        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", $payload)
            ->assertOk();

        // Reproduce the legacy bug state — resource_keys row stays,
        // but migrated_at gets manually reset to null. This is the
        // shape vault 49 (and any vault migrated before the fix
        // shipped) was found in.
        $vault->forceFill(['migrated_at' => null])->save();
        $this->assertNull($vault->refresh()->migrated_at);

        // Identical-payload replay should heal the row.
        $this->withToken($token)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", $payload)
            ->assertOk();

        $this->assertNotNull(
            $vault->refresh()->migrated_at,
            'Idempotent replay must heal a NULL migrated_at when resource_keys still exists.',
        );
    }

    /**
     * Build a password that passes the global Password::defaults()
     * policy (audit H9: 12 chars + mixedCase + numbers + symbols).
     */
    private function validRegistrationPassword(): string
    {
        // Predictable but compliant — the test only cares that the
        // register call passes validation.
        return 'Pa55word!-'.bin2hex(random_bytes(3));
    }
}

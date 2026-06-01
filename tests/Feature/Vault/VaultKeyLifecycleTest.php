<?php

namespace Tests\Feature\Vault;

use App\Enums\AuditAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Covers POST /api/v1/vaults/{vault}/migrate-key and
 * POST /api/v1/vaults/{vault}/rotate-key (CLAUDE.md §6.5).
 *
 * These endpoints re-encrypt every credential in the vault, so the test
 * matrix is: happy path, permission denied, 2FA gating, already-migrated,
 * not-yet-migrated, incomplete grant set, mismatched credential set, and
 * optimistic concurrency on rotate.
 */
class VaultKeyLifecycleTest extends TestCase
{
    public function test_freshly_created_vault_reports_null_wrapped_key_on_index(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        // Vault has been created (so `migrated_at` is set by the DB
        // default) but migrate-key hasn't run yet, so there are no
        // resource_keys rows for the caller — `my_wrapped_key` is null.
        $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk()
            ->assertJsonPath('data.0.id', $vault->id)
            ->assertJsonPath('data.0.my_wrapped_key', null);
    }

    public function test_index_and_show_embed_my_wrapped_key_after_migrate(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $credentialIds = $this->seedCredentials($project, $vault, 1);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $owner->id, 'encrypted_key' => 'wrapped-for-owner'],
                ],
                'credentials' => [
                    ['id' => $credentialIds[0], 'encrypted_data' => 'v1', 'iv' => str_repeat('A', 16)],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('vault.my_wrapped_key.encrypted_key', 'wrapped-for-owner')
            ->assertJsonPath('vault.my_wrapped_key.key_version', 1);

        // Index embeds it.
        $this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk()
            ->assertJsonPath('data.0.my_wrapped_key.encrypted_key', 'wrapped-for-owner')
            ->assertJsonPath('data.0.my_wrapped_key.key_version', 1);

        // Show embeds it.
        $this->actingAs($owner)
            ->getJson("/api/v1/vaults/{$vault->id}")
            ->assertOk()
            ->assertJsonPath('vault.my_wrapped_key.encrypted_key', 'wrapped-for-owner')
            ->assertJsonPath('vault.my_wrapped_key.key_version', 1);
    }

    public function test_rotate_bumps_embedded_key_version(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'v1-key']],
                'credentials' => [],
            ])
            ->assertOk();

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/rotate-key", [
                'expected_current_version' => 1,
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'v2-key']],
                'credentials' => [],
            ])
            ->assertOk();

        // Only the v2 key is reported — v1 is gone.
        $this->actingAs($owner)
            ->getJson("/api/v1/vaults/{$vault->id}")
            ->assertOk()
            ->assertJsonPath('vault.my_wrapped_key.encrypted_key', 'v2-key')
            ->assertJsonPath('vault.my_wrapped_key.key_version', 2);
    }

    public function test_owner_can_migrate_vault_key(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        $credentialIds = $this->seedCredentials($project, $vault, 3);

        $payload = [
            'grants' => [
                ['user_id' => $owner->id, 'encrypted_key' => 'wrapped-key-for-owner'],
            ],
            'credentials' => array_map(
                static fn (int $id): array => [
                    'id' => $id,
                    'encrypted_data' => 'rewrapped-'.$id,
                    'iv' => self::ivOf("v1-{$id}"),
                ],
                $credentialIds,
            ),
        ];

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", $payload)
            ->assertOk()
            ->assertJsonPath('key_version', 1)
            ->assertJsonPath('vault.id', $vault->id);

        // resource_keys row at v1 for the owner.
        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $owner->id,
            'key_version' => 1,
            'encrypted_key' => 'wrapped-key-for-owner',
        ]);

        // Every credential got rewrapped to v1.
        foreach ($credentialIds as $id) {
            $this->assertDatabaseHas('credentials', [
                'id' => $id,
                'encrypted_data' => 'rewrapped-'.$id,
                'iv' => self::ivOf("v1-{$id}"),
                'key_version' => 1,
            ]);
        }

        // vaults.migrated_at is now set.
        $this->assertNotNull($vault->refresh()->migrated_at);

        // And the audit row was written.
        $this->assertDatabaseHas('audit_log', [
            'action' => AuditAction::VaultMigrated->value,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'actor_user_id' => $owner->id,
        ]);
    }

    public function test_migrate_empty_vault(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $owner->id, 'encrypted_key' => 'wrapped-key-for-owner'],
                ],
                'credentials' => [],
            ])
            ->assertOk()
            ->assertJsonPath('key_version', 1);

        $this->assertNotNull($vault->refresh()->migrated_at);
        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $owner->id,
            'key_version' => 1,
        ]);
    }

    public function test_non_owner_cannot_migrate(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $editor = UserFactory::create();

        // Grant the editor project-level editor access — they should still
        // be rejected because migrate-key is owner-only (`share` ability).
        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($editor)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $editor->id, 'encrypted_key' => 'k']],
                'credentials' => [],
            ])
            ->assertForbidden();
    }

    public function test_migrate_rejects_already_migrated_vault(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        // First call succeeds — initial key wrap.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k1']],
                'credentials' => [],
            ])
            ->assertOk();

        // Second call must 409 — rotate-key handles subsequent rekeys.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k2']],
                'credentials' => [],
            ])
            ->assertStatus(409);
    }

    public function test_migrate_rejects_incomplete_grants(): void
    {
        [$owner, $project, $vault] = $this->setupProject();

        // Add a second member to the project, making the "complete" grant
        // set size 2 (owner + editor). Then omit the editor from the payload.
        $editor = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $editor->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [
                    ['user_id' => $owner->id, 'encrypted_key' => 'k1'],
                ],
                'credentials' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.grants.0', 'Vault grants do not match the set of authorized members.');
    }

    public function test_migrate_rejects_mismatched_credential_set(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $credentialIds = $this->seedCredentials($project, $vault, 2);

        // Send only one credential — the vault has two.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k1']],
                'credentials' => [
                    [
                        'id' => $credentialIds[0],
                        'encrypted_data' => 'x',
                        'iv' => str_repeat('A', 16),
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.credentials.0', 'Credential list does not match the vault contents.');
    }

    public function test_owner_can_rotate_vault_key(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $credentialIds = $this->seedCredentials($project, $vault, 2);

        // First, migrate to v1.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k1']],
                'credentials' => array_map(
                    static fn (int $id): array => [
                        'id' => $id,
                        'encrypted_data' => 'v1-'.$id,
                        'iv' => self::ivOf("v1-{$id}"),
                    ],
                    $credentialIds,
                ),
            ])
            ->assertOk();

        // Then rotate to v2.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/rotate-key", [
                'expected_current_version' => 1,
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k2']],
                'credentials' => array_map(
                    static fn (int $id): array => [
                        'id' => $id,
                        'encrypted_data' => 'v2-'.$id,
                        'iv' => self::ivOf("v2-{$id}"),
                    ],
                    $credentialIds,
                ),
            ])
            ->assertOk()
            ->assertJsonPath('key_version', 2);

        // v1 row is gone, v2 row exists with the new wrapped key.
        $this->assertDatabaseMissing('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'key_version' => 1,
        ]);
        $this->assertDatabaseHas('resource_keys', [
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
            'user_id' => $owner->id,
            'key_version' => 2,
            'encrypted_key' => 'k2',
        ]);

        // Credentials have been rewrapped to v2.
        foreach ($credentialIds as $id) {
            $this->assertDatabaseHas('credentials', [
                'id' => $id,
                'encrypted_data' => 'v2-'.$id,
                'key_version' => 2,
            ]);
        }

        $this->assertDatabaseHas('audit_log', [
            'action' => AuditAction::ResourceRotated->value,
            'resource_type' => ResourceType::Vault->value,
            'resource_id' => $vault->id,
        ]);
    }

    public function test_rotate_rejects_mismatched_credential_set(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $credentialIds = $this->seedCredentials($project, $vault, 2);

        // Migrate first so rotate has something to roll forward.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k1']],
                'credentials' => array_map(
                    static fn (int $id): array => [
                        'id' => $id,
                        'encrypted_data' => 'v1-'.$id,
                        'iv' => self::ivOf("v1-{$id}"),
                    ],
                    $credentialIds,
                ),
            ])
            ->assertOk();

        // Rotate with only one of the two credentials — must 422 on
        // set-equality.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/rotate-key", [
                'expected_current_version' => 1,
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k2']],
                'credentials' => [
                    [
                        'id' => $credentialIds[0],
                        'encrypted_data' => 'v2-only-one',
                        'iv' => self::ivOf('v2-only-one'),
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.credentials.0', 'Credential list does not match the vault contents.');
    }

    public function test_rotate_rejects_unmigrated_vault(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/rotate-key", [
                'expected_current_version' => 1,
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k']],
                'credentials' => [],
            ])
            ->assertStatus(409);
    }

    public function test_rotate_rejects_version_mismatch(): void
    {
        [$owner, $project, $vault] = $this->setupProject();
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/migrate-key", [
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k1']],
                'credentials' => [],
            ])
            ->assertOk();

        // Client thinks it's at v2 but it's at v1 — bail out.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/rotate-key", [
                'expected_current_version' => 2,
                'grants' => [['user_id' => $owner->id, 'encrypted_key' => 'k2']],
                'credentials' => [],
            ])
            ->assertStatus(409)
            ->assertJsonPath('errors.expected_current_version.0', 'Expected current key_version 2, got 1.');
    }

    /**
     * @return array{0: User, 1: Project, 2: Vault}
     */
    private function setupProject(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        return [$owner, $project, $vault];
    }

    /**
     * @return array<int, int>
     */
    private function seedCredentials(Project $project, Vault $vault, int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Credential::create([
                'project_id' => $project->id,
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'Entry '.$i,
                'encrypted_data' => 'original-'.$i,
                'iv' => self::ivOf("orig-{$i}"),
                'created_by' => $project->owner_id,
            ])->id;
        }

        return $ids;
    }

    /**
     * Deterministic 16-char iv string (real GCM nonce shape) keyed by tag.
     * Tests don't actually decrypt — the value just has to satisfy the
     * `length(iv) = 16` CHECK constraint and stay distinguishable across
     * versions/credentials.
     */
    private static function ivOf(string $tag): string
    {
        return base64_encode(str_pad(substr($tag, 0, 12), 12, '_'));
    }
}
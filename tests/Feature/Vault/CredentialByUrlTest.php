<?php

namespace Tests\Feature\Vault;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Browser extension credential lookup by URL domain.
 */
class CredentialByUrlTest extends TestCase
{
    public function test_returns_credentials_matching_domain(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey();
        $this->seedCredential($project, $vault, $user, 'GitHub Prod', 'https://github.com/login');
        $this->seedCredential($project, $vault, $user, 'Gmail', 'https://mail.google.com');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=github.com')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('GitHub Prod', $data[0]['name']);
        $this->assertArrayHasKey('my_wrapped_key', $data[0]);
        $this->assertArrayHasKey('encrypted_data', $data[0]);
        $this->assertArrayHasKey('vault_name', $data[0]);
        $this->assertArrayHasKey('project_name', $data[0]);
        $this->assertArrayHasKey('organisation_name', $data[0]);
    }

    public function test_subdomain_collapsing(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey();
        $this->seedCredential($project, $vault, $user, 'GitHub API', 'https://api.github.com/v3');

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=https://github.com')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'GitHub API');
    }

    public function test_no_match_returns_empty_array(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey();
        $this->seedCredential($project, $vault, $user, 'GitHub', 'https://github.com');

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=gitlab.com')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_missing_url_returns_422(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url')
            ->assertStatus(422);
    }

    public function test_excludes_credentials_without_wrapped_key(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey(writeKey: false);
        $this->seedCredential($project, $vault, $user, 'NoKey', 'https://example.com');

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=example.com')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_excludes_archived_credentials(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey();
        $this->seedCredential($project, $vault, $user, 'Archived', 'https://example.com', archived: true);

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=example.com')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_type_filter(): void
    {
        [$user, $project, $vault] = $this->setupVaultWithKey();
        $this->seedCredential($project, $vault, $user, 'SSH Key', 'https://github.com', type: 'ssh');
        $this->seedCredential($project, $vault, $user, 'Login', 'https://github.com', type: 'login');

        $this->actingAs($user)
            ->getJson('/api/v1/me/credentials/by-url?url=github.com&type=ssh')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'SSH Key');
    }

    public function test_inaccessible_credential_excluded(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();
        $this->seedCredential($project, $vault, $owner, 'Secret', 'https://secret.com');

        // Second user has no grants on this project/vault.
        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson('/api/v1/me/credentials/by-url?url=secret.com')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Project\Project, 2: Vault}
     */
    private function setupVaultWithKey(bool $writeKey = true): array
    {
        $user = UserFactory::create();
        $project = ProjectFactory::forOwner($user);
        $vault = $project->vaults()->where('is_default', true)->firstOrFail();

        if ($writeKey) {
            $vault->forceFill(['migrated_at' => now()])->save();
            ResourceKey::create([
                'resource_type' => ResourceType::Vault->value,
                'resource_id' => $vault->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'encrypted_key' => 'wrapped-test-key',
                'key_version' => 1,
            ]);
        }

        return [$user, $project, $vault];
    }

    private function seedCredential(
        $project,
        Vault $vault,
        $user,
        string $name,
        string $url,
        string $type = 'login',
        bool $archived = false,
    ): Credential {
        return Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => $type,
            'name' => $name,
            'url' => $url,
            'encrypted_data' => 'ciphertext-'.bin2hex(random_bytes(4)),
            'iv' => base64_encode(random_bytes(12)),
            'key_version' => 1,
            'is_archived' => $archived,
            'created_by' => $user->id,
        ]);
    }
}

<?php

namespace Tests\Feature\Vault;

use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\ShareLink;
use App\Models\Vault\Vault;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * Universal share-links — credential resource type. Exercises the
 * zero-knowledge auth_proof flow end-to-end. Other resource types are
 * covered by their own files in tests/Feature/Sharing/.
 */
class ShareLinkTest extends TestCase
{
    public function test_owner_can_create_credential_share_link_with_auth_proof(): void
    {
        [$owner, $credential] = $this->seedCredential();

        $payload = $this->credentialPayload($credential);

        $response = $this->actingAs($owner)
            ->postJson('/api/v1/share-links', $payload);

        $response->assertCreated()
            ->assertJsonPath('share_link.resource_type', 'credential')
            ->assertJsonPath('share_link.auth_scheme', 'auth_proof')
            ->assertJsonPath('share_link.max_views', 1);

        $this->assertDatabaseHas('share_links', [
            'token_hash' => $payload['token_hash'],
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
        ]);
    }

    public function test_credential_share_requires_auth_proof(): void
    {
        [$owner, $credential] = $this->seedCredential();

        $payload = $this->credentialPayload($credential);
        unset($payload['auth_proof'], $payload['key_salt']);

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['auth_proof']);
    }

    public function test_owner_cannot_exceed_five_active_credential_share_links(): void
    {
        [$owner, $credential] = $this->seedCredential();

        for ($i = 0; $i < 5; $i++) {
            ShareLink::create([
                'resource_type' => 'credential',
                'resource_id' => $credential->id,
                'project_id' => $credential->project_id,
                'created_by' => $owner->id,
                'token_hash' => str_pad((string) $i, 64, '0', STR_PAD_LEFT),
                'snapshot_payload' => ['encrypted_blob' => 'x', 'blob_iv' => 'y', 'key_salt' => 'z'],
                'expires_at' => Carbon::now()->addHour(),
                'auth_proof_hash' => str_repeat('a', 64),
            ]);
        }

        $this->actingAs($owner)
            ->postJson('/api/v1/share-links', $this->credentialPayload($credential))
            ->assertStatus(409)
            ->assertJsonPath('code', 'too_many_active_shares');
    }

    public function test_public_meta_returns_auth_scheme_and_key_salt(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: bin2hex(random_bytes(16)));

        $this->getJson('/api/v1/share-links/'.$link->token_hash)
            ->assertOk()
            ->assertJsonPath('auth_scheme', 'auth_proof')
            ->assertJsonStructure(['share_link', 'auth_scheme', 'key_salt']);
    }

    public function test_unlock_with_correct_auth_proof_returns_snapshot(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $authProofRaw = random_bytes(32);
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: $authProofRaw);

        $this->postJson("/api/v1/share-links/{$link->token_hash}/unlock", [
            'token' => $token,
            'auth_proof' => base64_encode($authProofRaw),
        ])->assertOk()
            ->assertJsonStructure(['snapshot_payload' => ['encrypted_blob', 'blob_iv', 'key_salt']]);

        $this->assertDatabaseHas('share_link_views', ['share_link_id' => $link->id]);
    }

    public function test_unlock_with_wrong_auth_proof_returns_401(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: random_bytes(32));

        $this->postJson("/api/v1/share-links/{$link->token_hash}/unlock", [
            'token' => $token,
            'auth_proof' => base64_encode(random_bytes(32)),
        ])->assertStatus(401)
            ->assertJsonPath('code', 'invalid_password');
    }

    public function test_unlock_auto_revokes_when_max_views_reached(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $authProofRaw = random_bytes(32);
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: $authProofRaw, maxViews: 1);

        $this->postJson("/api/v1/share-links/{$link->token_hash}/unlock", [
            'token' => $token,
            'auth_proof' => base64_encode($authProofRaw),
        ])->assertOk();

        // After hitting max_views the link is auto-revoked in the same
        // transaction; subsequent lookups return 404 (single not-found
        // shape — no enumeration leak between expired/revoked/missing).
        $this->postJson("/api/v1/share-links/{$link->token_hash}/unlock", [
            'token' => $token,
            'auth_proof' => base64_encode($authProofRaw),
        ])->assertStatus(404);

        $this->assertNotNull($link->fresh()->revoked_at);
    }

    public function test_owner_can_revoke_share_link(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: random_bytes(32));

        $this->actingAs($owner)
            ->deleteJson("/api/v1/share-links/{$link->id}")
            ->assertNoContent();

        $this->assertNotNull($link->fresh()->revoked_at);

        $this->getJson("/api/v1/share-links/{$link->token_hash}")->assertNotFound();
    }

    public function test_public_response_sets_security_headers(): void
    {
        [$owner, $credential] = $this->seedCredential();
        $token = bin2hex(random_bytes(32));
        $link = $this->createCredentialLink($owner, $credential, $token, authProof: random_bytes(32));

        $this->getJson("/api/v1/share-links/{$link->token_hash}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_prune_command_removes_old_revoked_rows(): void
    {
        [$owner, $credential] = $this->seedCredential();

        $old = ShareLink::create([
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
            'project_id' => $credential->project_id,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', 'old'),
            'snapshot_payload' => ['encrypted_blob' => 'x', 'blob_iv' => 'y', 'key_salt' => 'z'],
            'expires_at' => Carbon::now()->subDays(40),
            'revoked_at' => Carbon::now()->subDays(40),
            'auth_proof_hash' => str_repeat('a', 64),
        ]);

        $recent = ShareLink::create([
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
            'project_id' => $credential->project_id,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', 'recent'),
            'snapshot_payload' => ['encrypted_blob' => 'x', 'blob_iv' => 'y', 'key_salt' => 'z'],
            'expires_at' => Carbon::now()->subHour(),
            'revoked_at' => Carbon::now()->subHour(),
            'auth_proof_hash' => str_repeat('a', 64),
        ]);

        $this->artisan('shares:prune')->assertSuccessful();

        $this->assertDatabaseMissing('share_links', ['id' => $old->id]);
        $this->assertDatabaseHas('share_links', ['id' => $recent->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialPayload(Credential $credential): array
    {
        return [
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
            'name' => 'Production DB read-only',
            'token_hash' => hash('sha256', 'token-'.bin2hex(random_bytes(8))),
            'expires_at' => Carbon::now()->addDay()->toIso8601String(),
            'auth_proof' => base64_encode(random_bytes(32)),
            'key_salt' => base64_encode(random_bytes(16)),
            'encrypted_blob' => base64_encode(random_bytes(64)),
            'blob_iv' => base64_encode(random_bytes(12)),
        ];
    }

    private function createCredentialLink(
        User $owner,
        Credential $credential,
        string $rawToken,
        string $authProof,
        ?int $maxViews = null,
    ): ShareLink {
        return ShareLink::create([
            'resource_type' => 'credential',
            'resource_id' => $credential->id,
            'project_id' => $credential->project_id,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', $rawToken),
            'snapshot_payload' => [
                'encrypted_blob' => base64_encode('cipher'),
                'blob_iv' => base64_encode(random_bytes(12)),
                'key_salt' => base64_encode(random_bytes(16)),
            ],
            'expires_at' => Carbon::now()->addHour(),
            'max_views' => $maxViews,
            'auth_proof_hash' => hash('sha256', $authProof),
        ]);
    }

    /**
     * @return array{0: User, 1: Credential}
     */
    private function seedCredential(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $credential = Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'Shared',
            'encrypted_data' => 'cipher',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);

        return [$owner, $credential];
    }
}

<?php

namespace Tests\Feature\Vault;

use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class CredentialCrudTest extends TestCase
{
    public function test_owner_can_store_credential_with_ciphertext(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $payload = [
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'GitHub',
            'url' => 'https://github.com',
            'encrypted_data' => base64_encode(random_bytes(256)),
            'iv' => base64_encode(random_bytes(12)),
            'tags' => ['work', 'oauth'],
        ];

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/credentials", $payload);

        $response->assertCreated()
            ->assertJsonPath('credential.name', 'GitHub')
            ->assertJsonPath('credential.type', 'login')
            ->assertJsonPath('credential.encrypted_data', $payload['encrypted_data'])
            ->assertJsonPath('credential.tags', ['work', 'oauth']);

        $this->assertDatabaseHas('credentials', [
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'encrypted_data' => $payload['encrypted_data'],
        ]);
    }

    public function test_update_writes_history_row_before_applying_change(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $createResponse = $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/credentials", [
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'GitHub',
                'encrypted_data' => 'original-ciphertext',
                'iv' => str_repeat('A', 16),
            ])
            ->assertCreated();

        $credentialId = $createResponse->json('credential.id');

        $this->actingAs($owner)
            ->patchJson("/api/v1/credentials/{$credentialId}", [
                'encrypted_data' => 'new-ciphertext',
                'iv' => str_repeat('B', 16),
            ])
            ->assertOk()
            ->assertJsonPath('credential.encrypted_data', 'new-ciphertext');

        $this->assertDatabaseHas('credential_history', [
            'credential_id' => $credentialId,
            'encrypted_data' => 'original-ciphertext',
            'iv' => str_repeat('A', 16),
            'changed_by' => $owner->id,
        ]);
    }

    public function test_store_rejects_empty_iv(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/credentials", [
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'GitHub',
                'encrypted_data' => base64_encode(random_bytes(256)),
                'iv' => '',
                'tags' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iv']);
    }

    public function test_store_rejects_iv_shorter_than_twelve_bytes(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/credentials", [
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'GitHub',
                'encrypted_data' => base64_encode(random_bytes(256)),
                // Base64 of 8 random bytes — shorter than the required 12.
                'iv' => base64_encode(random_bytes(8)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iv']);
    }

    public function test_update_rejects_iv_shorter_than_twelve_bytes(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $credential = $this->makeCredential($owner, $project->id);

        $this->actingAs($owner)
            ->patchJson("/api/v1/credentials/{$credential->id}", [
                'encrypted_data' => 'updated',
                'iv' => base64_encode(random_bytes(8)),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['iv']);
    }

    public function test_update_rejects_encrypted_data_without_iv(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $credential = $this->makeCredential($owner, $project->id);

        $this->actingAs($owner)
            ->patchJson("/api/v1/credentials/{$credential->id}", [
                'encrypted_data' => 'half-updated',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encrypted_data']);
    }

    public function test_index_supports_search_by_name(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();

        Credential::create([
            'project_id' => $project->id, 'vault_id' => $vault->id,
            'type' => 'login', 'name' => 'GitLab', 'encrypted_data' => 'x', 'iv' => str_repeat('A', 16), 'created_by' => $owner->id,
        ]);
        Credential::create([
            'project_id' => $project->id, 'vault_id' => $vault->id,
            'type' => 'login', 'name' => 'Bitbucket', 'encrypted_data' => 'x', 'iv' => str_repeat('A', 16), 'created_by' => $owner->id,
        ]);

        $names = collect($this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/credentials?q=lab")
            ->assertOk()
            ->json('data'))
            ->pluck('name')
            ->all();

        $this->assertContains('GitLab', $names);
        $this->assertNotContains('Bitbucket', $names);
    }

    public function test_soft_delete_removes_from_index(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $credential = $this->makeCredential($owner, $project->id);

        $this->actingAs($owner)
            ->deleteJson("/api/v1/credentials/{$credential->id}")
            ->assertNoContent();

        $ids = collect($this->actingAs($owner)
            ->getJson("/api/v1/projects/{$project->id}/credentials")
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($credential->id, $ids);
        $this->assertSoftDeleted('credentials', ['id' => $credential->id]);
    }

    public function test_outsider_cannot_access_credentials(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $credential = $this->makeCredential($owner, $project->id);

        $outsider = UserFactory::create();

        $this->actingAs($outsider)
            ->getJson("/api/v1/credentials/{$credential->id}")
            ->assertForbidden();
    }

    private function makeCredential(User $owner, int $projectId): Credential
    {
        $vault = Vault::query()->where('project_id', $projectId)->where('is_default', true)->firstOrFail();

        return Credential::create([
            'project_id' => $projectId,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'Seeded',
            'encrypted_data' => 'cipher',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);
    }
}

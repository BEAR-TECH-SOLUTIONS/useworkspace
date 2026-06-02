<?php

namespace Tests\Feature\Vault;

use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * End-to-end Pattern B smoke test: a user invited via
 * POST /vaults/{vault}/members (no project-level grant) must be able
 * to walk the same API surface as a project member for the ONE vault
 * they were granted on — list vaults, read credentials scoped to that
 * vault, view the parent project, and see their wrapped key.
 */
class PatternBCredentialAccessTest extends TestCase
{
    public function test_pattern_b_user_can_read_credentials_in_granted_vault(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $granted = Vault::create([
            'project_id' => $project->id,
            'name' => 'Granted',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);
        $sibling = Vault::create([
            'project_id' => $project->id,
            'name' => 'Sibling',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        Credential::create([
            'project_id' => $project->id,
            'vault_id' => $granted->id,
            'type' => 'login',
            'name' => 'Granted secret',
            'encrypted_data' => 'ct-granted',
            'iv' => str_repeat('A', 16),
            'created_by' => $owner->id,
        ]);
        Credential::create([
            'project_id' => $project->id,
            'vault_id' => $sibling->id,
            'type' => 'login',
            'name' => 'Sibling secret',
            'encrypted_data' => 'ct-sibling',
            'iv' => str_repeat('B', 16),
            'created_by' => $owner->id,
        ]);
        // "All entries" credential (vault_id NULL) must NOT be visible
        // to a Pattern B user.
        Credential::create([
            'project_id' => $project->id,
            'vault_id' => null,
            'type' => 'login',
            'name' => 'Orphan secret',
            'encrypted_data' => 'ct-orphan',
            'iv' => str_repeat('C', 16),
            'created_by' => $owner->id,
        ]);

        // Grant scoped user direct access to just `$granted`.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$granted->id}/members", [
                'email' => $scoped->email,
                'role' => 'editor',
                'encrypted_key' => base64_encode('wrapped-for-scoped'),
            ])
            ->assertCreated();

        // 1. GET /projects/{project} should be reachable for the scoped user.
        $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('project.id', $project->id);

        // 2. GET /projects/{project}/vaults returns ONLY the granted
        //    vault and its my_wrapped_key is populated.
        $vaultList = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/vaults")
            ->assertOk();

        $vaultData = $vaultList->json('data');
        $this->assertCount(1, $vaultData);
        $this->assertSame($granted->id, $vaultData[0]['id']);
        $this->assertNotNull($vaultData[0]['my_wrapped_key']);
        $this->assertSame(
            base64_encode('wrapped-for-scoped'),
            $vaultData[0]['my_wrapped_key']['encrypted_key'],
        );

        // 3. GET /projects/{project}/credentials?vault_id=granted works
        //    and returns the granted secret, nothing else.
        $credList = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/credentials?vault_id={$granted->id}")
            ->assertOk();

        $credData = $credList->json('data');
        $this->assertCount(1, $credData);
        $this->assertSame('Granted secret', $credData[0]['name']);

        // 4. GET without vault_id filter returns only the granted
        //    secret — sibling and orphan credentials are NOT leaked.
        $allList = $this->actingAs($scoped)
            ->getJson("/api/v1/projects/{$project->id}/credentials")
            ->assertOk();

        $allNames = array_column($allList->json('data'), 'name');
        $this->assertSame(['Granted secret'], $allNames);
    }

    public function test_pattern_b_editor_can_create_credential_in_granted_vault(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $vault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Granted',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $scoped->email,
                'role' => 'editor',
                'encrypted_key' => base64_encode('wrapped'),
            ])
            ->assertCreated();

        $this->actingAs($scoped)
            ->postJson("/api/v1/projects/{$project->id}/credentials", [
                'vault_id' => $vault->id,
                'type' => 'login',
                'name' => 'New secret',
                'encrypted_data' => 'ct',
                'iv' => str_repeat('A', 16),
            ])
            ->assertCreated()
            ->assertJsonPath('credential.name', 'New secret');
    }

    public function test_pattern_b_user_cannot_read_foreign_project(): void
    {
        $owner = UserFactory::create();
        $outsider = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->getJson("/api/v1/projects/{$project->id}/credentials")
            ->assertForbidden();
    }

    public function test_me_access_surfaces_pattern_b_grants(): void
    {
        $owner = UserFactory::create();
        $scoped = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $vault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Granted',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$vault->id}/members", [
                'email' => $scoped->email,
                'role' => 'editor',
                'encrypted_key' => base64_encode('wrapped'),
            ])
            ->assertCreated();

        $response = $this->actingAs($scoped)
            ->getJson('/api/v1/me/access')
            ->assertOk();

        $orgs = $response->json('organisations');
        $this->assertNotEmpty($orgs);

        // Find the project containing our scoped vault grant.
        $foundVaultResource = null;
        $foundProjectRole = 'missing';
        foreach ($orgs as $org) {
            foreach ($org['projects'] as $p) {
                if ($p['id'] !== $project->id) {
                    continue;
                }
                $foundProjectRole = $p['role']; // may be null for pure Pattern B
                foreach ($p['resources'] as $r) {
                    if ($r['type'] === 'vault' && $r['id'] === $vault->id) {
                        $foundVaultResource = $r;
                    }
                }
            }
        }

        $this->assertNotNull($foundVaultResource, 'vault grant missing from /me/access');
        $this->assertSame('editor', $foundVaultResource['role']);
        $this->assertSame(base64_encode('wrapped'), $foundVaultResource['encrypted_key']);
        $this->assertNull($foundProjectRole, 'Pattern B user must not show a project-level role');
    }

    public function test_me_access_owner_sees_owner_role(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/access')
            ->assertOk();

        $projectRole = null;
        foreach ($response->json('organisations') as $org) {
            foreach ($org['projects'] as $p) {
                if ($p['id'] === $project->id) {
                    $projectRole = $p['role'];
                }
            }
        }

        $this->assertSame('owner', $projectRole);
    }

    public function test_me_access_expands_cascaded_resources_for_project_owner(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        // Add a second vault so we can assert cascade expansion covers
        // more than just the bootstrapped defaults.
        $extraVault = Vault::create([
            'project_id' => $project->id,
            'name' => 'Extra',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/v1/me/access')
            ->assertOk();

        $projectNode = null;
        foreach ($response->json('organisations') as $org) {
            foreach ($org['projects'] as $p) {
                if ($p['id'] === $project->id) {
                    $projectNode = $p;
                }
            }
        }

        $this->assertNotNull($projectNode);
        $this->assertSame('owner', $projectNode['role']);

        // Every child resource type should cascade in as 'owner'.
        $byType = [];
        foreach ($projectNode['resources'] as $r) {
            $byType[$r['type']] ??= [];
            $byType[$r['type']][] = $r;
        }

        $this->assertArrayHasKey('vault', $byType);
        $this->assertArrayHasKey('board', $byType);
        $this->assertArrayHasKey('bucket', $byType);

        $vaultIds = array_column($byType['vault'], 'id');
        $this->assertContains($extraVault->id, $vaultIds);

        foreach ($byType['vault'] as $v) {
            $this->assertSame('owner', $v['role']);
            // encrypted_key is null until the vault is migrated, but
            // the key MUST be present in the entry shape.
            $this->assertArrayHasKey('encrypted_key', $v);
            $this->assertArrayHasKey('key_version', $v);
        }

        foreach ($byType['board'] as $b) {
            $this->assertSame('owner', $b['role']);
        }

        foreach ($byType['bucket'] as $b) {
            $this->assertSame('owner', $b['role']);
        }
    }

    public function test_me_access_cascades_editor_role_with_direct_override(): void
    {
        $owner = UserFactory::create();
        $editor = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);

        // Project-level Editor grant (Pattern A).
        $this->actingAs($owner)
            ->postJson("/api/v1/projects/{$project->id}/members", [
                'email' => $editor->email,
                'role' => 'editor',
            ])
            ->assertCreated();

        // One extra vault so we can override the cascade on just that one.
        $override = Vault::create([
            'project_id' => $project->id,
            'name' => 'Override',
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        // Direct Viewer grant on the override vault — more specific
        // than the project-level Editor cascade.
        $this->actingAs($owner)
            ->postJson("/api/v1/vaults/{$override->id}/members", [
                'email' => $editor->email,
                'role' => 'viewer',
                'encrypted_key' => base64_encode('wrapped'),
            ])
            ->assertCreated();

        $response = $this->actingAs($editor)
            ->getJson('/api/v1/me/access')
            ->assertOk();

        $projectNode = null;
        foreach ($response->json('organisations') as $org) {
            foreach ($org['projects'] as $p) {
                if ($p['id'] === $project->id) {
                    $projectNode = $p;
                }
            }
        }

        $this->assertNotNull($projectNode);
        $this->assertSame('editor', $projectNode['role']);

        $overrideEntry = null;
        $otherVaultRoles = [];
        foreach ($projectNode['resources'] as $r) {
            if ($r['type'] !== 'vault') {
                continue;
            }

            if ($r['id'] === $override->id) {
                $overrideEntry = $r;
            } else {
                $otherVaultRoles[] = $r['role'];
            }
        }

        $this->assertNotNull($overrideEntry);
        $this->assertSame('viewer', $overrideEntry['role'], 'direct grant must override cascade');
        $this->assertSame(base64_encode('wrapped'), $overrideEntry['encrypted_key']);
        // Sibling vaults still cascade at the project-level Editor role.
        $this->assertNotEmpty($otherVaultRoles);
        foreach ($otherVaultRoles as $role) {
            $this->assertSame('editor', $role);
        }
    }
}
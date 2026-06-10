<?php

namespace Tests\Feature\Sharing;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\ShareLink;
use App\Models\Vault\ShareLinkView;
use App\Models\Vault\Vault;
use Illuminate\Support\Carbon;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * GET /api/v1/share-links/by-hash/{tokenHash}
 *
 * Navigation-only lookup used by the desktop client after the
 * `usework://s/{tokenHash}` deep-link opens the app. Must:
 *  - 200 with the right navigation ids for authenticated users
 *    regardless of whether they own the share;
 *  - 401 anonymous;
 *  - 404 on unknown hashes only — revoked / expired return 200 with
 *    the corresponding flag so the client can render type-correct
 *    copy;
 *  - never increment view_count or insert share_views rows.
 */
class ShareLinkNavigationTest extends TestCase
{
    public function test_owner_of_credential_share_gets_navigation_payload_with_vault_id(): void
    {
        [$owner, $credential, $vault, $project] = $this->seedCredentialShare();

        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $response->assertJsonPath('share.resource_type', 'credential');
        $response->assertJsonPath('share.resource_id', $credential->id);
        $response->assertJsonPath('share.project_id', $project->id);
        $response->assertJsonPath('share.workspace_id', $project->organisation_id);
        $response->assertJsonPath('share.vault_id', $vault->id);
        $response->assertJsonPath('share.has_access', true);
        $response->assertJsonPath('share.revoked', false);
        $response->assertJsonPath('share.expired', false);
    }

    public function test_non_owner_with_project_grant_gets_has_access_true(): void
    {
        [$owner, $credential, $vault, $project] = $this->seedCredentialShare();

        $member = UserFactory::create();
        ResourcePermission::create([
            'user_id' => $member->id,
            'resource_type' => ResourceType::Project->value,
            'resource_id' => $project->id,
            'project_id' => $project->id,
            'role' => MemberRole::Editor->value,
            'granted_by' => $owner->id,
        ]);

        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        $this->actingAs($member)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk()
            ->assertJsonPath('share.has_access', true)
            ->assertJsonPath('share.vault_id', $vault->id);
    }

    public function test_outsider_gets_has_access_false_but_still_sees_navigation_ids(): void
    {
        [$owner, $credential, $vault, $project] = $this->seedCredentialShare();

        $outsider = UserFactory::create();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        $response = $this->actingAs($outsider)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $response->assertJsonPath('share.has_access', false);
        // Ids still populated — the client uses them for the fallback
        // copy ("you have a share for project X in workspace Y, open it
        // in the web viewer").
        $response->assertJsonPath('share.vault_id', $vault->id);
        $response->assertJsonPath('share.project_id', $project->id);
        $response->assertJsonPath('share.workspace_id', $project->organisation_id);
    }

    public function test_anonymous_caller_gets_401(): void
    {
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        $this->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertStatus(401);
    }

    public function test_unknown_token_hash_returns_404_share_not_found(): void
    {
        $user = UserFactory::create();

        $bogusHash = str_repeat('a', 64);
        $this->actingAs($user)
            ->getJson("/api/v1/share-links/by-hash/{$bogusHash}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'share_not_found');
    }

    public function test_soft_revoked_share_returns_200_with_revoked_true(): void
    {
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);
        $link->update(['revoked_at' => Carbon::now()]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $response->assertJsonPath('share.revoked', true);
        $response->assertJsonPath('share.expired', false);
    }

    public function test_expired_share_returns_200_with_expired_true(): void
    {
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);
        $link->update(['expires_at' => Carbon::now()->subHour()]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $response->assertJsonPath('share.expired', true);
        $response->assertJsonPath('share.revoked', false);
    }

    public function test_share_at_max_views_returns_expired_true(): void
    {
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);
        $link->update(['max_views' => 3, 'view_count' => 3]);

        $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk()
            ->assertJsonPath('share.expired', true);
    }

    public function test_endpoint_does_not_increment_view_count_or_log_views(): void
    {
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        // Hit the endpoint repeatedly — should be a pure read.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($owner)
                ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
                ->assertOk();
        }

        $link->refresh();
        $this->assertSame(0, (int) $link->view_count);
        $this->assertSame(0, ShareLinkView::query()->where('share_link_id', $link->id)->count());
    }

    public function test_task_share_response_includes_board_id_not_vault_id(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $board = TaskBoard::query()->where('project_id', $project->id)->firstOrFail();
        $column = TaskColumn::query()->where('board_id', $board->id)->firstOrFail();
        $task = TaskItem::create([
            'project_id' => $project->id,
            'column_id' => $column->id,
            'title' => 'Test task',
            'created_by' => $owner->id,
        ]);

        $link = $this->makeLink('task', $task->id, $project->id, $owner);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $response->assertJsonPath('share.resource_type', 'task');
        $response->assertJsonPath('share.resource_id', $task->id);
        $response->assertJsonPath('share.board_id', $board->id);
        // Type-specific ids are mutually exclusive — task shares
        // shouldn't carry a vault_id / bucket_id field.
        $this->assertArrayNotHasKey('vault_id', $response->json('share'));
        $this->assertArrayNotHasKey('bucket_id', $response->json('share'));
    }

    public function test_expense_share_response_includes_bucket_id(): void
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $bucket = ExpenseBucket::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();
        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => 'Test expense',
            'category' => 'saas',
            'amount' => '12.34',
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'created_by' => $owner->id,
        ]);

        $link = $this->makeLink('expense', $expense->id, $project->id, $owner);

        $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk()
            ->assertJsonPath('share.resource_type', 'expense')
            ->assertJsonPath('share.bucket_id', $bucket->id);
    }

    public function test_response_never_carries_encrypted_blob_or_password_material(): void
    {
        // Defense in depth: even if `snapshot_payload` happens to
        // include an `encrypted_blob` field for the recipient flow,
        // the navigation endpoint must NEVER surface it (would leak
        // ciphertext to anyone who happened to hold a hash).
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);
        $link->update([
            'snapshot_payload' => [
                'name' => 'Adobe creds',
                'encrypted_blob' => 'CIPHERTEXT_DO_NOT_LEAK',
                'blob_iv' => 'IV_DO_NOT_LEAK',
                'key_salt' => 'SALT_DO_NOT_LEAK',
            ],
            'password_hash' => 'argon2id$dont_leak_me_either',
            'auth_proof_hash' => str_repeat('f', 64),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();

        $body = $response->json();
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('CIPHERTEXT_DO_NOT_LEAK', $json);
        $this->assertStringNotContainsString('IV_DO_NOT_LEAK', $json);
        $this->assertStringNotContainsString('SALT_DO_NOT_LEAK', $json);
        $this->assertStringNotContainsString('dont_leak_me_either', $json);
        $this->assertStringNotContainsString('encrypted_blob', $json);
        $this->assertStringNotContainsString('password_hash', $json);
        $this->assertStringNotContainsString('auth_proof_hash', $json);
    }

    public function test_works_without_master_password_handshake(): void
    {
        // Deep-link lookups happen before the desktop client has run
        // the master-password setup (the share might be a board / task
        // share that doesn't need vault crypto at all). The route MUST
        // sit outside the `master-password.set` middleware group.
        [$owner, $credential, $_v, $project] = $this->seedCredentialShare();
        $link = $this->makeLink('credential', $credential->id, $project->id, $owner);

        // A user that has not run the master-password setup. (The
        // factory creates a User row but doesn't set up the crypto
        // bundle, so this user would 409 on any /vaults/* call.)
        $user = UserFactory::create();

        $this->actingAs($user)
            ->getJson("/api/v1/share-links/by-hash/{$link->token_hash}")
            ->assertOk();
    }

    // ── helpers ──────────────────────────────────────────────────

    /**
     * @return array{0: User, 1: Credential, 2: Vault, 3: Project}
     */
    private function seedCredentialShare(): array
    {
        $owner = UserFactory::create();
        $project = ProjectFactory::forOwner($owner);
        $vault = Vault::query()
            ->where('project_id', $project->id)
            ->where('is_default', true)
            ->firstOrFail();

        $credential = Credential::create([
            'project_id' => $project->id,
            'vault_id' => $vault->id,
            'type' => 'login',
            'name' => 'Adobe creds',
            'url' => 'https://adobe.example',
            'encrypted_data' => 'ciphertext',
            // Postgres CHECK constraint requires iv length == 16
            // (the AES-GCM nonce, base64-decoded). Any 16-char string
            // satisfies it for tests since the controller never reads
            // the ciphertext.
            'iv' => str_repeat('a', 16),
            'created_by' => $owner->id,
        ]);

        return [$owner, $credential, $vault, $project];
    }

    private function makeLink(string $type, int $resourceId, int $projectId, User $owner): ShareLink
    {
        $rawToken = bin2hex(random_bytes(32));

        return ShareLink::create([
            'resource_type' => $type,
            'resource_id' => $resourceId,
            'project_id' => $projectId,
            'created_by' => $owner->id,
            'token_hash' => hash('sha256', $rawToken),
            // Minimal snapshot — enough for the navigation endpoint
            // to surface the name field if it's there.
            'snapshot_payload' => ['name' => 'Test share'],
            'expires_at' => Carbon::now()->addHour(),
        ]);
    }
}

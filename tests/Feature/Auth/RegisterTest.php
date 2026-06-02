<?php

namespace Tests\Feature\Auth;

use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    public function test_user_can_register_and_receives_token(): void
    {
        $payload = $this->validPayload();

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'master_password_set'],
            ])
            ->assertJsonPath('user.email', $payload['email'])
            ->assertJsonPath('user.master_password_set', false);

        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        $this->assertNull($user->master_password_salt);
        $this->assertNull($user->public_key);
        $this->assertNotEmpty($user->password_hash);
    }

    public function test_registration_bootstraps_personal_project_with_defaults(): void
    {
        $payload = $this->validPayload();

        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $user = User::query()->where('email', $payload['email'])->firstOrFail();

        $organisation = Organisation::query()
            ->where('owner_id', $user->id)
            ->where('is_personal', true)
            ->firstOrFail();

        $project = Project::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_personal', true)
            ->firstOrFail();

        $this->assertDatabaseHas('resource_permissions', [
            'project_id' => $project->id,
            'resource_type' => 'project',
            'resource_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->assertTrue(TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->exists());
        $this->assertTrue(Vault::query()->where('project_id', $project->id)->where('is_default', true)->exists());
        $this->assertTrue(ExpenseBucket::query()->where('project_id', $project->id)->where('is_default', true)->exists());

        $board = TaskBoard::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $this->assertSame(3, $board->columns()->count());
    }

    public function test_registration_creates_workspace_membership(): void
    {
        // Pins the invariant from the workspace spec: every new user
        // gets exactly one personal organisation with themselves
        // materialised as its admin `organisation_members` row. Guards
        // against the "new user sees zero workspaces" regression.
        $payload = $this->validPayload();

        $response = $this->postJson('/api/v1/register', $payload)->assertCreated();
        $userId = (int) $response->json('user.id');

        $user = User::query()->whereKey($userId)->firstOrFail();

        $organisation = Organisation::query()
            ->where('owner_id', $user->id)
            ->where('is_personal', true)
            ->firstOrFail();

        $this->assertDatabaseHas('organisation_members', [
            'organisation_id' => $organisation->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'invited_by' => $user->id,
        ]);

        $this->assertSame(1, OrganisationMember::query()
            ->where('organisation_id', $organisation->id)
            ->where('user_id', $user->id)
            ->count());

        // And GET /workspaces must actually return it.
        $list = $this->actingAs($user)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($list);
        $this->assertCount(1, $list);
        $this->assertSame((int) $organisation->id, (int) $list[0]['id']);
        $this->assertTrue((bool) $list[0]['is_personal']);
        $this->assertSame('free', $list[0]['tier']);
        $this->assertSame(1, $list[0]['seat_cap']);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        $payload = $this->validPayload();
        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $second = $this->validPayload();
        $second['email'] = $payload['email'];

        $this->postJson('/api/v1/register', $second)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_does_not_accept_crypto_fields(): void
    {
        // Even if the client accidentally posts them, the crypto bundle must
        // travel via POST /auth/master-password instead so it's a separate,
        // explicit step.
        $payload = $this->validPayload();
        $payload['master_password_salt'] = 'ignored';
        $payload['public_key'] = 'ignored';

        $this->postJson('/api/v1/register', $payload)->assertCreated();

        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertNull($user->master_password_salt);
        $this->assertNull($user->public_key);
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(): array
    {
        $email = 'user'.bin2hex(random_bytes(4)).'@example.com';

        return [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ];
    }
}

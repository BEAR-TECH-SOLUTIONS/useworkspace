<?php

namespace Tests\Feature\Admin;

use App\Models\Identity\Organisation;
use App\Models\User;
use Tests\Support\ProjectFactory;
use Tests\Support\UserFactory;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    public function test_non_admin_cannot_list_users(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/users')
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_list_users(): void
    {
        $this->getJson('/api/v1/admin/users')->assertStatus(401);
    }

    public function test_admin_can_list_users_paginated(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        UserFactory::create();
        UserFactory::create();

        $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?per_page=10')
            ->assertOk()
            ->assertJsonStructure([
                'users' => [['id', 'name', 'email', 'is_admin', 'two_factor_enabled', 'workspace_count']],
                'pagination' => ['page', 'per_page', 'total', 'last_page'],
            ])
            ->assertJsonPath('pagination.per_page', 10);
    }

    public function test_admin_can_filter_users_by_search_and_is_admin(): void
    {
        $admin = UserFactory::create(['is_admin' => true, 'email' => 'admin-q@example.com']);
        UserFactory::create(['email' => 'alice@example.com', 'name' => 'Alice']);
        UserFactory::create(['email' => 'bob@example.com', 'name' => 'Bob']);

        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?q=alice')
            ->assertOk();

        $emails = collect($response->json('users'))->pluck('email')->all();
        $this->assertContains('alice@example.com', $emails);
        $this->assertNotContains('bob@example.com', $emails);

        $adminOnly = $this->actingAs($admin)
            ->getJson('/api/v1/admin/users?is_admin=true')
            ->assertOk()
            ->json('users');
        foreach ($adminOnly as $row) {
            $this->assertTrue($row['is_admin']);
        }
    }

    public function test_admin_can_show_user_with_memberships(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $target = UserFactory::create();
        ProjectFactory::forOwner($target); // creates an org + membership

        $this->actingAs($admin)
            ->getJson("/api/v1/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('user.id', $target->id)
            ->assertJsonStructure(['user' => ['memberships', 'owned_workspaces']]);
    }

    public function test_admin_can_toggle_is_admin_on_another_user(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $target = UserFactory::create(['is_admin' => false]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true);

        $this->assertTrue((bool) $target->refresh()->is_admin);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$admin->id}", ['is_admin' => false])
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_demote_self');

        $this->assertTrue((bool) $admin->refresh()->is_admin);
    }

    public function test_admin_can_rename_user(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $target = UserFactory::create(['name' => 'Old Name']);

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/users/{$target->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name');
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_delete_self');
    }

    public function test_admin_cannot_delete_user_who_still_owns_workspaces(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($target);
        // Force the owned workspace to be non-personal so the guard fires.
        Organisation::query()
            ->whereKey($project->organisation_id)
            ->update(['is_personal' => false]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'user_owns_workspaces');

        $this->assertNotNull(User::query()->whereKey($target->id)->first());
    }

    public function test_admin_can_delete_user_with_only_personal_workspaces(): void
    {
        $admin = UserFactory::create(['is_admin' => true]);
        $target = UserFactory::create();
        $project = ProjectFactory::forOwner($target);
        // Make the owned workspace personal so the guard does NOT fire.
        Organisation::query()
            ->whereKey($project->organisation_id)
            ->update(['is_personal' => true]);

        $this->actingAs($admin)
            ->deleteJson("/api/v1/admin/users/{$target->id}")
            ->assertStatus(204);

        $this->assertNull(User::query()->whereKey($target->id)->first());
    }
}

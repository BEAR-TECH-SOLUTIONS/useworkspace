<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    public function test_creates_admin_user_and_bootstraps_personal_workspace(): void
    {
        $exit = $this->artisan('tc:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'CorrectHorseBattery42!',
            '--name' => 'Site Admin',
        ])->run();

        $this->assertSame(0, $exit);

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue((bool) $user->is_admin);
        $this->assertSame('Site Admin', $user->name);
        $this->assertTrue(Hash::check('CorrectHorseBattery42!', $user->password_hash));

        // PersonalProjectFactory should have produced a personal org
        // owned by this user so the desktop client lands somewhere
        // immediately after sign-in.
        $this->assertDatabaseHas('organisations', [
            'owner_id' => $user->id,
            'is_personal' => true,
        ]);
    }

    public function test_returns_exit_code_two_when_user_already_exists(): void
    {
        User::create([
            'name' => 'Existing',
            'email' => 'admin@example.com',
            'password_hash' => Hash::make('whatever-strong-pw-19A!'),
        ]);

        $exit = $this->artisan('tc:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'AnotherStrongPw17!',
        ])->run();

        // Distinct exit code so install.sh can treat re-runs as a
        // no-op rather than a hard failure.
        $this->assertSame(2, $exit);
    }

    public function test_rejects_weak_password(): void
    {
        $exit = $this->artisan('tc:admin:create', [
            'email' => 'admin@example.com',
            '--password' => 'short',
        ])->run();

        $this->assertSame(1, $exit);
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
    }

    public function test_rejects_malformed_email(): void
    {
        $exit = $this->artisan('tc:admin:create', [
            'email' => 'not-an-email',
            '--password' => 'StrongEnoughPw13!',
        ])->run();

        $this->assertSame(1, $exit);
        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }
}

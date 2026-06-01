<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Identity\PersonalProjectFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * Bootstrap-time admin creator, invoked by selfhost/install.sh on the
 * first install so the operator has a sign-in account before the
 * desktop client connects. Reuses {@see PersonalProjectFactory} so
 * the bootstrap step is identical to a normal /register POST — the
 * only difference is the `is_admin = true` flag.
 *
 * Idempotent: if a user with that email already exists, exits 2 with
 * a clear message so install.sh can treat re-runs as a no-op.
 *
 * Audit H6: the password is sourced strictly from the TC_ADMIN_PASSWORD
 * environment variable, never the command line. Passing a secret as a
 * `--password=...` flag would expose it to any user with `ps` access
 * for the lifetime of the install step.
 */
class CreateAdmin extends Command
{
    protected $signature = 'tc:admin:create
        {email : Admin email address (used to log in)}
        {--name=Admin : Display name for the admin user}';

    protected $description = 'Create the initial admin user during self-hosted install. Reads the password from TC_ADMIN_PASSWORD. Idempotent on re-run.';

    public function handle(PersonalProjectFactory $bootstrapper): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) $this->option('name');

        // Pull the password from the environment only — never the
        // command line — to keep it out of `ps`/audit logs.
        $envPassword = getenv('TC_ADMIN_PASSWORD');
        if ($envPassword === false || $envPassword === '') {
            $this->error('TC_ADMIN_PASSWORD is not set. Export it (e.g. `read -rs TC_ADMIN_PASSWORD && export TC_ADMIN_PASSWORD`) before invoking tc:admin:create.');

            return self::FAILURE;
        }
        $password = (string) $envPassword;

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email:rfc', 'max:255'],
                'password' => ['required', Password::defaults()],
                'name' => ['required', 'string', 'max:100'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->warn("User {$email} already exists — leaving in place.");

            // Distinct exit code so install.sh can distinguish
            // "already done" from a real failure.
            return 2;
        }

        try {
            $user = DB::transaction(function () use ($email, $password, $name, $bootstrapper): User {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => Hash::make($password),
                    'is_admin' => true,
                ]);

                $bootstrapper->bootstrap($user);

                return $user;
            });
        } catch (Throwable $e) {
            $this->error('Admin creation failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Admin user created (id={$user->id}, email={$email}).");
        $this->line('Sign in with the desktop client and complete the master-password setup to enable vault features.');

        return self::SUCCESS;
    }
}

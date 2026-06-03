<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Operator-side password reset. Replaces a fragile `artisan tinker
 * --execute="..."` invocation (audit M14) that interpolated the email
 * into a PHP string, opening trivial shell + PHP-injection. This
 * command parses the email as an argument and reads the new password
 * from $TC_NEW_PASSWORD (preferred) or an interactive secret prompt —
 * neither path ever lands the secret in argv.
 *
 * Wired by selfhost/usework's `reset-password` subcommand.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'tc:user:reset-password {email : Email of the user whose password to reset}';

    protected $description = 'Reset a user account password. Reads the new password from TC_NEW_PASSWORD or prompts on stdin.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $emailValidator = Validator::make(['email' => $email], [
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);
        if ($emailValidator->fails()) {
            $this->error('Invalid email argument.');

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        $envPassword = getenv('TC_NEW_PASSWORD');
        $password = ($envPassword !== false && $envPassword !== '')
            ? (string) $envPassword
            : (string) $this->secret('New password');

        $passwordValidator = Validator::make(['password' => $password], [
            'password' => ['required', Password::defaults()],
        ]);
        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $line) {
                $this->error($line);
            }

            return self::FAILURE;
        }

        $user->forceFill([
            'password_hash' => Hash::make($password),
        ])->save();

        $this->info("Password reset for {$email}.");

        return self::SUCCESS;
    }
}

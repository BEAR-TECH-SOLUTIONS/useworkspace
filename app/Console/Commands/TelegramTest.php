<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnostic: send a Telegram message to a user synchronously (bypassing
 * the queue) and report exactly why it did or didn't go out. Use this to
 * tell apart "no bot token", "user not linked", and a live Telegram API
 * error without digging through the queue + logs.
 *
 *   php artisan telegram:test 42
 *   php artisan telegram:test user@example.com
 */
class TelegramTest extends Command
{
    protected $signature = 'telegram:test {user : User id or email}';

    protected $description = 'Send a test Telegram message to a user and report the outcome.';

    public function handle(TelegramService $telegram): int
    {
        $arg = (string) $this->argument('user');
        $user = ctype_digit($arg)
            ? User::find((int) $arg)
            : User::where('email', $arg)->first();

        if ($user === null) {
            $this->error("No user matched '{$arg}'.");

            return self::FAILURE;
        }

        $this->line("User: #{$user->id} {$user->email}");

        if (! $user->telegramLinked()) {
            $this->error('User has not linked a Telegram chat (telegram_chat_id is null).');

            return self::FAILURE;
        }

        $this->line("Linked chat_id: {$user->telegram_chat_id} (@{$user->telegram_username})");

        if (! $telegram->isConfigured()) {
            $this->warn('TELEGRAM_BOT_TOKEN is not set — send() no-ops. Set the token and retry.');

            return self::FAILURE;
        }

        try {
            $ok = $telegram->send(
                $user->telegram_chat_id,
                'Test message from usework.space ✅ — if you can read this, delivery works.',
            );
        } catch (Throwable $e) {
            $this->error('Telegram API call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($ok) {
            $this->info('Sent. Check the Telegram chat.');

            return self::SUCCESS;
        }

        $this->warn('send() returned false without throwing — check the log for the reason.');

        return self::FAILURE;
    }
}

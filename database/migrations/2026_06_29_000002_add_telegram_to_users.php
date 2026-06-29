<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #213B — optional Telegram delivery channel for notifications.
 *
 * A user links their Telegram account via a one-time pairing code
 * (`telegram_link_code`); the bot webhook binds the resulting `chat_id`.
 * `telegram_notification_prefs` holds the per-type opt-in list — NULL
 * means "all types" (the default), an array means "only these types".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('telegram_chat_id')->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->timestampTz('telegram_linked_at')->nullable();
            $table->string('telegram_link_code')->nullable()->unique();
            $table->timestampTz('telegram_link_code_expires_at')->nullable();
            $table->jsonb('telegram_notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'telegram_chat_id',
                'telegram_username',
                'telegram_linked_at',
                'telegram_link_code',
                'telegram_link_code_expires_at',
                'telegram_notification_prefs',
            ]);
        });
    }
};

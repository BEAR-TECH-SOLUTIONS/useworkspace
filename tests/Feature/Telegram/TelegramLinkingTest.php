<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Carbon;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * #213B — Telegram account linking: pairing-code issue, bot webhook
 * binding, unlink, and per-type preferences.
 */
class TelegramLinkingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_username' => 'useworkbot',
            'services.telegram.webhook_secret' => 'hook-secret',
            // No bot_token: outbound sends no-op, so the confirmation
            // message in the webhook handler doesn't hit the network.
            'services.telegram.bot_token' => null,
        ]);
    }

    public function test_link_issues_deep_link_and_pairing_code(): void
    {
        $user = UserFactory::create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/me/telegram/link')
            ->assertOk()
            ->assertJsonStructure(['deep_link', 'code', 'expires_at']);

        $code = $response->json('code');
        $this->assertSame("https://t.me/useworkbot?start={$code}", $response->json('deep_link'));
        $this->assertSame($code, $user->refresh()->telegram_link_code);
    }

    public function test_webhook_binds_chat_from_start_command(): void
    {
        $user = UserFactory::create();
        $code = $this->actingAs($user)->postJson('/api/v1/me/telegram/link')->json('code');

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => 1,
                'message' => [
                    'message_id' => 10,
                    'from' => ['id' => 555, 'username' => 'janedoe'],
                    'chat' => ['id' => 555, 'type' => 'private'],
                    'text' => "/start {$code}",
                ],
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $user->refresh();
        $this->assertSame('555', $user->telegram_chat_id);
        $this->assertSame('janedoe', $user->telegram_username);
        $this->assertTrue($user->telegramLinked());
        // Code is consumed on bind.
        $this->assertNull($user->telegram_link_code);
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
            ->postJson('/api/v1/telegram/webhook', ['message' => ['chat' => ['id' => 1], 'text' => '/start x']])
            ->assertForbidden();
    }

    public function test_webhook_ignores_expired_code(): void
    {
        $user = UserFactory::create();
        $user->forceFill([
            'telegram_link_code' => 'stalecode',
            'telegram_link_code_expires_at' => Carbon::now()->subMinute(),
        ])->save();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])
            ->postJson('/api/v1/telegram/webhook', [
                'message' => ['chat' => ['id' => 999], 'text' => '/start stalecode'],
            ])
            ->assertOk();

        $this->assertFalse($user->refresh()->telegramLinked());
    }

    public function test_show_reports_state(): void
    {
        $user = UserFactory::create();
        $user->forceFill([
            'telegram_chat_id' => '42',
            'telegram_username' => 'bob',
            'telegram_linked_at' => Carbon::now(),
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/me/telegram')
            ->assertOk()
            ->assertJson(['linked' => true, 'username' => 'bob', 'enabled_types' => null]);
    }

    public function test_destroy_unlinks(): void
    {
        $user = UserFactory::create();
        $user->forceFill([
            'telegram_chat_id' => '42',
            'telegram_username' => 'bob',
            'telegram_linked_at' => Carbon::now(),
        ])->save();

        $this->actingAs($user)
            ->deleteJson('/api/v1/me/telegram')
            ->assertNoContent();

        $this->assertFalse($user->refresh()->telegramLinked());
    }

    public function test_update_preferences_sets_allowlist(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/me/telegram/preferences', [
                'enabled_types' => ['task_assigned', 'expense_overdue'],
            ])
            ->assertOk()
            ->assertJson(['enabled_types' => ['task_assigned', 'expense_overdue']]);

        $this->assertSame(['task_assigned', 'expense_overdue'], $user->refresh()->telegram_notification_prefs);
    }

    public function test_update_preferences_rejects_unknown_type(): void
    {
        $user = UserFactory::create();

        $this->actingAs($user)
            ->putJson('/api/v1/me/telegram/preferences', [
                'enabled_types' => ['not_a_real_type'],
            ])
            ->assertStatus(422);
    }

    public function test_update_preferences_null_means_all(): void
    {
        $user = UserFactory::create();
        $user->forceFill(['telegram_notification_prefs' => ['task_assigned']])->save();

        $this->actingAs($user)
            ->putJson('/api/v1/me/telegram/preferences', ['enabled_types' => null])
            ->assertOk()
            ->assertJson(['enabled_types' => null]);

        $this->assertNull($user->refresh()->telegram_notification_prefs);
    }
}

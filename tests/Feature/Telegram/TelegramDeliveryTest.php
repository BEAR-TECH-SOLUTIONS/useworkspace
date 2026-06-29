<?php

namespace Tests\Feature\Telegram;

use App\Enums\NotificationType;
use App\Jobs\SendTelegramNotification;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Telegram\TelegramService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\UserFactory;
use Tests\TestCase;

/**
 * #213B — outbound delivery: a created notification enqueues a Telegram
 * send for a linked + opted-in recipient, and the transport is inert
 * without a bot token.
 */
class TelegramDeliveryTest extends TestCase
{
    public function test_created_notification_enqueues_send_for_linked_user(): void
    {
        Queue::fake();
        $user = $this->linkedUser();

        $notification = app(NotificationService::class)->create(
            userId: $user->id,
            type: NotificationType::TaskAssigned,
            title: 'You were assigned a task',
        );

        Queue::assertPushed(
            SendTelegramNotification::class,
            fn (SendTelegramNotification $job): bool => $job->notificationId === $notification->id,
        );
    }

    public function test_no_send_for_unlinked_user(): void
    {
        Queue::fake();
        $user = UserFactory::create();

        app(NotificationService::class)->create(
            userId: $user->id,
            type: NotificationType::TaskAssigned,
            title: 'You were assigned a task',
        );

        Queue::assertNotPushed(SendTelegramNotification::class);
    }

    public function test_no_send_when_type_not_in_allowlist(): void
    {
        Queue::fake();
        $user = $this->linkedUser(['expense_overdue']);

        app(NotificationService::class)->create(
            userId: $user->id,
            type: NotificationType::TaskAssigned,
            title: 'Different type',
        );

        Queue::assertNotPushed(SendTelegramNotification::class);
    }

    public function test_job_sends_message_via_telegram_api(): void
    {
        config([
            'services.telegram.bot_token' => 'BOTTOKEN',
            'services.telegram.api_url' => 'https://api.telegram.org',
        ]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $user = $this->linkedUser();
        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => NotificationType::TaskAssigned->value,
            'title' => 'Hello',
            'body' => 'World',
            'is_read' => false,
            'created_at' => Carbon::now(),
        ]);

        (new SendTelegramNotification($notification->id))->handle(app(TelegramService::class));

        Http::assertSent(function ($request) use ($user): bool {
            return str_contains($request->url(), '/botBOTTOKEN/sendMessage')
                && $request['chat_id'] === $user->telegram_chat_id
                && $request['text'] === "Hello\nWorld";
        });
    }

    public function test_send_is_inert_without_a_bot_token(): void
    {
        config(['services.telegram.bot_token' => null]);
        Http::fake();

        $this->assertFalse(app(TelegramService::class)->send('123', 'hi'));
        Http::assertNothingSent();
    }

    /**
     * @param  array<int, string>|null  $prefs
     */
    private function linkedUser(?array $prefs = null): User
    {
        $user = UserFactory::create();
        $user->forceFill([
            'telegram_chat_id' => '777',
            'telegram_username' => 'tester',
            'telegram_linked_at' => Carbon::now(),
            'telegram_notification_prefs' => $prefs,
        ])->save();

        return $user;
    }
}

<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirror a created notification to the recipient's linked Telegram chat
 * (#213B). Queued and retried; a failure here never affects the in-app
 * notification, which was already written before this job was dispatched.
 *
 * Linked-state and per-type preference are re-checked at run time so a
 * user who unlinked (or disabled the type) between enqueue and execution
 * isn't messaged.
 */
class SendTelegramNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $notificationId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(TelegramService $telegram): void
    {
        $notification = Notification::find($this->notificationId);
        if ($notification === null) {
            return;
        }

        $type = $notification->type instanceof NotificationType
            ? $notification->type->value
            : (string) $notification->type;

        $user = User::find($notification->user_id);
        if ($user === null || ! $user->telegramWantsType($type)) {
            return;
        }

        $telegram->send($user->telegram_chat_id, $telegram->formatNotification($notification));
    }
}

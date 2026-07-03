<?php

namespace App\Jobs;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Services\Telegram\TelegramService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Mirror a created notification to the recipient's linked Telegram chat
 * (#213B).
 *
 * Dispatched via {@see Dispatchable::dispatchAfterResponse()}
 * so it runs in-process right after the response is flushed — no queue
 * worker required, and it never adds latency to (or fails) the request /
 * command that created the notification. Delivery is best-effort: a
 * transport error is reported and swallowed here so it stays non-fatal.
 *
 * Linked-state and per-type preference are re-checked at run time in case
 * the user unlinked (or disabled the type) since the notification row was
 * written.
 */
class SendTelegramNotification
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $notificationId) {}

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

        try {
            $telegram->send($user->telegram_chat_id, $telegram->formatNotification($notification));
        } catch (Throwable $e) {
            // Non-fatal: the in-app notification already exists. Log and move on.
            report($e);
        }
    }
}

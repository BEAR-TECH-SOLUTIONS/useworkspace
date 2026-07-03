<?php

namespace App\Services\Telegram;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Telegram delivery + account-linking (#213B).
 *
 * Linking is a pairing-code handshake: the API hands the user a
 * `t.me/<bot>?start=<code>` deep link, and the bot's `/start <code>`
 * webhook binds the resulting chat_id to the user.
 *
 * Outbound delivery is intentionally inert when no bot token is
 * configured — {@see send()} logs and returns false rather than throwing
 * — so the whole feature can ship before a bot is provisioned.
 */
class TelegramService
{
    private const CODE_TTL_MINUTES = 15;

    public function __construct(private readonly HttpFactory $http) {}

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Issue a fresh one-time pairing code for $user and return it with
     * the matching deep link. Replaces any previous unused code.
     *
     * @return array{code: string, deep_link: string, expires_at: Carbon}
     */
    public function issueLinkCode(User $user): array
    {
        $code = Str::lower(Str::random(32));
        $expiresAt = Carbon::now()->addMinutes(self::CODE_TTL_MINUTES);

        $user->forceFill([
            'telegram_link_code' => $code,
            'telegram_link_code_expires_at' => $expiresAt,
        ])->save();

        return [
            'code' => $code,
            'deep_link' => $this->deepLink($code),
            'expires_at' => $expiresAt,
        ];
    }

    public function deepLink(string $code): string
    {
        $bot = (string) config('services.telegram.bot_username');

        return 'https://t.me/'.$bot.'?start='.$code;
    }

    /**
     * Bind a Telegram chat to the user that owns a still-valid pairing
     * code. Returns the bound user, or null if the code is unknown or
     * expired.
     */
    public function bindFromStartCommand(string $code, string $chatId, ?string $username): ?User
    {
        $user = User::query()
            ->where('telegram_link_code', $code)
            ->where('telegram_link_code_expires_at', '>', Carbon::now())
            ->first();

        if ($user === null) {
            return null;
        }

        $user->forceFill([
            'telegram_chat_id' => $chatId,
            'telegram_username' => $username,
            'telegram_linked_at' => Carbon::now(),
            'telegram_link_code' => null,
            'telegram_link_code_expires_at' => null,
        ])->save();

        return $user;
    }

    public function unlink(User $user): void
    {
        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_linked_at' => null,
            'telegram_link_code' => null,
            'telegram_link_code_expires_at' => null,
        ])->save();
    }

    public function formatNotification(Notification $notification): string
    {
        $text = (string) $notification->title;

        if (! empty($notification->body)) {
            $text .= "\n".$notification->body;
        }

        return $text;
    }

    /**
     * Send a message to a chat. No-ops (logs + returns false) when no bot
     * token is configured. Throws on a transport failure so the queued
     * job can retry; the caller treats that as non-fatal.
     */
    public function send(string $chatId, string $text): bool
    {
        if (! $this->isConfigured()) {
            logger()->info('Telegram send skipped — no bot token configured.', [
                'chat_id' => $chatId,
            ]);

            return false;
        }

        $url = rtrim((string) config('services.telegram.api_url'), '/')
            .'/bot'.$this->token().'/sendMessage';

        return $this->http
            ->connectTimeout(5)
            ->timeout(10)
            ->asForm()
            ->post($url, [
                'chat_id' => $chatId,
                'text' => $text,
            ])->throw()->successful();
    }

    private function token(): string
    {
        return (string) config('services.telegram.bot_token');
    }
}

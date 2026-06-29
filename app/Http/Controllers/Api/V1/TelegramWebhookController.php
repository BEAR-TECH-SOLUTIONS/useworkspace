<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public bot webhook (#213B). Telegram POSTs every update here; we only
 * act on the `/start <code>` deep-link command, which binds the sending
 * chat to the user that owns the pairing code.
 *
 * Authenticity is established by the secret token Telegram echoes in the
 * X-Telegram-Bot-Api-Secret-Token header (configured when the webhook is
 * registered). With no secret configured the endpoint refuses every
 * request — the feature stays dark until a bot is provisioned.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 403);
        }

        $message = (array) $request->input('message', []);
        $text = trim((string) ($message['text'] ?? ''));
        $chatId = $message['chat']['id'] ?? null;

        // Only the pairing command is actionable; acknowledge anything else
        // with 200 so Telegram doesn't retry.
        if ($chatId === null || ! str_starts_with($text, '/start')) {
            return response()->json(['ok' => true]);
        }

        $parts = preg_split('/\s+/', $text, 2);
        $code = isset($parts[1]) ? trim($parts[1]) : '';
        if ($code === '') {
            return response()->json(['ok' => true]);
        }

        $username = $message['from']['username']
            ?? ($message['chat']['username'] ?? null);

        $user = $this->telegram->bindFromStartCommand($code, (string) $chatId, $username);

        if ($user !== null) {
            // Best-effort confirmation back to the chat; non-fatal if it fails.
            $this->telegram->send((string) $chatId, 'Your Telegram account is now linked to usework.space.');
        }

        return response()->json(['ok' => true]);
    }
}

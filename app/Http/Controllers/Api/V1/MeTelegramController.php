<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\UpdateTelegramPreferencesRequest;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telegram account linking + per-type delivery preferences for the
 * authenticated user (#213B). Drives the desktop "Connect Telegram"
 * panel in Settings → Notifications.
 */
class MeTelegramController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /**
     * Issue a one-time pairing code + deep link. The user opens the link,
     * Telegram sends `/start <code>` to the bot, and the webhook binds the
     * chat to this account.
     */
    public function link(Request $request): JsonResponse
    {
        $issued = $this->telegram->issueLinkCode($request->user());

        return response()->json([
            'deep_link' => $issued['deep_link'],
            'code' => $issued['code'],
            'expires_at' => $issued['expires_at']->toIso8601String(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->telegram->unlink($request->user());

        return response()->json(status: 204);
    }

    public function updatePreferences(UpdateTelegramPreferencesRequest $request): JsonResponse
    {
        // null/omitted → "all types"; an array → explicit allow-list.
        $types = $request->has('enabled_types') ? $request->input('enabled_types') : null;

        $request->user()->forceFill([
            'telegram_notification_prefs' => $types,
        ])->save();

        return response()->json($this->state($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        $user = $request->user();

        return [
            'linked' => $user->telegramLinked(),
            'username' => $user->telegram_username,
            // null means every type is mirrored (the default).
            'enabled_types' => $user->telegram_notification_prefs,
        ];
    }
}

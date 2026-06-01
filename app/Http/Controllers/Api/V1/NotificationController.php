<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global per-user notifications inbox. Every endpoint is implicitly
 * scoped to the authenticated user — there is no user id in the URL,
 * so a stolen URL cannot enumerate other people's inboxes.
 */
class NotificationController extends Controller
{
    /**
     * Newest-first list with cursor pagination. `unread_count` always
     * reflects total unread across the whole inbox, not just the page.
     */
    public function index(ListNotificationsRequest $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 30);
        $cursor = $request->filled('cursor') ? (int) $request->input('cursor') : null;
        $unreadOnly = $request->boolean('unread_only');

        $query = Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id');

        if ($unreadOnly) {
            $query->where('is_read', false);
        }

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $hasMore ? $rows->slice(0, $limit) : $rows;
        $nextCursor = $hasMore ? (int) $page->last()->id : null;

        $unreadCount = Notification::query()
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'data' => NotificationResource::collection($page->values()),
            'next_cursor' => $nextCursor,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Ownership gate — the authenticated user may only mark their
        // own notifications. Returning 404 (rather than 403) to avoid
        // leaking whether a notification with this id exists.
        abort_if((int) $notification->user_id !== (int) $request->user()->id, 404);

        if (! $notification->is_read) {
            $notification->forceFill(['is_read' => true])->save();
        }

        return response()->json([
            'notification' => new NotificationResource($notification->refresh()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'updated_count' => $updated,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    public function destroy(Request $request, Notification $notification): JsonResponse
    {
        // Same 404-over-403 approach as markRead so an id fishing
        // attempt can't distinguish "exists but not yours" from
        // "doesn't exist".
        abort_if((int) $notification->user_id !== (int) $request->user()->id, 404);

        $notification->delete();

        return response()->json(status: 204);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        // `read_only=true` lets a client clear the "already seen"
        // queue without losing anything unread. Default stays
        // destructive (delete everything) so the nuke-the-inbox
        // button is a single call.
        $readOnly = $request->boolean('read_only');

        $query = Notification::query()->where('user_id', $request->user()->id);

        if ($readOnly) {
            $query->where('is_read', true);
        }

        $deleted = $query->delete();

        return response()->json([
            'deleted_count' => $deleted,
        ]);
    }
}

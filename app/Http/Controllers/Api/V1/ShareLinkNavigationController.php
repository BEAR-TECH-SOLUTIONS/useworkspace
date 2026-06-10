<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Expenses\Expense;
use App\Models\Project\Project;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use App\Models\Vault\ShareLink;
use App\Services\Permissions\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dedicated navigation-only lookup the desktop client hits after the
 * `usework://s/{tokenHash}` deep-link opens the app. The recipient may
 * or may not be the share's owner — anyone authenticated can resolve
 * the hash to navigation metadata (board_id / vault_id / bucket_id +
 * project_id + workspace_id) so the client knows which in-app page to
 * open.
 *
 * Deliberately distinct from:
 *   - {@see ShareLinkController::show} — owner-only, by id, returns the
 *     full record incl. snapshot payload.
 *   - {@see PublicShareLinkController::show} — anonymous, by token hash,
 *     returns the encrypted blob AND counts as a recipient view.
 *
 * This endpoint:
 *   - is authenticated (any Sanctum bearer; NOT owner-gated);
 *   - does not return the snapshot, encrypted blob, password hash, or
 *     auth proof material;
 *   - does NOT increment view_count or insert a share_views row — it's
 *     a navigation hop for an authenticated user, not a recipient view.
 *
 * `has_access` is computed against the caller's own grants on the
 * underlying resource, NOT against the share's permissions. The
 * client uses it to decide between in-app navigation and falling
 * back to the public web viewer.
 */
class ShareLinkNavigationController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    /**
     * GET /api/v1/share-links/by-hash/{tokenHash}
     */
    public function showByHash(Request $request, string $tokenHash): JsonResponse
    {
        $link = ShareLink::query()->where('token_hash', $tokenHash)->first();
        if ($link === null) {
            return response()->json(['code' => 'share_not_found'], 404);
        }

        // Status flags surface as 200 fields rather than 404s so the
        // client can render type-correct copy ("this share has been
        // revoked" vs "this share has expired") without an extra
        // round-trip — see spec table.
        $revoked = $link->revoked_at !== null;
        $expired = $link->expires_at->isPast()
            || ($link->max_views !== null && (int) $link->view_count >= (int) $link->max_views);

        // Workspace id always comes from the share's project. project_id
        // is denormalised onto share_links with ON DELETE CASCADE, so a
        // live share row always points at a live project row.
        $workspaceId = (int) Project::query()
            ->whereKey($link->project_id)
            ->value('organisation_id');

        // Parent resource lookup — used both for has_access and for
        // type-specific navigation ids (board for task, vault for
        // credential, bucket for expense). The morphTo can return null
        // if the parent row was deleted out from under us (no FK
        // cascade on the polymorphic side). When that happens we mark
        // the share revoked + has_access:false rather than 500 — the
        // share row exists but its target doesn't, indistinguishable
        // from a soft-revoked share from the recipient's POV.
        $resource = $this->resolveResource($link);
        $extra = $this->extraIdsFor($link, $resource);

        $hasAccess = false;
        if ($resource === null) {
            $revoked = true;
        } else {
            $hasAccess = $this->perms->can($request->user(), 'view', $resource);
        }

        return response()->json([
            'share' => [
                'resource_type' => (string) $link->resource_type,
                'resource_id' => (int) $link->resource_id,
                'project_id' => (int) $link->project_id,
                'workspace_id' => $workspaceId,
                ...$extra,
                'has_access' => $hasAccess,
                'revoked' => $revoked,
                'expired' => $expired,
                // share_links has no dedicated `name` column — the
                // resource name lives inside the frozen snapshot
                // payload as `name`. Surfaced for telemetry / a "you
                // just opened the share named X" toast on the client;
                // null when the snapshot doesn't carry a name field
                // (e.g. older shares before the snapshot got the
                // canonical resource name added).
                'name' => is_array($link->snapshot_payload)
                    ? ($link->snapshot_payload['name'] ?? null)
                    : null,
                'expires_at' => $link->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve the share's polymorphic parent via the morph map. Returns
     * null if the row was deleted out from under us.
     */
    private function resolveResource(ShareLink $link): ?Model
    {
        return $link->resource()->withoutGlobalScopes()->first();
    }

    /**
     * Type-specific denormalised ids the client uses to navigate to the
     * containing page. Only fields the spec asks for — board_id for
     * tasks, vault_id for credentials, bucket_id for expenses.
     *
     * @return array<string, int|null>
     */
    private function extraIdsFor(ShareLink $link, ?Model $resource): array
    {
        return match ((string) $link->resource_type) {
            'task' => ['board_id' => $this->boardIdForTask($resource)],
            'credential' => ['vault_id' => $resource instanceof Credential ? (int) $resource->vault_id : null],
            'expense' => ['bucket_id' => $resource instanceof Expense ? (int) $resource->bucket_id : null],
            default => [],
        };
    }

    /**
     * task_items.board_id doesn't exist as a direct column — boards own
     * columns, columns own items. One join keeps this O(1) regardless
     * of board size.
     */
    private function boardIdForTask(?Model $task): ?int
    {
        if (! $task instanceof TaskItem) {
            return null;
        }

        $boardId = DB::table('task_columns')
            ->where('id', $task->column_id)
            ->value('board_id');

        return $boardId === null ? null : (int) $boardId;
    }
}

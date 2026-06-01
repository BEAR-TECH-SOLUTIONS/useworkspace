<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sharing\StoreShareLinkRequest;
use App\Http\Resources\Sharing\ShareLinkSummaryResource;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\ShareLink;
use App\Services\Activity\ActivityService;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\AuditLogger;
use App\Services\Permissions\PermissionService;
use App\Services\Sharing\BoardStatsBuilder;
use App\Services\Sharing\ShareSnapshotBuilder;
use App\Services\Sharing\SnapshotTooLargeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Universal Share Links — owner-side endpoints. Public unlock lives
 * in {@see PublicShareLinkController}.
 *
 * No business logic in here beyond validation, resource resolution,
 * authorisation, and writing the row + audit/activity in the same
 * transaction. Snapshot serialisation is delegated to ShareSnapshotBuilder.
 */
class ShareLinkController extends Controller
{
    public const MAX_ACTIVE_PER_CREDENTIAL = 5;

    public const MAX_ACTIVE_TOTAL_PER_USER = 50;

    public const MAX_LIFETIME_DAYS_CREDENTIAL = 30;

    public const MAX_LIFETIME_DAYS_OTHER = 365;

    public const DEFAULT_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly ShareSnapshotBuilder $snapshots,
        private readonly ActivityService $activity,
        private readonly AuditLogger $audit,
        private readonly BoardStatsBuilder $stats,
        private readonly PermissionService $perms,
    ) {}

    public function store(StoreShareLinkRequest $request): JsonResponse
    {
        $type = $request->string('resource_type')->toString();
        $resource = $this->resolveResource($type, (int) $request->integer('resource_id'));

        // TaskItem doesn't have its own policy — gate it via the parent
        // TaskBoard's `share` ability. (Plan §3.) Same pattern would
        // apply to any future leaf-resource that shares its parent's
        // permission grants.
        $this->authorize('share', $resource instanceof TaskItem
            ? $resource->column?->board ?? $resource
            : $resource);

        $expiresAt = $this->resolveExpiresAt($request, $type);
        if ($expiresAt instanceof JsonResponse) {
            return $expiresAt;
        }

        $maxViews = $this->resolveMaxViews($request, $type);

        if ($cap = $this->capExceeded($request->user()->id, $type, $resource)) {
            return $cap;
        }

        $crypto = $type === 'credential' ? [
            'encrypted_blob' => $request->string('encrypted_blob')->toString(),
            'blob_iv' => $request->string('blob_iv')->toString(),
            'key_salt' => $request->string('key_salt')->toString(),
        ] : null;

        // Optional board-stats addendum. Requires a separate
        // `view_activity` ability gate so a user without activity-feed
        // access can't leak it into a share link. Validated to be
        // board-only at the request layer.
        $boardStats = null;
        if ($type === 'board' && $request->boolean('include_stats')) {
            if (! $this->perms->can($request->user(), Abilities::VIEW_ACTIVITY, $resource)) {
                return response()->json([
                    'message' => 'Stats require activity-feed access on this board.',
                    'code' => 'stats_requires_activity_access',
                ], 403);
            }
            $timezone = $this->stats->resolveTimezone($resource, $request->user());
            $boardStats = $this->stats->build($resource, $timezone);
        }

        try {
            $snapshot = $this->snapshots->build($type, $resource, $crypto, $boardStats);
        } catch (SnapshotTooLargeException $e) {
            return response()->json([
                'message' => 'Snapshot exceeds the per-resource size cap.',
                'errors' => ['snapshot_payload' => [
                    "Snapshot is {$e->size} bytes; the cap for {$e->resourceType} is {$e->cap}.",
                ]],
            ], 422);
        }

        $authProofHash = null;
        if ($type === 'credential') {
            $decoded = base64_decode($request->string('auth_proof')->toString(), true);
            $authProofHash = hash('sha256', (string) $decoded);
        }

        $passwordHash = $type !== 'credential'
            ? ($request->input('password_hash') !== '' ? $request->input('password_hash') : null)
            : null;

        $link = DB::transaction(function () use ($request, $resource, $type, $snapshot, $authProofHash, $passwordHash, $expiresAt, $maxViews) {
            $link = ShareLink::create([
                'resource_type' => $type,
                'resource_id' => $resource->getKey(),
                'project_id' => $resource->project_id,
                'created_by' => $request->user()->id,
                'name' => $request->input('name'),
                'token_hash' => $request->string('token_hash')->toString(),
                'snapshot_payload' => $snapshot,
                'expires_at' => $expiresAt,
                'max_views' => $maxViews,
                'password_hash' => $passwordHash,
                'auth_proof_hash' => $authProofHash,
            ]);

            $this->writeShareAudit($request->user(), $resource, $link, AuditAction::ShareLinkCreated, $type, [
                'share_link_id' => $link->id,
                'resource_type' => $type,
                'resource_id' => $resource->getKey(),
            ]);

            return $link;
        });

        return response()->json([
            'share_link' => new ShareLinkSummaryResource($link),
        ], 201);
    }

    public function mine(Request $request): AnonymousResourceCollection
    {
        $query = ShareLink::query()
            ->where('created_by', $request->user()->id)
            ->orderByDesc('created_at');

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->string('resource_type')->toString());
        }

        if ($request->boolean('active')) {
            $query->whereNull('revoked_at')->where('expires_at', '>', Carbon::now());
        }

        $perPage = (int) min(100, max(1, (int) $request->integer('per_page', 25)));

        return ShareLinkSummaryResource::collection($query->paginate($perPage));
    }

    public function show(ShareLink $shareLink): JsonResponse
    {
        $this->authorize('view', $shareLink);

        return response()->json([
            'share_link' => new ShareLinkSummaryResource($shareLink),
        ]);
    }

    public function destroy(Request $request, ShareLink $shareLink): JsonResponse
    {
        $this->authorize('delete', $shareLink);

        if ($shareLink->revoked_at === null) {
            DB::transaction(function () use ($shareLink, $request): void {
                $shareLink->update(['revoked_at' => Carbon::now()]);

                $resource = $shareLink->resource;
                if ($resource !== null) {
                    $this->writeShareAudit(
                        $request->user(),
                        $resource,
                        $shareLink,
                        AuditAction::ShareLinkRevoked,
                        $shareLink->resource_type,
                        ['share_link_id' => $shareLink->id, 'reason' => 'manual_revoke'],
                    );
                }
            });
        }

        return response()->json(status: 204);
    }

    public function views(ShareLink $shareLink): JsonResponse
    {
        $this->authorize('viewAudit', $shareLink);

        $views = $shareLink->views()->orderByDesc('viewed_at')->limit(100)->get();

        return response()->json([
            'views' => $views->map(fn ($view) => [
                'id' => (int) $view->id,
                'ip_hash' => $view->ip_hash,
                'user_agent' => $view->user_agent,
                'viewed_at' => $view->viewed_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * Audit L11: explicit allow-list of share-eligible morph keys.
     * Previously this method trusted the globally-registered morph
     * map, so any new model added to morphMap() (in an unrelated PR)
     * would silently become share-able. The list here mirrors the
     * `resource_type` enum on StoreShareLinkRequest and must move in
     * lockstep with it.
     */
    private const SHAREABLE_TYPES = ['board', 'task', 'credential', 'doc', 'expense'];

    private function resolveResource(string $type, int $id): Model
    {
        if (! in_array($type, self::SHAREABLE_TYPES, true)) {
            abort(422, "Unknown share-link resource_type [{$type}].");
        }

        $class = Relation::getMorphedModel($type);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            abort(422, "Unknown share-link resource_type [{$type}].");
        }

        /** @var Model $resource */
        $resource = $class::query()->findOrFail($id);

        return $resource;
    }

    private function resolveExpiresAt(StoreShareLinkRequest $request, string $type): Carbon|JsonResponse
    {
        $expiresAt = $request->filled('expires_at')
            ? Carbon::parse($request->string('expires_at')->toString())
            : Carbon::now()->addDays(self::DEFAULT_LIFETIME_DAYS);

        $maxDays = $type === 'credential'
            ? self::MAX_LIFETIME_DAYS_CREDENTIAL
            : self::MAX_LIFETIME_DAYS_OTHER;

        if ($expiresAt->gt(Carbon::now()->addDays($maxDays))) {
            return response()->json([
                'message' => "expires_at exceeds the {$maxDays}-day cap for {$type} shares.",
                'errors' => ['expires_at' => ["Maximum lifetime for {$type} is {$maxDays} days."]],
            ], 422);
        }

        return $expiresAt;
    }

    private function resolveMaxViews(StoreShareLinkRequest $request, string $type): ?int
    {
        if ($request->filled('max_views')) {
            return (int) $request->integer('max_views');
        }

        // Credentials default to single-fetch — recipient gets exactly
        // one shot, then the link auto-revokes (Universal Share Links
        // plan §"Mandatory rules for credential shares").
        return $type === 'credential' ? 1 : null;
    }

    private function capExceeded(int $userId, string $type, Model $resource): ?JsonResponse
    {
        $now = Carbon::now();

        // Per-credential cap (legacy rule, retained).
        if ($type === 'credential') {
            $perCred = ShareLink::query()
                ->where('resource_type', 'credential')
                ->where('resource_id', $resource->getKey())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->count();

            if ($perCred >= self::MAX_ACTIVE_PER_CREDENTIAL) {
                return response()->json([
                    'message' => 'A credential may have at most '.self::MAX_ACTIVE_PER_CREDENTIAL.' active share links at once.',
                    'code' => 'too_many_active_shares',
                ], 409);
            }
        }

        // Per-user-across-all-resources cap (new in v2).
        $totalForUser = ShareLink::query()
            ->where('created_by', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->count();

        if ($totalForUser >= self::MAX_ACTIVE_TOTAL_PER_USER) {
            return response()->json([
                'message' => 'You have reached the maximum of '.self::MAX_ACTIVE_TOTAL_PER_USER.' active share links.',
                'code' => 'too_many_active_shares',
            ], 409);
        }

        return null;
    }

    /**
     * Routes the audit row to the correct table:
     *  - task / board    → task_activities (board audit panel)
     *  - credential / doc / expense → audit_log (project audit feed)
     *
     * @param  array<string, mixed>  $meta
     */
    private function writeShareAudit(
        \App\Models\User $actor,
        Model $resource,
        ShareLink $link,
        AuditAction $auditAction,
        string $type,
        array $meta,
    ): void {
        if ($resource instanceof TaskBoard || $resource instanceof TaskItem) {
            $activityAction = $this->activityActionFor($type, $auditAction);
            if ($activityAction !== null) {
                $this->activity->recordShare($actor, $resource, $activityAction, $meta);

                return;
            }
        }

        $this->audit->record(
            actor: $actor,
            action: $auditAction,
            projectId: $resource->project_id,
            resourceType: null,
            resourceId: $link->id,
            metadata: $meta,
        );
    }

    private function activityActionFor(string $type, AuditAction $auditAction): ?ActivityAction
    {
        return match ([$type, $auditAction]) {
            ['board', AuditAction::ShareLinkCreated] => ActivityAction::BoardShared,
            ['board', AuditAction::ShareLinkRevoked] => ActivityAction::BoardShareRevoked,
            ['board', AuditAction::ShareLinkViewed] => ActivityAction::BoardShareViewed,
            ['task', AuditAction::ShareLinkCreated] => ActivityAction::TaskShared,
            ['task', AuditAction::ShareLinkRevoked] => ActivityAction::TaskShareRevoked,
            ['task', AuditAction::ShareLinkViewed] => ActivityAction::TaskShareViewed,
            default => null,
        };
    }
}

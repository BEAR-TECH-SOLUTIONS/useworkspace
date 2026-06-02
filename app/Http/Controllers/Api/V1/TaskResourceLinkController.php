<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\TaskResourceLinkKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskResourceLinkRequest;
use App\Http\Resources\Tasks\TaskResourceLinkResource;
use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskResourceLink;
use App\Models\Vault\Credential;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Task Resource Attachments spec §3. CRUD for the lightweight
 * task_resource_links table. Read-time per-entry permission filtering
 * is the critical invariant — the whole list never 403s; individual
 * entries just render as "locked" placeholders when the caller can't
 * see the underlying resource.
 */
class TaskResourceLinkController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Request $request, TaskItem $taskItem): AnonymousResourceCollection
    {
        $user = $request->user();
        // viewer on the board = view on the task's board resource
        $board = $taskItem->column->board;
        $this->perms->authorize($user, Abilities::VIEW, $board);

        $links = TaskResourceLink::query()
            ->where('task_item_id', $taskItem->id)
            ->orderByDesc('created_at')
            ->get();

        // Batch-load targets by type so we don't do N queries per row.
        $byType = $links->groupBy(fn ($l) => $l->resource_type instanceof TaskResourceLinkKind
            ? $l->resource_type->value
            : (string) $l->resource_type);

        $targets = [];
        foreach ($byType as $typeValue => $group) {
            $ids = $group->pluck('resource_id')->map(fn ($i) => (int) $i)->unique()->all();

            $targets[$typeValue] = match ($typeValue) {
                TaskResourceLinkKind::Credential->value => Credential::query()
                    ->with('vault')
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                TaskResourceLinkKind::ExpenseBucket->value => ExpenseBucket::query()
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                TaskResourceLinkKind::Expense->value => Expense::query()
                    ->with('bucket')
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                TaskResourceLinkKind::Doc->value => Doc::query()
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id'),
                default => collect(),
            };
        }

        $resources = $links->map(function (TaskResourceLink $link) use ($targets, $user): TaskResourceLinkResource {
            $typeValue = $link->resource_type instanceof TaskResourceLinkKind
                ? $link->resource_type->value
                : (string) $link->resource_type;

            $target = $targets[$typeValue][$link->resource_id] ?? null;
            $hasAccess = $target !== null && $this->perms->can($user, Abilities::VIEW, $target);

            return new TaskResourceLinkResource($link, $hasAccess, $hasAccess ? $target : null);
        });

        return TaskResourceLinkResource::collection($resources);
    }

    public function store(StoreTaskResourceLinkRequest $request, TaskItem $taskItem): JsonResponse
    {
        $user = $request->user();
        $board = $taskItem->column->board;

        // Editor on the board is the attach floor.
        $this->perms->authorize($user, Abilities::UPDATE, $board);

        $type = TaskResourceLinkKind::from($request->string('resource_type')->toString());
        $resourceId = (int) $request->input('resource_id');

        $target = $this->resolveTarget($type, $resourceId);

        // Two-failure-mode unification per spec §3.1: "missing" and
        // "can't see it" return the SAME body so we don't leak the
        // existence of resources the caller can't access.
        if ($target === null || ! $this->perms->can($user, Abilities::VIEW, $target)) {
            return response()->json([
                'message' => 'Resource does not exist or is not accessible.',
                'code' => 'cannot_view_resource',
            ], 403);
        }

        // Cross-project attachment is always rejected — links are
        // project-local. Walk each target type's project_id.
        $targetProjectId = (int) $this->projectIdOf($type, $target);
        if ($targetProjectId !== (int) $taskItem->project_id) {
            return response()->json([
                'message' => 'Resources can only be attached within the same project.',
                'code' => 'cross_project_attachment',
            ], 422);
        }

        $link = DB::transaction(function () use ($taskItem, $type, $resourceId, $user, $target): TaskResourceLink {
            $link = TaskResourceLink::firstOrCreate(
                [
                    'task_item_id' => $taskItem->id,
                    'resource_type' => $type->value,
                    'resource_id' => $resourceId,
                ],
                [
                    'created_by' => $user->id,
                ],
            );

            // Emit activity only on the creating call; second click
            // on the same resource is a no-op and shouldn't spam the
            // activity feed.
            if ($link->wasRecentlyCreated) {
                TaskActivity::create([
                    'project_id' => $taskItem->project_id,
                    'board_id' => $taskItem->column?->board_id,
                    'task_item_id' => $taskItem->id,
                    'user_id' => $user->id,
                    'action' => $type->activityAttached()->value,
                    'meta' => [
                        'resource_type' => $type->value,
                        'resource_id' => $resourceId,
                        'resource_name' => $this->nameOf($type, $target),
                    ],
                ]);
            }

            return $link;
        });

        return response()->json([
            'data' => (new TaskResourceLinkResource($link, true, $target))->toArray($request),
        ], 201);
    }

    public function destroy(Request $request, TaskItem $taskItem, TaskResourceLink $link): JsonResponse
    {
        abort_unless((int) $link->task_item_id === (int) $taskItem->id, 404);

        $user = $request->user();
        $board = $taskItem->column->board;
        $this->perms->authorize($user, Abilities::UPDATE, $board);

        $type = $link->resource_type instanceof TaskResourceLinkKind
            ? $link->resource_type
            : TaskResourceLinkKind::from((string) $link->resource_type);

        $target = $this->resolveTarget($type, (int) $link->resource_id);

        DB::transaction(function () use ($taskItem, $link, $user, $type, $target): void {
            TaskActivity::create([
                'project_id' => $taskItem->project_id,
                'board_id' => $taskItem->column?->board_id,
                'task_item_id' => $taskItem->id,
                'user_id' => $user->id,
                'action' => $type->activityDetached()->value,
                'meta' => [
                    'resource_type' => $type->value,
                    'resource_id' => (int) $link->resource_id,
                    // Name snapshot survives even if the row disappears
                    // later — spec §6 says history shouldn't cascade renames.
                    'resource_name' => $target !== null ? $this->nameOf($type, $target) : null,
                ],
            ]);

            $link->delete();
        });

        return response()->json(status: 204);
    }

    private function resolveTarget(TaskResourceLinkKind $type, int $id): ?Model
    {
        return match ($type) {
            TaskResourceLinkKind::Credential => Credential::query()->with('vault')->whereKey($id)->first(),
            TaskResourceLinkKind::ExpenseBucket => ExpenseBucket::query()->whereKey($id)->first(),
            TaskResourceLinkKind::Expense => Expense::query()->with('bucket')->whereKey($id)->first(),
            TaskResourceLinkKind::Doc => Doc::query()->whereKey($id)->first(),
        };
    }

    private function projectIdOf(TaskResourceLinkKind $type, Model $target): int
    {
        return match ($type) {
            TaskResourceLinkKind::Credential,
            TaskResourceLinkKind::ExpenseBucket,
            TaskResourceLinkKind::Expense,
            TaskResourceLinkKind::Doc => (int) $target->project_id,
        };
    }

    private function nameOf(TaskResourceLinkKind $type, Model $target): ?string
    {
        return match ($type) {
            TaskResourceLinkKind::Credential,
            TaskResourceLinkKind::ExpenseBucket,
            TaskResourceLinkKind::Expense => $target->name ?? null,
            // Docs use `title`, not `name` — activity rows keep the
            // doc title so history survives later renames (spec §6).
            TaskResourceLinkKind::Doc => $target->title ?? null,
        };
    }
}

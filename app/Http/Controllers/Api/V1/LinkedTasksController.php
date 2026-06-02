<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskResourceLinkKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tasks\TaskItemResource;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskResourceLink;
use App\Models\Vault\Credential;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reverse-lookup endpoints — "what tasks reference this resource?".
 * All three gated by the caller's view access to the source resource
 * (spec §7 authz matrix).
 */
class LinkedTasksController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function forCredential(Request $request, Credential $credential): AnonymousResourceCollection
    {
        return $this->tasksFor($request, TaskResourceLinkKind::Credential, $credential, (int) $credential->id);
    }

    public function forExpenseBucket(Request $request, ExpenseBucket $expenseBucket): AnonymousResourceCollection
    {
        return $this->tasksFor($request, TaskResourceLinkKind::ExpenseBucket, $expenseBucket, (int) $expenseBucket->id);
    }

    public function forExpense(Request $request, Expense $expense): AnonymousResourceCollection
    {
        return $this->tasksFor($request, TaskResourceLinkKind::Expense, $expense, (int) $expense->id);
    }

    private function tasksFor(Request $request, TaskResourceLinkKind $type, $source, int $resourceId): AnonymousResourceCollection
    {
        $this->perms->authorize($request->user(), Abilities::VIEW, $source);

        $taskIds = TaskResourceLink::query()
            ->where('resource_type', $type->value)
            ->where('resource_id', $resourceId)
            ->pluck('task_item_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $tasks = $taskIds === []
            ? collect()
            : TaskItem::query()
                ->whereIn('id', $taskIds)
                ->orderByDesc('updated_at')
                ->get();

        // Hide tasks on boards the caller can't see — same principle
        // as the main list endpoint: don't surface identifiers for
        // things the user isn't allowed to read.
        $visible = $tasks->filter(function (TaskItem $task) use ($request): bool {
            $board = $task->column?->board;
            if ($board === null) {
                return false;
            }

            return $this->perms->can($request->user(), Abilities::VIEW, $board);
        })->values();

        return TaskItemResource::collection($visible);
    }
}

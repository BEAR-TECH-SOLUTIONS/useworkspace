<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ProjectSearchRequest;
use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/projects/{project}/search
 *
 * Project-wide search across tasks, credentials, boards, vaults,
 * expenses, and expense buckets. Results are permission-scoped: Pattern
 * B users only see results from resources they hold a direct grant on.
 */
class ProjectSearchController extends Controller
{
    private const VALID_TYPES = ['task', 'credential', 'board', 'vault', 'expense', 'expense_bucket', 'doc'];

    public function __construct(private readonly PermissionService $perms) {}

    public function __invoke(ProjectSearchRequest $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $q = $request->string('q')->toString();
        $limit = min(50, max(1, (int) $request->input('limit', 20)));
        $includeArchived = (bool) $request->input('include_archived', false);

        $types = self::VALID_TYPES;
        if ($typesParam = $request->query('types')) {
            $types = array_intersect(
                array_map('trim', explode(',', $typesParam)),
                self::VALID_TYPES,
            );
        }

        $pattern = '%'.addcslashes($q, '%_\\').'%';
        $prefixPattern = addcslashes($q, '%_\\').'%';

        $results = [];

        if (in_array('task', $types, true)) {
            $results['tasks'] = $this->searchTasks($user, $project, $pattern, $prefixPattern, $limit, $includeArchived);
        }

        if (in_array('credential', $types, true)) {
            $results['credentials'] = $this->searchCredentials($user, $project, $pattern, $prefixPattern, $limit, $includeArchived);
        }

        if (in_array('board', $types, true)) {
            $results['boards'] = $this->searchBoards($user, $project, $pattern, $prefixPattern, $limit);
        }

        if (in_array('vault', $types, true)) {
            $results['vaults'] = $this->searchVaults($user, $project, $pattern, $prefixPattern, $limit);
        }

        if (in_array('expense_bucket', $types, true)) {
            $results['expense_buckets'] = $this->searchExpenseBuckets($user, $project, $pattern, $prefixPattern, $limit);
        }

        if (in_array('expense', $types, true)) {
            $results['expenses'] = $this->searchExpenses($user, $project, $pattern, $prefixPattern, $limit);
        }

        if (in_array('doc', $types, true)) {
            $results['docs'] = $this->searchDocs($user, $project, $q, $prefixPattern, $limit, $includeArchived);
        }

        return response()->json([
            'query' => $q,
            'results' => $results,
        ]);
    }

    /**
     * Doc search: Postgres FTS against content_text + ILIKE on title.
     * Returns one entry per match with a 200-char content preview for
     * the results list. Pattern B users only see docs they hold a
     * direct grant on — visibleScope() narrows it.
     */
    private function searchDocs($user, Project $project, string $q, string $prefixPattern, int $limit, bool $includeArchived): array
    {
        $titlePattern = '%'.addcslashes($q, '%_\\').'%';

        $query = $this->perms
            ->visibleScope($user, ResourceType::Doc, $project)
            ->where(function ($qb) use ($titlePattern, $q): void {
                $qb->whereRaw('lower(title) like ?', [mb_strtolower($titlePattern)])
                    ->orWhereRaw(
                        "to_tsvector('english', coalesce(content_text, '')) @@ plainto_tsquery('english', ?)",
                        [$q],
                    );
            });

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        $rows = $query
            ->orderByRaw('CASE WHEN title ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn (Doc $d): array => [
            'type' => 'doc',
            'id' => (int) $d->id,
            'title' => $d->title,
            'preview' => $d->content_text !== null
                ? mb_substr((string) $d->content_text, 0, 200)
                : null,
            'project_id' => (int) $d->project_id,
        ])->all();
    }

    private function searchTasks($user, Project $project, string $pattern, string $prefixPattern, int $limit, bool $includeArchived): array
    {
        $visibleBoardIds = $this->perms
            ->visibleScope($user, ResourceType::Board, $project)
            ->pluck('id');

        $query = TaskItem::query()
            ->select('task_items.*')
            ->join('task_columns', 'task_columns.id', '=', 'task_items.column_id')
            ->join('task_boards', 'task_boards.id', '=', 'task_columns.board_id')
            ->where('task_items.project_id', $project->id)
            ->whereIn('task_columns.board_id', $visibleBoardIds)
            ->where(function ($q) use ($pattern): void {
                $q->where('task_items.title', 'ilike', $pattern)
                    ->orWhere('task_items.description', 'ilike', $pattern);
            });

        if (! $includeArchived) {
            $query->where('task_items.is_archived', false);
        }

        $rows = $query
            ->addSelect([
                'task_boards.id as _board_id',
                'task_boards.name as _board_name',
                'task_columns.id as _column_id',
                'task_columns.name as _column_name',
            ])
            ->orderByRaw('CASE WHEN task_items.title ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('task_items.updated_at')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'title' => $r->title,
            'board_id' => (int) $r->_board_id,
            'board_name' => $r->_board_name,
            'column_id' => (int) $r->_column_id,
            'column_name' => $r->_column_name,
            'is_completed' => (bool) $r->is_completed,
            'priority' => $r->priority?->value,
        ])->all();
    }

    private function searchCredentials($user, Project $project, string $pattern, string $prefixPattern, int $limit, bool $includeArchived): array
    {
        $visibleVaultIds = $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->pluck('id');

        $hasProjectLevelAccess = $this->perms->can($user, 'view', $project);

        $query = Credential::query()
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($visibleVaultIds, $hasProjectLevelAccess): void {
                $q->whereIn('vault_id', $visibleVaultIds);
                if ($hasProjectLevelAccess) {
                    $q->orWhereNull('vault_id');
                }
            })
            ->where(function ($q) use ($pattern): void {
                $q->where('name', 'ilike', $pattern)
                    ->orWhere('url', 'ilike', $pattern);
            });

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        $rows = $query
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        // Batch-load vault names.
        $vaultNames = [];
        $vaultIds = $rows->pluck('vault_id')->filter()->unique()->all();
        if ($vaultIds) {
            $vaultNames = DB::table('vaults')
                ->whereIn('id', $vaultIds)
                ->pluck('name', 'id')
                ->all();
        }

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'url' => $r->url,
            'type' => $r->type?->value,
            'vault_id' => $r->vault_id,
            'vault_name' => $r->vault_id ? ($vaultNames[$r->vault_id] ?? null) : null,
        ])->all();
    }

    private function searchBoards($user, Project $project, string $pattern, string $prefixPattern, int $limit): array
    {
        return $this->perms
            ->visibleScope($user, ResourceType::Board, $project)
            ->where(function ($q) use ($pattern): void {
                $q->where('name', 'ilike', $pattern)
                    ->orWhere('description', 'ilike', $pattern);
            })
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'description' => $r->description,
            ])->all();
    }

    private function searchVaults($user, Project $project, string $pattern, string $prefixPattern, int $limit): array
    {
        return $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->where('name', 'ilike', $pattern)
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
            ])->all();
    }

    private function searchExpenseBuckets($user, Project $project, string $pattern, string $prefixPattern, int $limit): array
    {
        return $this->perms
            ->visibleScope($user, ResourceType::Bucket, $project)
            ->where('name', 'ilike', $pattern)
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'currency' => $r->currency,
                'color' => $r->color,
            ])->all();
    }

    private function searchExpenses($user, Project $project, string $pattern, string $prefixPattern, int $limit): array
    {
        $visibleBucketIds = $this->perms
            ->visibleScope($user, ResourceType::Bucket, $project)
            ->pluck('id');

        $rows = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->where(function ($q) use ($pattern): void {
                $q->where('name', 'ilike', $pattern)
                    ->orWhere('description', 'ilike', $pattern)
                    ->orWhere('vendor', 'ilike', $pattern);
            })
            ->orderByRaw('CASE WHEN name ILIKE ? THEN 0 ELSE 1 END', [$prefixPattern])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $bucketNames = [];
        $bucketIds = $rows->pluck('bucket_id')->unique()->all();
        if ($bucketIds) {
            $bucketNames = DB::table('expense_buckets')
                ->whereIn('id', $bucketIds)
                ->pluck('name', 'id')
                ->all();
        }

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'name' => $r->name,
            'amount' => $r->amount,
            'currency' => $r->currency,
            'vendor' => $r->vendor,
            'bucket_id' => (int) $r->bucket_id,
            'bucket_name' => $bucketNames[$r->bucket_id] ?? null,
            'category' => $r->category?->value,
            'billing_cycle' => $r->billing_cycle?->value,
        ])->all();
    }
}

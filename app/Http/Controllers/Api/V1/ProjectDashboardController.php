<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillingCycle;
use App\Enums\ResourceType;
use App\Enums\WorkspaceInvitationStatus;
use App\Http\Controllers\Controller;
use App\Models\Expenses\Expense;
use App\Models\Permissions\ResourcePermission;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInvitationProjectGrant;
use App\Models\Project\Project;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/projects/{project}/dashboard
 *
 * Single-call dashboard aggregation. Returns stats, my tasks,
 * recent activity, upcoming expenses, team snapshot, and pending
 * invitation count — everything the dashboard needs in one request.
 */
class ProjectDashboardController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function __invoke(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $visibleBoardIds = $this->perms
            ->visibleScope($user, ResourceType::Board, $project)
            ->pluck('id');

        $visibleVaultIds = $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->pluck('id');

        $visibleBucketIds = $this->perms
            ->visibleScope($user, ResourceType::Bucket, $project)
            ->pluck('id');

        $activityLimit = min(50, max(1, (int) $request->input('activity_limit', 20)));
        $expenseDays = min(90, max(1, (int) $request->input('expense_days', 30)));

        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        // ── Visible column IDs (needed to scope tasks by board) ──
        $visibleColumnIds = DB::table('task_columns')
            ->whereIn('board_id', $visibleBoardIds)
            ->pluck('id');

        // ── My assigned task IDs ──
        $myTaskIds = DB::table('task_assignees')
            ->where('user_id', $user->id)
            ->pluck('task_item_id');

        // ── Stats ──
        $stats = $this->buildStats(
            $user, $project, $visibleColumnIds, $visibleVaultIds,
            $visibleBucketIds, $myTaskIds, $today, $weekStart, $weekEnd, $expenseDays,
        );

        // ── My tasks ──
        $myTasks = $this->buildMyTasks($user, $project, $visibleColumnIds, $myTaskIds, $today, $weekStart, $weekEnd);

        // ── Recent activity ──
        $recentActivity = $this->buildRecentActivity($project, $visibleBoardIds, $activityLimit);

        // ── Upcoming expenses ──
        $upcomingExpenses = $this->buildUpcomingExpenses($project, $visibleBucketIds, $expenseDays);

        // ── Team ──
        $team = $this->buildTeam($project, $today);

        // ── Pending invitations ──
        // Project-scope invitations are gone; workspace invitations
        // are the new unit. Count invites either explicitly assigned
        // to this user OR addressed to their email (invitee_id is
        // null when invited before an account existed).
        $myPendingInvitations = WorkspaceInvitation::query()
            ->where('status', WorkspaceInvitationStatus::Pending->value)
            ->where(function ($q) use ($user): void {
                $q->where('invitee_id', $user->id)
                    ->orWhereRaw('lower(invitee_email) = ?', [strtolower($user->email)]);
            })
            ->count();

        return response()->json([
            'stats' => $stats,
            'my_tasks' => $myTasks,
            'recent_activity' => $recentActivity,
            'upcoming_expenses' => $upcomingExpenses,
            'team' => $team,
            'my_pending_invitations' => $myPendingInvitations,
        ]);
    }

    private function buildStats(
        $user, Project $project, $visibleColumnIds, $visibleVaultIds,
        $visibleBucketIds, $myTaskIds, Carbon $today, Carbon $weekStart, Carbon $weekEnd, int $expenseDays,
    ): array {
        $baseTaskQuery = TaskItem::query()
            ->where('project_id', $project->id)
            ->whereIn('column_id', $visibleColumnIds)
            ->where('is_archived', false);

        $myTaskCount = (clone $baseTaskQuery)
            ->whereIn('id', $myTaskIds)
            ->where('is_completed', false)
            ->count();

        $completedThisWeek = (clone $baseTaskQuery)
            ->where('is_completed', true)
            ->whereBetween('completed_at', [$weekStart, $weekEnd])
            ->count();

        $overdueCount = (clone $baseTaskQuery)
            ->whereIn('id', $myTaskIds)
            ->where('is_completed', false)
            ->where('due_date', '<', $today)
            ->count();

        $upcomingExpenseCount = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today, $today->copy()->addDays($expenseDays)])
            ->count();

        $totalTaskCount = (clone $baseTaskQuery)->count();

        $hasProjectLevelAccess = $this->perms->can($user, 'view', $project);
        $credQuery = Credential::query()
            ->where('project_id', $project->id)
            ->whereNull('deleted_at');

        if ($hasProjectLevelAccess) {
            // Can see all credentials including null-vault ones.
        } else {
            $credQuery->whereIn('vault_id', $visibleVaultIds);
        }
        $totalCredentialCount = $credQuery->count();

        [$monthlyBurn, $burnCurrency] = $this->computeMonthlyBurn($project, $visibleBucketIds);

        return [
            'my_task_count' => $myTaskCount,
            'completed_this_week' => $completedThisWeek,
            'overdue_count' => $overdueCount,
            'upcoming_expense_count' => $upcomingExpenseCount,
            'total_task_count' => $totalTaskCount,
            'total_credential_count' => $totalCredentialCount,
            'monthly_burn' => number_format($monthlyBurn, 2, '.', ''),
            'monthly_burn_currency' => $burnCurrency,
        ];
    }

    private function computeMonthlyBurn(Project $project, $visibleBucketIds): array
    {
        $expenses = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->where('billing_cycle', '!=', BillingCycle::OneTime->value)
            ->get();

        $sum = 0;
        foreach ($expenses as $e) {
            $amount = (float) $e->amount;
            $sum += match ($e->billing_cycle) {
                BillingCycle::Monthly => $amount,
                BillingCycle::Quarterly => round($amount / 3, 2),
                BillingCycle::Yearly => round($amount / 12, 2),
                default => $amount,
            };
        }

        // Most common currency.
        $currency = $expenses->countBy('currency')->sortDesc()->keys()->first() ?? 'USD';

        return [$sum, $currency];
    }

    private function buildMyTasks($user, Project $project, $visibleColumnIds, $myTaskIds, Carbon $today, Carbon $weekStart, Carbon $weekEnd): array
    {
        $base = TaskItem::query()
            ->select('task_items.*')
            ->join('task_columns', 'task_columns.id', '=', 'task_items.column_id')
            ->join('task_boards', 'task_boards.id', '=', 'task_columns.board_id')
            ->addSelect([
                'task_boards.id as _board_id',
                'task_boards.name as _board_name',
                'task_columns.id as _column_id',
                'task_columns.name as _column_name',
            ])
            ->where('task_items.project_id', $project->id)
            ->whereIn('task_items.column_id', $visibleColumnIds)
            ->whereIn('task_items.id', $myTaskIds)
            ->where('task_items.is_archived', false)
            ->where('task_items.is_completed', false)
            ->with(['assignees:id,name']);

        $overdue = (clone $base)
            ->where('task_items.due_date', '<', $today)
            ->orderBy('task_items.due_date')
            ->get();

        $dueToday = (clone $base)
            ->where('task_items.due_date', $today)
            ->orderBy('task_items.priority')
            ->get();

        $dueThisWeek = (clone $base)
            ->where('task_items.due_date', '>', $today)
            ->where('task_items.due_date', '<=', $weekEnd)
            ->orderBy('task_items.due_date')
            ->get();

        $fourteenDaysOut = $today->copy()->addDays(14);
        $upcoming = (clone $base)
            ->where('task_items.due_date', '>', $weekEnd)
            ->where('task_items.due_date', '<=', $fourteenDaysOut)
            ->orderBy('task_items.due_date')
            ->limit(10)
            ->get();

        $noDate = (clone $base)
            ->whereNull('task_items.due_date')
            ->orderByDesc('task_items.updated_at')
            ->limit(10)
            ->get();

        $format = fn (Collection $tasks) => $tasks->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->title,
            'priority' => $t->priority?->value,
            'due_date' => $t->due_date?->toDateString(),
            'is_completed' => (bool) $t->is_completed,
            'board_id' => (int) $t->_board_id,
            'board_name' => $t->_board_name,
            'column_id' => (int) $t->_column_id,
            'column_name' => $t->_column_name,
            'assignees' => $t->assignees->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
            ])->all(),
        ])->all();

        return [
            'overdue' => $format($overdue),
            'due_today' => $format($dueToday),
            'due_this_week' => $format($dueThisWeek),
            'upcoming' => $format($upcoming),
            'no_date' => $format($noDate),
        ];
    }

    private function buildRecentActivity(Project $project, $visibleBoardIds, int $limit): array
    {
        $rows = TaskActivity::query()
            ->with('user:id,name')
            ->where('project_id', $project->id)
            ->whereIn('board_id', $visibleBoardIds)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        // Batch-load task + board names.
        $taskIds = $rows->pluck('task_item_id')->filter()->unique()->all();
        $taskInfo = [];
        if ($taskIds) {
            $taskInfo = DB::table('task_items')
                ->join('task_columns', 'task_columns.id', '=', 'task_items.column_id')
                ->join('task_boards', 'task_boards.id', '=', 'task_columns.board_id')
                ->whereIn('task_items.id', $taskIds)
                ->select(['task_items.id', 'task_items.title', 'task_boards.id as board_id', 'task_boards.name as board_name'])
                ->get()
                ->keyBy('id')
                ->all();
        }

        return $rows->map(function ($r) use ($taskInfo) {
            $task = $taskInfo[$r->task_item_id] ?? null;

            return [
                'id' => $r->id,
                'action' => $r->action?->value ?? (string) $r->action,
                'field' => $r->field,
                'old_value' => $r->old_value,
                'new_value' => $r->new_value,
                'created_at' => $r->created_at?->toIso8601String(),
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                ] : null,
                'task' => $task ? [
                    'id' => (int) $task->id,
                    'title' => $task->title,
                    'board_id' => (int) $task->board_id,
                    'board_name' => $task->board_name,
                ] : null,
            ];
        })->all();
    }

    private function buildUpcomingExpenses(Project $project, $visibleBucketIds, int $days): array
    {
        $today = Carbon::today();

        $rows = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [$today, $today->copy()->addDays($days)])
            ->orderBy('next_due_date')
            ->limit(10)
            ->get();

        $bucketNames = [];
        $bucketIds = $rows->pluck('bucket_id')->unique()->all();
        if ($bucketIds) {
            $bucketNames = DB::table('expense_buckets')
                ->whereIn('id', $bucketIds)
                ->pluck('name', 'id')
                ->all();
        }

        return $rows->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'amount' => number_format((float) $e->amount, 2, '.', ''),
            'currency' => $e->currency,
            'next_due_date' => $e->next_due_date?->toDateString(),
            'vendor' => $e->vendor,
            'bucket_id' => (int) $e->bucket_id,
            'bucket_name' => $bucketNames[$e->bucket_id] ?? null,
        ])->all();
    }

    private function buildTeam(Project $project, Carbon $today): array
    {
        $memberCount = ResourcePermission::query()
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->count();

        // Count pending workspace invitations that stage a grant on
        // this specific project — that's "people who'll land here
        // once they accept".
        $pendingInvitationCount = WorkspaceInvitationProjectGrant::query()
            ->where('project_id', $project->id)
            ->whereHas('invitation', function ($q): void {
                $q->where('status', WorkspaceInvitationStatus::Pending->value);
            })
            ->count();

        $activeToday = TaskActivity::query()
            ->where('project_id', $project->id)
            ->where('created_at', '>=', $today)
            ->select('user_id')
            ->selectRaw('MAX(created_at) as last_action_at')
            ->groupBy('user_id')
            ->orderByDesc('last_action_at')
            ->limit(10)
            ->get();

        $userIds = $activeToday->pluck('user_id')->all();
        $names = [];
        if ($userIds) {
            $names = DB::table('users')
                ->whereIn('id', $userIds)
                ->pluck('name', 'id')
                ->all();
        }

        return [
            'member_count' => $memberCount,
            'pending_invitation_count' => $pendingInvitationCount,
            'active_today' => $activeToday->map(fn ($r) => [
                'id' => (int) $r->user_id,
                'name' => $names[$r->user_id] ?? null,
                'last_action_at' => $r->last_action_at,
            ])->all(),
        ];
    }
}

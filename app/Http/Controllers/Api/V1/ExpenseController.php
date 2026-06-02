<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\AnalyticsPeriod;
use App\Enums\BillingCycle;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentType;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Requests\Expenses\UpdateExpenseRequest;
use App\Http\Resources\Expenses\ExpenseResource;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Services\Activity\ActivityService;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly ActivityService $activity,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        // Pattern B users reach this endpoint too; visibleScope()
        // narrows the result to buckets they have direct access to.
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        $query = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds);

        if ($bucketId = $request->query('bucket_id')) {
            $query->where('bucket_id', (int) $bucketId);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        if ($cycle = $request->query('billing_cycle')) {
            $query->where('billing_cycle', $cycle);
        }

        if ($q = $request->query('q')) {
            $query->where(function ($sub) use ($q): void {
                $sub->where('name', 'ilike', '%'.$q.'%')
                    ->orWhere('vendor', 'ilike', '%'.$q.'%');
            });
        }

        if ($tag = $request->query('tag')) {
            $query->whereRaw('? = ANY (tags)', [$tag]);
        }

        // Period filter (same semantics as the summary endpoint).
        if ($periodParam = $request->query('period')) {
            $period = AnalyticsPeriod::from($periodParam);
            [$start, $end] = $period->dateRange();

            if ($period !== AnalyticsPeriod::AllTime) {
                $query->where(function ($sub) use ($start, $end): void {
                    $sub->where(function ($inner) use ($start, $end): void {
                        $inner->where('billing_cycle', BillingCycle::OneTime->value)
                            ->whereBetween('created_at', [$start, $end]);
                    })->orWhere(function ($inner) use ($start, $end): void {
                        $inner->where('billing_cycle', '!=', BillingCycle::OneTime->value)
                            ->where('created_at', '<=', $end)
                            ->where(function ($q2) use ($start): void {
                                $q2->whereNull('next_due_date')
                                    ->orWhere('next_due_date', '>=', $start);
                            });
                    });
                });
            }
        }

        // Amount range filters.
        if ($min = $request->query('min_amount')) {
            $query->where('amount', '>=', (float) $min);
        }
        if ($max = $request->query('max_amount')) {
            $query->where('amount', '<=', (float) $max);
        }

        // Sort.
        $sort = $request->query('sort', 'created_at');
        $query = match ($sort) {
            'amount_asc' => $query->orderBy('amount'),
            'amount_desc' => $query->orderByDesc('amount'),
            'due_date' => $query->orderByRaw('next_due_date IS NULL, next_due_date ASC'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };

        $expenses = $query
            ->limit(500)
            ->get();

        return ExpenseResource::collection($expenses);
    }

    public function upcoming(Request $request, Project $project): AnonymousResourceCollection
    {
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $days = min(180, max(1, (int) $request->query('days', 30)));

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        $expenses = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [Carbon::today(), Carbon::today()->addDays($days)])
            ->orderBy('next_due_date')
            ->get();

        return ExpenseResource::collection($expenses);
    }

    public function store(StoreExpenseRequest $request, Project $project): JsonResponse
    {
        /** @var ExpenseBucket $bucket */
        $bucket = ExpenseBucket::query()
            ->where('id', (int) $request->input('bucket_id'))
            ->where('project_id', $project->id)
            ->firstOrFail();

        // The only check that matters is "update on the target bucket"
        // — a Pattern B editor with a direct bucket grant qualifies
        // without needing any project-level row.
        $this->authorize('update', $bucket);

        $paymentType = $request->filled('payment_type')
            ? PaymentType::from($request->string('payment_type')->toString())
            : null;

        $expense = Expense::create([
            'project_id' => $project->id,
            'bucket_id' => $bucket->id,
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'category' => ExpenseCategory::from($request->string('category')->toString()),
            'amount' => $request->input('amount'),
            'currency' => strtoupper($request->string('currency')->toString()),
            'billing_cycle' => BillingCycle::from($request->string('billing_cycle')->toString()),
            'vendor' => $request->input('vendor'),
            'tags' => $request->input('tags', []),
            'payment_type' => $paymentType,
            'payment_method_other' => $paymentType === PaymentType::Other
                ? $request->input('payment_method_other')
                : null,
            'url' => $request->input('url'),
            'next_due_date' => $request->input('next_due_date'),
            'created_by' => $request->user()->id,
        ]);

        if ($paymentType !== null) {
            $this->activity->record(
                $request->user(),
                $expense,
                ActivityAction::ExpensePaymentTypeSet,
                meta: ['detail' => 'set to '.$paymentType->value],
            );
        }

        return response()->json([
            'expense' => new ExpenseResource($expense),
        ], 201);
    }

    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return response()->json([
            'expense' => new ExpenseResource($expense),
        ]);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $this->authorize('update', $expense);

        $payload = $request->only([
            'bucket_id',
            'name',
            'description',
            'category',
            'amount',
            'currency',
            'billing_cycle',
            'vendor',
            'tags',
            'payment_type',
            'payment_method_other',
            'url',
            'next_due_date',
        ]);

        if (isset($payload['currency'])) {
            $payload['currency'] = strtoupper($payload['currency']);
        }

        $previousType = $expense->payment_type;

        // PATCH semantics for the paired payment_type / _other fields:
        // when payment_type is being set to anything other than 'other',
        // null out payment_method_other so the DB CHECK constraint stays
        // happy. The validation invariant catches incoherent payloads
        // upstream; this just clears the trailing field.
        if (array_key_exists('payment_type', $payload) && $payload['payment_type'] !== PaymentType::Other->value) {
            $payload['payment_method_other'] = null;
        }

        $expense->fill($payload)->save();
        $expense->refresh();

        $newType = $expense->payment_type;
        if ($previousType !== $newType) {
            $detail = $previousType !== null && $newType !== null
                ? $previousType->value.' → '.$newType->value
                : ($newType !== null ? 'set to '.$newType->value : 'cleared from '.$previousType?->value);

            $this->activity->record(
                $request->user(),
                $expense,
                ActivityAction::ExpensePaymentTypeSet,
                meta: ['detail' => $detail],
            );
        }

        return response()->json([
            'expense' => new ExpenseResource($expense),
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', $expense);

        $expense->delete();

        return response()->json(status: 204);
    }
}

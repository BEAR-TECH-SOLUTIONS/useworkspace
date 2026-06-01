<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AnalyticsPeriod;
use App\Enums\BillingCycle;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Expenses\ExpensePayment;
use App\Models\Project\Project;
use App\Services\Fx\FxRateService;
use App\Services\Fx\FxUnavailableException;
use App\Services\Fx\FxUnsupportedCurrencyException;
use App\Services\Permissions\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseAnalyticsController extends Controller
{
    private const SCALE = 8;

    public function __construct(
        private readonly PermissionService $perms,
        private readonly FxRateService $fx,
    ) {}

    /**
     * GET /projects/{project}/expenses/summary
     */
    public function summary(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $request->validate([
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $displayCurrency = strtoupper((string) $request->query('currency'));

        $period = AnalyticsPeriod::from($request->query('period', 'month'));
        [$start, $end] = $period->dateRange();

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        $query = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds);

        if ($bucketId = $request->query('bucket_id')) {
            $query->where('bucket_id', (int) $bucketId);
        }

        if ($tag = $request->query('tag')) {
            $query->whereRaw('? = ANY (tags)', [$tag]);
        }

        $this->applyPeriodFilter($query, $period, $start, $end);

        $rows = $query->get();

        try {
            [$converted, $currencySource] = $this->convertRows($rows, $displayCurrency);
        } catch (FxUnsupportedCurrencyException $e) {
            return $this->fxUnsupportedResponse($e);
        } catch (FxUnavailableException $e) {
            return $this->fxUnavailableResponse();
        }

        $totalAmount = $this->bcSum($converted);
        $totalCount = $rows->count();

        $byCategory = $rows->groupBy(fn ($e) => $e->category?->value ?? 'other')
            ->map(fn (Collection $group, string $cat) => [
                'category' => $cat,
                'amount' => $this->bcSumFor($group, $converted),
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $byCycle = $rows->groupBy(fn ($e) => $e->billing_cycle?->value ?? 'one_time')
            ->map(fn (Collection $group, string $cycle) => [
                'billing_cycle' => $cycle,
                'amount' => $this->bcSumFor($group, $converted),
                'count' => $group->count(),
            ])
            ->values()
            ->all();

        $monthlyRecurring = $this->computeMonthlyRecurring($rows, $converted);

        return response()->json([
            'period' => $period->value,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'currency' => $displayCurrency,
            'currency_source' => $currencySource,
            'total_amount' => $this->roundTo2($totalAmount),
            'total_count' => $totalCount,
            'by_category' => $byCategory,
            'by_billing_cycle' => $byCycle,
            'monthly_recurring' => $this->roundTo2($monthlyRecurring),
        ]);
    }

    /**
     * GET /projects/{project}/expenses/trend
     */
    public function trend(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $request->validate([
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $displayCurrency = strtoupper((string) $request->query('currency'));

        $months = min(24, max(1, (int) $request->query('months', 6)));

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        $baseQuery = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds);

        if ($bucketId = $request->query('bucket_id')) {
            $baseQuery->where('bucket_id', (int) $bucketId);
        }

        $windowEnd = Carbon::now()->endOfMonth();

        $expenses = (clone $baseQuery)
            ->where('created_at', '<=', $windowEnd)
            ->get();

        try {
            [$converted, $currencySource] = $this->convertRows($expenses, $displayCurrency);
        } catch (FxUnsupportedCurrencyException $e) {
            return $this->fxUnsupportedResponse($e);
        } catch (FxUnavailableException $e) {
            return $this->fxUnavailableResponse();
        }

        $result = [];

        for ($i = 0; $i < $months; $i++) {
            $monthStart = Carbon::now()->subMonths($months - 1 - $i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $recurringSum = '0';
            $oneTimeSum = '0';

            foreach ($expenses as $e) {
                $createdAt = $e->created_at;
                $amount = $converted[$e->id] ?? '0';

                if ($e->billing_cycle === BillingCycle::OneTime) {
                    if ($createdAt >= $monthStart && $createdAt <= $monthEnd) {
                        $oneTimeSum = bcadd($oneTimeSum, $amount, self::SCALE);
                    }
                    continue;
                }

                if ($createdAt > $monthEnd) {
                    continue;
                }
                $dueDate = $e->next_due_date;
                if ($dueDate !== null && $dueDate < $monthStart) {
                    continue;
                }

                $recurringSum = bcadd($recurringSum, match ($e->billing_cycle) {
                    BillingCycle::Monthly => $amount,
                    BillingCycle::Quarterly => bcdiv($amount, '3', self::SCALE),
                    BillingCycle::Yearly => bcdiv($amount, '12', self::SCALE),
                    default => $amount,
                }, self::SCALE);
            }

            $result[] = [
                'month' => $monthStart->toDateString(),
                'total' => $this->roundTo2(bcadd($recurringSum, $oneTimeSum, self::SCALE)),
                'recurring' => $this->roundTo2($recurringSum),
                'one_time' => $this->roundTo2($oneTimeSum),
            ];
        }

        return response()->json([
            'currency' => $displayCurrency,
            'currency_source' => $currencySource,
            'months' => $result,
        ]);
    }

    /**
     * GET /projects/{project}/expenses/history
     *
     * Per-bucket "Total history": ONE row per payment (not per expense).
     * Walks the expense_payments of every expense in the bucket whose
     * paid_at falls in [from, to] (inclusive), newest first, each
     * payment's amount FX-converted into the chosen currency. The same
     * expense appears once per payment — the client groups the Items
     * filter by expense_id, so every row carries its parent's id/name.
     * FX semantics are identical to summary/trend.
     */
    public function history(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $request->validate([
            'bucket_id' => ['required', 'integer'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $bucketId = (int) $request->query('bucket_id');
        $from = Carbon::createFromFormat('Y-m-d', (string) $request->query('from'))->toDateString();
        $to = Carbon::createFromFormat('Y-m-d', (string) $request->query('to'))->toDateString();

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        // Target currency: the explicit query param, else the bucket's own
        // currency — so the window's "total" stays summable and every
        // converted_amount is expressed in the response `currency`. The
        // 'USD' fallback only bites the degenerate case where bucket_id
        // points at nothing, where the window is empty regardless.
        $bucket = ExpenseBucket::query()
            ->where('project_id', $project->id)
            ->find($bucketId);

        $currencyParam = $request->query('currency');
        $displayCurrency = $currencyParam !== null && $currencyParam !== ''
            ? strtoupper((string) $currencyParam)
            : strtoupper((string) ($bucket->currency ?? 'USD'));

        // One row per payment. The whereHas scopes to the requested
        // bucket AND the user's visible buckets; the window filters on
        // paid_at (the payment date), NOT the expense's created_at.
        $payments = ExpensePayment::query()
            ->with('expense')
            ->whereHas('expense', function (Builder $q) use ($project, $bucketId, $visibleBucketIds): void {
                $q->where('project_id', $project->id)
                    ->where('bucket_id', $bucketId)
                    ->whereIn('bucket_id', $visibleBucketIds);
            })
            ->whereBetween('paid_at', [$from, $to])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        try {
            [$converted, $currencySource] = $this->convertPayments($payments, $displayCurrency);
        } catch (FxUnsupportedCurrencyException $e) {
            return $this->fxUnsupportedResponse($e);
        } catch (FxUnavailableException $e) {
            return $this->fxUnavailableResponse();
        }

        $data = $payments->map(function (ExpensePayment $payment) use ($converted): array {
            $expense = $payment->expense;

            return [
                'id' => $payment->id,
                'expense_id' => $payment->expense_id,
                'expense_name' => $expense?->name,
                'paid_at' => $payment->paid_at?->toDateString(),
                'amount' => (string) $payment->amount,
                'currency' => $payment->currency,
                'converted_amount' => $converted[$payment->id] ?? $this->roundTo2((string) $payment->amount),
                // Parent expense fields — copied onto each payment row so
                // the client can filter/label without a second fetch.
                'category' => $expense?->category?->value,
                'billing_cycle' => $expense?->billing_cycle?->value,
                'payment_type' => $expense?->payment_type?->value,
                'payment_method_other' => $expense?->payment_method_other,
                'vendor' => $expense?->vendor,
            ];
        })->all();

        return response()->json([
            'currency' => $displayCurrency,
            'currency_source' => $currencySource,
            'from' => $from,
            'to' => $to,
            'total' => $this->roundTo2($this->bcSum($converted)),
            'data' => $data,
        ]);
    }

    /**
     * GET /projects/{project}/expenses/forecast
     *
     * Projects recurring expenses forward for N months. Monthly expenses
     * appear every month, quarterly on their 3-month cycle, yearly on
     * their 12-month cycle. One-time and archived expenses are excluded.
     */
    public function forecast(Request $request, Project $project): JsonResponse
    {
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $request->validate([
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $displayCurrency = strtoupper((string) $request->query('currency'));

        $months = min(24, max(1, (int) $request->query('months', 12)));

        $visibleBucketIds = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->pluck('id');

        $query = Expense::query()
            ->where('project_id', $project->id)
            ->whereIn('bucket_id', $visibleBucketIds)
            ->where('billing_cycle', '!=', BillingCycle::OneTime->value);

        if ($bucketId = $request->query('bucket_id')) {
            $query->where('bucket_id', (int) $bucketId);
        }

        $expenses = $query->get();

        try {
            [$converted, $currencySource] = $this->convertRows($expenses, $displayCurrency);
        } catch (FxUnsupportedCurrencyException $e) {
            return $this->fxUnsupportedResponse($e);
        } catch (FxUnavailableException $e) {
            return $this->fxUnavailableResponse();
        }

        $includeBreakdown = $expenses->count() <= 50;

        $result = [];

        for ($i = 1; $i <= $months; $i++) {
            $monthStart = Carbon::now()->addMonths($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->endOfMonth();

            $total = '0';
            $count = 0;
            $breakdown = [];

            foreach ($expenses as $expense) {
                if (! $this->expenseLandsInMonth($expense, $monthStart, $monthEnd)) {
                    continue;
                }

                $amount = $converted[$expense->id] ?? '0';
                $total = bcadd($total, $amount, self::SCALE);
                $count++;

                if ($includeBreakdown) {
                    $breakdown[] = [
                        'expense_id' => $expense->id,
                        'name' => $expense->name,
                        'amount' => $this->roundTo2($amount),
                        'billing_cycle' => $expense->billing_cycle->value,
                    ];
                }
            }

            $entry = [
                'month' => $monthStart->toDateString(),
                'projected_total' => $this->roundTo2($total),
                'expense_count' => $count,
            ];

            if ($includeBreakdown) {
                $entry['breakdown'] = $breakdown;
            }

            $result[] = $entry;
        }

        return response()->json([
            'currency' => $displayCurrency,
            'currency_source' => $currencySource,
            'months' => $result,
        ]);
    }

    /**
     * Does this recurring expense "land" (i.e. produce a charge) in the
     * given month? Monthly expenses always land. Quarterly/yearly land
     * only in months that align with their cycle from the anchor date.
     */
    private function expenseLandsInMonth(Expense $expense, Carbon $monthStart, Carbon $monthEnd): bool
    {
        if ($expense->billing_cycle === BillingCycle::Monthly) {
            return true;
        }

        $interval = match ($expense->billing_cycle) {
            BillingCycle::Quarterly => 3,
            BillingCycle::Yearly => 12,
            default => 1,
        };

        // Anchor: next_due_date if set, otherwise created_at.
        $anchor = $expense->next_due_date
            ? Carbon::parse($expense->next_due_date)->startOfMonth()
            : Carbon::parse($expense->created_at)->startOfMonth();

        // If anchor is in the past, roll it forward by the cycle
        // interval until it's in or after the current month.
        $now = Carbon::now()->startOfMonth();
        while ($anchor->lt($now)) {
            $anchor->addMonths($interval);
        }

        // Check if any occurrence from the anchor lands in [monthStart, monthEnd].
        $cursor = $anchor->copy();
        while ($cursor->lte($monthEnd)) {
            if ($cursor->gte($monthStart) && $cursor->lte($monthEnd)) {
                return true;
            }
            $cursor->addMonths($interval);
        }

        return false;
    }

    /**
     * Apply period filtering with the semantics described in the spec:
     * - one_time: created_at within period
     * - recurring: created before period end AND (no due date OR due date >= period start)
     */
    private function applyPeriodFilter(Builder $query, AnalyticsPeriod $period, Carbon $start, Carbon $end): void
    {
        if ($period === AnalyticsPeriod::AllTime) {
            return;
        }

        $query->where(function (Builder $q) use ($start, $end): void {
            // One-time: created within the period.
            $q->where(function (Builder $sub) use ($start, $end): void {
                $sub->where('billing_cycle', BillingCycle::OneTime->value)
                    ->whereBetween('created_at', [$start, $end]);
            });

            // Recurring: created before period end AND (no due date OR due date >= period start).
            $q->orWhere(function (Builder $sub) use ($start, $end): void {
                $sub->where('billing_cycle', '!=', BillingCycle::OneTime->value)
                    ->where('created_at', '<=', $end)
                    ->where(function (Builder $inner) use ($start): void {
                        $inner->whereNull('next_due_date')
                            ->orWhere('next_due_date', '>=', $start);
                    });
            });
        });
    }

    /**
     * Compute monthly recurring estimate from already-converted amounts.
     * Monthly contributes as-is, quarterly ÷ 3, yearly ÷ 12, one_time
     * excluded.
     *
     * @param  iterable<int, Expense>  $expenses
     * @param  array<int, string>  $converted
     */
    private function computeMonthlyRecurring(iterable $expenses, array $converted): string
    {
        $sum = '0';

        foreach ($expenses as $e) {
            if ($e->billing_cycle === BillingCycle::OneTime) {
                continue;
            }

            $amount = $converted[$e->id] ?? '0';

            $sum = bcadd($sum, match ($e->billing_cycle) {
                BillingCycle::Monthly => $amount,
                BillingCycle::Quarterly => bcdiv($amount, '3', self::SCALE),
                BillingCycle::Yearly => bcdiv($amount, '12', self::SCALE),
                default => $amount,
            }, self::SCALE);
        }

        return $sum;
    }

    /**
     * Convert each expense's amount into $displayCurrency using
     * {@see FxRateService}. Returns a tuple of [convertedById, source]
     * where source is "converted" if any expense was in a different
     * currency from the display currency, "native" otherwise.
     *
     * @param  iterable<int, Expense>  $expenses
     * @return array{0: array<int, string>, 1: 'converted'|'native'}
     */
    private function convertRows(iterable $expenses, string $displayCurrency): array
    {
        $converted = [];
        $hasMixed = false;

        foreach ($expenses as $expense) {
            $rowCurrency = strtoupper((string) $expense->currency);

            if ($rowCurrency !== $displayCurrency) {
                $hasMixed = true;
            }

            $converted[$expense->id] = $this->fx->convert(
                $rowCurrency,
                $displayCurrency,
                (string) $expense->amount,
            );
        }

        return [$converted, $hasMixed ? 'converted' : 'native'];
    }

    /**
     * Convert each payment's snapshot amount into $displayCurrency. Keyed
     * by payment id. Source is "converted" if any payment was in a
     * currency different from the display currency, "native" otherwise.
     *
     * @param  iterable<int, ExpensePayment>  $payments
     * @return array{0: array<int, string>, 1: 'converted'|'native'}
     */
    private function convertPayments(iterable $payments, string $displayCurrency): array
    {
        $converted = [];
        $hasMixed = false;

        foreach ($payments as $payment) {
            $rowCurrency = strtoupper((string) $payment->currency);

            if ($rowCurrency !== $displayCurrency) {
                $hasMixed = true;
            }

            $converted[$payment->id] = $this->fx->convert(
                $rowCurrency,
                $displayCurrency,
                (string) $payment->amount,
            );
        }

        return [$converted, $hasMixed ? 'converted' : 'native'];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function bcSum(array $values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, (string) $v, self::SCALE);
        }

        return $sum;
    }

    /**
     * Sum the converted amounts for the expenses in $group.
     *
     * @param  Collection<int, Expense>  $group
     * @param  array<int, string>  $converted
     */
    private function bcSumFor(Collection $group, array $converted): string
    {
        $sum = '0';
        foreach ($group as $expense) {
            $sum = bcadd($sum, $converted[$expense->id] ?? '0', self::SCALE);
        }

        return $this->roundTo2($sum);
    }

    private function roundTo2(string $value): string
    {
        $bump = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $bump, self::SCALE), '0', 2);
    }

    private function fxUnsupportedResponse(FxUnsupportedCurrencyException $e): JsonResponse
    {
        return response()->json([
            'code' => 'fx_unsupported_currency',
            'currency' => $e->currency,
            'message' => "Currency {$e->currency} is not supported.",
        ], 422);
    }

    private function fxUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'fx_unavailable',
            'message' => 'FX rates are temporarily unavailable. Please try again later.',
        ], 502);
    }
}

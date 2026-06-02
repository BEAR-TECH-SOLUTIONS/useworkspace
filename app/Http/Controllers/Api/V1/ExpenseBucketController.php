<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentType;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseBucketRequest;
use App\Http\Requests\Expenses\UpdateExpenseBucketRequest;
use App\Http\Resources\Expenses\ExpenseBucketResource;
use App\Http\Resources\Expenses\ExpenseResource;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseBucketController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        // Pattern B users (only child-level grants) can reach this list;
        // visibleScope() narrows the result to what they can actually see.
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $buckets = $this->perms
            ->visibleScope($request->user(), ResourceType::Bucket, $project)
            ->orderBy('id')
            ->get();

        return ExpenseBucketResource::collection($buckets);
    }

    public function store(StoreExpenseBucketRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $currency = $request->exists('currency')
            ? ($request->input('currency') !== null ? strtoupper($request->input('currency')) : null)
            : 'USD';

        $bucket = ExpenseBucket::create([
            'project_id' => $project->id,
            'name' => $request->string('name')->toString(),
            'currency' => $currency,
            'color' => $request->input('color'),
            'is_default' => false,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'bucket' => new ExpenseBucketResource($bucket),
        ], 201);
    }

    public function show(Request $request, ExpenseBucket $expenseBucket): JsonResponse
    {
        $this->authorize('view', $expenseBucket);

        $query = Expense::query()
            ->where('bucket_id', $expenseBucket->id);

        // ?payment_types=card,paypal | ?payment_types=card,null | ?payment_types=null
        // OR within the list. The literal `null` matches expenses with
        // payment_type IS NULL — opt-in only, never implicit.
        if ($request->filled('payment_types')) {
            $tokens = $this->splitCsv($request->string('payment_types')->toString());

            $values = [];
            $includeNull = false;
            foreach ($tokens as $token) {
                if (strcasecmp($token, 'null') === 0) {
                    $includeNull = true;
                    continue;
                }
                if (PaymentType::tryFrom($token) !== null) {
                    $values[] = $token;
                }
            }

            $query->where(function ($q) use ($values, $includeNull): void {
                if (! empty($values)) {
                    $q->whereIn('payment_type', $values);
                }
                if ($includeNull) {
                    $q->orWhereNull('payment_type');
                }
                if (empty($values) && ! $includeNull) {
                    // No recognised tokens — return zero matches rather
                    // than silently widening the query.
                    $q->whereRaw('1 = 0');
                }
            });
        }

        // ?tags=AWS,Stripe — OR within the list. The expenses.tags
        // column is TEXT storing a JSON array (Laravel's `array` cast),
        // so we cast to jsonb and check containment for each tag. We
        // avoid the JSONB `?|` operator because `?` collides with
        // Laravel's parameter placeholder. `@>` has no such collision.
        if ($request->filled('tags')) {
            $tags = $this->splitCsv($request->string('tags')->toString());
            if (! empty($tags)) {
                $query->where(function ($sub) use ($tags): void {
                    foreach ($tags as $tag) {
                        $sub->orWhereRaw('tags::jsonb @> ?::jsonb', [
                            json_encode([$tag], JSON_THROW_ON_ERROR),
                        ]);
                    }
                });
            }
        }

        $expenses = $query->orderByDesc('created_at')->limit(500)->get();

        return response()->json([
            'bucket' => new ExpenseBucketResource($expenseBucket),
            'expenses' => ExpenseResource::collection($expenses),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function splitCsv(string $raw): array
    {
        return array_values(array_filter(
            array_map(fn (string $part) => trim($part), explode(',', $raw)),
            fn (string $part) => $part !== '',
        ));
    }


    public function update(UpdateExpenseBucketRequest $request, ExpenseBucket $expenseBucket): JsonResponse
    {
        $this->authorize('update', $expenseBucket);

        $payload = $request->only(['name', 'currency', 'color']);

        if (array_key_exists('currency', $payload) && $payload['currency'] !== null) {
            $payload['currency'] = strtoupper($payload['currency']);
        }

        $expenseBucket->fill($payload)->save();

        return response()->json([
            'bucket' => new ExpenseBucketResource($expenseBucket->refresh()),
        ]);
    }

    public function archive(ExpenseBucket $expenseBucket): JsonResponse
    {
        $this->authorize('archive', $expenseBucket);

        $expenseBucket->update(['is_archived' => ! $expenseBucket->is_archived]);

        return response()->json([
            'bucket' => new ExpenseBucketResource($expenseBucket->refresh()),
        ]);
    }

    public function destroy(ExpenseBucket $expenseBucket): JsonResponse
    {
        $this->authorize('delete', $expenseBucket);

        if ($expenseBucket->is_default) {
            return response()->json(['message' => 'The default expense bucket cannot be deleted.'], 422);
        }

        $expenseBucket->delete();

        return response()->json(status: 204);
    }
}

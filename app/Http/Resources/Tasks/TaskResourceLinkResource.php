<?php

namespace App\Http\Resources\Tasks;

use App\Enums\BillingCycle;
use App\Enums\TaskResourceLinkKind;
use App\Http\Resources\Docs\DocResource;
use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Tasks\TaskResourceLink;
use App\Models\Vault\Credential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Variant resource: emits either the "accessible" shape (full preview)
 * or the "locked" shape (id + type only, no identifying data).
 *
 * Callers are expected to pre-resolve access — the controller passes
 * `$link->target` and a boolean `hasAccess` via `additional`, so this
 * class never re-runs the permission check. Keeps both the row-by-row
 * list and the single-row POST response on the same path.
 *
 * @mixin TaskResourceLink
 */
class TaskResourceLinkResource extends JsonResource
{
    public function __construct(
        TaskResourceLink $link,
        private readonly bool $hasAccess,
        private readonly mixed $target = null,
    ) {
        parent::__construct($link);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource_type instanceof TaskResourceLinkKind
            ? $this->resource_type
            : TaskResourceLinkKind::from((string) $this->resource_type);

        if (! $this->hasAccess) {
            // Never leak a name, never leak an identifying preview —
            // the caller already can't read the resource. Leaking the
            // id is fine (they know they're blocked) but anything
            // beyond that is an info-disclosure bug.
            return [
                'id' => (int) $this->id,
                'task_id' => (int) $this->task_item_id,
                'resource_type' => $type->value,
                'resource_id' => (int) $this->resource_id,
                'has_access' => false,
                'created_by' => (int) $this->created_by,
                'created_at' => $this->created_at?->toIso8601String(),
            ];
        }

        return [
            'id' => (int) $this->id,
            'task_id' => (int) $this->task_item_id,
            'resource_type' => $type->value,
            'resource_id' => (int) $this->resource_id,
            'has_access' => true,
            'preview' => $this->buildPreview($type),
            'created_by' => (int) $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPreview(TaskResourceLinkKind $type): ?array
    {
        return match ($type) {
            TaskResourceLinkKind::Credential => $this->credentialPreview(),
            TaskResourceLinkKind::ExpenseBucket => $this->bucketPreview(),
            TaskResourceLinkKind::Expense => $this->expensePreview(),
            TaskResourceLinkKind::Doc => $this->docPreview(),
        };
    }

    /**
     * Doc preview — title plus first ~200 chars of plaintext content.
     * Matches the spec: `{ title, content_preview }`. Derives the
     * preview from the already-indexed `content_text` column so we
     * don't have to re-walk the JSONB per render.
     *
     * @return array<string, mixed>|null
     */
    private function docPreview(): ?array
    {
        /** @var Doc|null $d */
        $d = $this->target;
        if (! $d instanceof Doc) {
            return null;
        }

        $text = (string) ($d->content_text ?? '');

        return [
            'title' => $d->title,
            'content_preview' => $text === ''
                ? null
                : mb_substr($text, 0, DocResource::PREVIEW_LENGTH),
            'is_archived' => (bool) $d->is_archived,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentialPreview(): ?array
    {
        /** @var Credential|null $c */
        $c = $this->target;
        if (! $c instanceof Credential) {
            return null;
        }

        return [
            'name' => $c->name,
            'credential_type' => $c->type?->value,
            'vault_id' => $c->vault_id !== null ? (int) $c->vault_id : null,
            'vault_name' => $c->vault?->name,
            'url' => $c->url,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bucketPreview(): ?array
    {
        /** @var ExpenseBucket|null $b */
        $b = $this->target;
        if (! $b instanceof ExpenseBucket) {
            return null;
        }

        // Mirror ProjectDashboardController::computeMonthlyBurn — one
        // pass over this bucket's non-one-time expenses.
        $monthlyBurn = 0.0;
        foreach ($b->expenses()->where('billing_cycle', '!=', BillingCycle::OneTime->value)->get() as $e) {
            $amount = (float) $e->amount;
            $cycle = $e->billing_cycle instanceof BillingCycle
                ? $e->billing_cycle
                : BillingCycle::from((string) $e->billing_cycle);

            $monthlyBurn += match ($cycle) {
                BillingCycle::Monthly => $amount,
                BillingCycle::Quarterly => round($amount / 3, 2),
                BillingCycle::Yearly => round($amount / 12, 2),
                BillingCycle::OneTime => 0.0,
            };
        }

        return [
            'name' => $b->name,
            'color' => $b->color,
            'icon' => $b->icon,
            'monthly_burn' => number_format($monthlyBurn, 2, '.', ''),
            'currency' => $b->currency,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function expensePreview(): ?array
    {
        /** @var Expense|null $e */
        $e = $this->target;
        if (! $e instanceof Expense) {
            return null;
        }

        return [
            'name' => $e->name,
            'amount' => number_format((float) $e->amount, 2, '.', ''),
            'currency' => $e->currency,
            'category' => $e->category?->value,
            'next_due_date' => $e->next_due_date?->toDateString(),
            'bucket_id' => (int) $e->bucket_id,
            'bucket_name' => $e->bucket?->name,
        ];
    }
}

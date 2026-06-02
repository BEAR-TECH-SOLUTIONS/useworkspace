<?php

namespace App\Http\Resources\Expenses;

use App\Models\Expenses\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'bucket_id' => $this->bucket_id,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category?->value,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'billing_cycle' => $this->billing_cycle?->value,
            'vendor' => $this->vendor,
            'tags' => $this->tags ?? [],
            'payment_type' => $this->payment_type?->value,
            'payment_method_other' => $this->payment_method_other,
            'url' => $this->url,
            'next_due_date' => $this->next_due_date?->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Payment aggregates — always present so the expense list
            // can show payment status without fetching the full history.
            'payment_count' => (int) ($this->resource->payments_count
                ?? $this->resource->payments()->count()),
            'total_paid' => number_format(
                (float) ($this->resource->payments_sum_amount
                    ?? $this->resource->payments()->sum('amount')),
                2, '.', '',
            ),
            'last_paid_at' => $this->resource->payments_max_paid_at
                ?? $this->resource->payments()->max('paid_at'),
        ];
    }
}

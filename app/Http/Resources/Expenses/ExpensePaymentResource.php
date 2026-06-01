<?php

namespace App\Http\Resources\Expenses;

use App\Models\Expenses\ExpensePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpensePayment
 */
class ExpensePaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'expense_id' => (int) $this->expense_id,
            'paid_at' => $this->paid_at?->toDateString(),
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'currency' => $this->currency,
            'note' => $this->note,
            'created_by' => (int) $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

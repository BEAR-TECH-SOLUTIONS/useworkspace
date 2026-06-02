<?php

namespace App\Http\Resources\Sharing;

use App\Models\Expenses\Expense;

/**
 * Frozen JSON snapshot of an Expense for a public share link.
 * Excludes `created_by`.
 */
final class ExpenseShareSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function forResource(Expense $expense): array
    {
        $expense->load('payments:id,expense_id,amount,currency,paid_at,note');

        return [
            'id' => (int) $expense->id,
            'name' => (string) $expense->name,
            'description' => $expense->description,
            'category' => $expense->category?->value,
            'amount' => (string) $expense->amount,
            'currency' => $expense->currency,
            'billing_cycle' => $expense->billing_cycle?->value,
            'vendor' => $expense->vendor,
            'next_due_date' => $expense->next_due_date?->toDateString(),
            'payments' => $expense->payments
                ->sortBy('paid_at')
                ->values()
                ->map(fn ($p): array => [
                    'amount' => (string) $p->amount,
                    'currency' => $p->currency,
                    'paid_at' => $p->paid_at?->toDateString(),
                    'note' => $p->note,
                ])->all(),
        ];
    }
}

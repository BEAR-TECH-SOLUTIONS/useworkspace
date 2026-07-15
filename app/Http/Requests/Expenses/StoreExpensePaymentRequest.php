<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpensePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller gates via PermissionService.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paid_at' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // false back-fills a missed payment without moving the schedule
            // forward (recurring only). Defaults to true — the advancing path.
            'advance_due_date' => ['sometimes', 'boolean'],
        ];
    }
}

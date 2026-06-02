<?php

namespace App\Http\Requests\Expenses;

use App\Enums\BillingCycle;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bucket_id' => ['required', 'integer', 'exists:expense_buckets,id'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'amount' => ['required', 'numeric', 'gte:0', 'max:99999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'vendor' => ['nullable', 'string', 'max:200'],
            'next_due_date' => ['nullable', 'date_format:Y-m-d'],

            // Payment-method block. The `payment_type='other' ↔
            // payment_method_other set` invariant is enforced in
            // withValidator() rather than via dependency rules, so the
            // error code matches the spec's contract.
            'payment_type' => ['nullable', Rule::enum(PaymentType::class)],
            'payment_method_other' => ['nullable', 'string', 'max:120'],

            // URL must be http(s) only — the regex blocks javascript:,
            // mailto:, data:, etc. before the URL validator runs.
            'url' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\//i', 'url'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator): void {
            \App\Http\Requests\Expenses\PaymentTypeInvariant::enforce($this, $validator);
        });
    }
}

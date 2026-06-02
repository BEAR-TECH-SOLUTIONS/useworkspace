<?php

namespace App\Http\Requests\Expenses;

use App\Enums\BillingCycle;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentType;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
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
            'bucket_id' => ['sometimes', 'integer', 'exists:expense_buckets,id'],
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::enum(ExpenseCategory::class)],
            'amount' => ['sometimes', 'numeric', 'gte:0', 'max:99999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'next_due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],

            'payment_type' => ['sometimes', 'nullable', Rule::enum(PaymentType::class)],
            'payment_method_other' => ['sometimes', 'nullable', 'string', 'max:120'],

            // To clear the URL on PATCH, send `null`; an empty string
            // is rejected. Same scheme allow-list as Store.
            'url' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^https?:\/\//i', 'url'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator): void {
            PaymentTypeInvariant::enforce($this, $validator);

            // Bucket move guard: any PATCH supplying bucket_id must
            // target a bucket in the expense's own project AND the
            // caller must hold `update` on that target bucket. Closes
            // the cross-bucket IDOR (audit C6).
            $expense = $this->route('expense');
            if ($this->has('bucket_id') && $expense instanceof Expense) {
                $bucket = ExpenseBucket::query()->find((int) $this->input('bucket_id'));
                if ($bucket === null) {
                    $validator->errors()->add('bucket_id', 'Target bucket does not exist.');
                } else {
                    if ((int) $bucket->project_id !== (int) $expense->project_id) {
                        $validator->errors()->add(
                            'bucket_id',
                            'Target bucket must belong to the same project as the expense.',
                        );
                    }
                    if (! Gate::forUser($this->user())->allows('update', $bucket)) {
                        $validator->errors()->add(
                            'bucket_id',
                            'You do not have permission to move expenses into the target bucket.',
                        );
                    }
                }
            }
        });
    }
}

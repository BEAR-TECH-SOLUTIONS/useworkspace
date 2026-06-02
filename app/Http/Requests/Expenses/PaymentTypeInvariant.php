<?php

namespace App\Http\Requests\Expenses;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `payment_method_other` must be set ↔ `payment_type === 'other'`. Any
 * other combination raises 422 `payment_method_other_only_for_other`.
 *
 * The DB also enforces this via a CHECK constraint, but raising at the
 * validation layer gives a clean structured error code instead of a
 * SQL-state explosion.
 *
 * For PATCHes (UpdateExpenseRequest), a field is "in play" only when
 * the request explicitly carries it — `has()` rather than `filled()`,
 * so that PATCH `{ payment_type: null }` is treated as "clear it" and
 * is checked against the value already on the row.
 */
class PaymentTypeInvariant
{
    public static function enforce(FormRequest $request, Validator $validator): void
    {
        // Resolve the effective post-write state. Pull from the request
        // when present, otherwise fall back to the model under update.
        $existing = $request->route('expense');

        $type = $request->has('payment_type')
            ? $request->input('payment_type')
            : ($existing?->payment_type?->value ?? null);

        $other = $request->has('payment_method_other')
            ? $request->input('payment_method_other')
            : ($existing?->payment_method_other ?? null);

        // Treat empty string as null — both fail the "non-empty when
        // type=other" test and trigger the same 422.
        $otherFilled = is_string($other) && $other !== '';

        $valid = ($type === 'other' && $otherFilled)
            || ($type !== 'other' && ! $otherFilled);

        if (! $valid) {
            $validator->errors()->add(
                'payment_method_other',
                'payment_method_other_only_for_other',
            );
        }
    }
}

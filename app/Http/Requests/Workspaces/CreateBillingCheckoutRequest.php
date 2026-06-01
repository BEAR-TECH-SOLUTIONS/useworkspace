<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\PlanTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBillingCheckoutRequest extends FormRequest
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
        // The desktop client renders the GET /api/v1/plans catalog
        // and posts the chosen plan's id back here. Validate against
        // PlanTier scoped to self-serve cases — only Free is excluded
        // (nothing to charge); SelfHosted is now a paid self-serve
        // checkout that auto-issues a license.
        return [
            'tier' => ['required', Rule::in(
                array_map(
                    static fn (PlanTier $p): string => $p->value,
                    PlanTier::selfServeCheckoutCases(),
                ),
            )],
            // Currently only Team (legacy Business) supports extra
            // seats above the default cap; ignored for other tiers.
            'extra_seats' => ['sometimes', 'integer', 'min:0', 'max:500'],
        ];
    }
}

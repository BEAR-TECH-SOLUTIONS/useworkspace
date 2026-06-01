<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\PlanTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            // Tier on create may only be `free`. Paid tiers go through
            // the billing checkout flow (commit 4) so there's no way to
            // self-assign a paid tier without Stripe ever touching it.
            'tier' => ['sometimes', Rule::in([PlanTier::Free->value])],
        ];
    }
}

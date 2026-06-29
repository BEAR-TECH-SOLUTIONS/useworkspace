<?php

namespace App\Http\Requests\Telegram;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTelegramPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Self-scoped: always the authenticated user's own preferences.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `null` (or omitted) means "all types"; an array is an explicit
            // allow-list. An empty array means "none".
            'enabled_types' => ['present', 'nullable', 'array'],
            'enabled_types.*' => [Rule::enum(NotificationType::class)],
        ];
    }
}

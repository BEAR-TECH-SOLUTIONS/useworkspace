<?php

namespace App\Http\Requests\Docs;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:500'],
            // Tiptap JSON document. Optional — brand-new docs can
            // start empty and pick up content on the first PATCH.
            'content' => ['sometimes', 'array'],
        ];
    }
}

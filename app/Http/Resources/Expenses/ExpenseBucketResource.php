<?php

namespace App\Http\Resources\Expenses;

use App\Models\Expenses\ExpenseBucket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseBucket
 */
class ExpenseBucketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'currency' => $this->currency,
            'color' => $this->color,
            'is_default' => $this->is_default,
            'is_archived' => $this->is_archived,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

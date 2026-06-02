<?php

namespace App\Http\Resources\Docs;

use App\Models\Docs\Doc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Doc
 *
 * Dual-shape resource: list endpoints pass `preview: true` via
 * `DocResource::collection($docs, preview: true)` to swap the full
 * Tiptap JSON for a 200-char plaintext preview. Single-doc fetches
 * include the full `content`.
 */
class DocResource extends JsonResource
{
    public const PREVIEW_MODE_ATTR = 'doc_resource_preview_mode';

    public const PREVIEW_LENGTH = 200;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $previewMode = (bool) $this->resource->getAttribute(self::PREVIEW_MODE_ATTR);

        $out = [
            'id' => (int) $this->id,
            'project_id' => (int) $this->project_id,
            'title' => $this->title,
            'created_by' => $this->created_by !== null ? (int) $this->created_by : null,
            'updated_by' => $this->updated_by !== null ? (int) $this->updated_by : null,
            'is_archived' => (bool) $this->is_archived,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($previewMode) {
            $text = (string) ($this->content_text ?? '');
            $out['content_preview'] = $text === '' ? null : mb_substr($text, 0, self::PREVIEW_LENGTH);
        } else {
            // Full payload — include the Tiptap JSON. Coerce an empty
            // cast result to an object-shaped array so the wire format
            // stays `{}` rather than `[]` on brand-new docs.
            $content = $this->content;
            $out['content'] = is_array($content) && $content === [] ? (object) [] : $content;
        }

        return $out;
    }

    /**
     * Helper to stamp every doc in a collection with the preview flag
     * before serialisation. Used by the list endpoint.
     *
     * @param  iterable<Doc>  $docs
     */
    public static function previewCollection(iterable $docs): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        foreach ($docs as $doc) {
            $doc->setAttribute(self::PREVIEW_MODE_ATTR, true);
        }

        return self::collection($docs);
    }
}

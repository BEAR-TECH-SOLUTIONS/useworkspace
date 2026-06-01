<?php

namespace App\Http\Resources\Sharing;

use App\Models\Docs\Doc;

/**
 * Frozen JSON snapshot of a Doc for a public share link.
 *
 * Walks the BlockNote/Tiptap content tree and replaces any mention
 * `props.userId` with null (display name kept). Stops the doc from
 * leaking team-member ids to a public viewer.
 */
final class DocShareSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function forResource(Doc $doc): array
    {
        $content = is_array($doc->content) ? $doc->content : [];

        return [
            'id' => (int) $doc->id,
            'title' => (string) $doc->title,
            'content' => self::stripMentionUserIds($content),
            'updated_at' => $doc->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private static function stripMentionUserIds(array $node): array
    {
        if (isset($node['type']) && $node['type'] === 'mention' && isset($node['props']) && is_array($node['props'])) {
            $node['props']['userId'] = null;
        }

        if (isset($node['attrs']) && is_array($node['attrs']) && array_key_exists('userId', $node['attrs'])) {
            $node['attrs']['userId'] = null;
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(
                fn ($child) => is_array($child) ? self::stripMentionUserIds($child) : $child,
                $node['content'],
            );
        }

        // Top-level array of nodes (no `type` key on the root).
        if (! isset($node['type'])) {
            $isList = array_keys($node) === range(0, count($node) - 1);
            if ($isList) {
                return array_map(
                    fn ($child) => is_array($child) ? self::stripMentionUserIds($child) : $child,
                    $node,
                );
            }
        }

        return $node;
    }
}

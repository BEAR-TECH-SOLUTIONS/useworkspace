<?php

namespace App\Services\Docs;

/**
 * Flatten a rich-text document to a plain-text string so Postgres FTS
 * can index it. Handles both shapes the client may send:
 *
 *   - Tiptap: `{type: 'doc', content: [blocks…]}`
 *   - BlockNote: `[block1, block2, …]` at the root
 *
 * Walks the tree concatenating every `text` node and inserting newlines
 * between block-level nodes (paragraph, heading, list item, …) so
 * adjacent blocks don't silently merge.
 */
class DocContentTextExtractor
{
    /** @var array<int, string> */
    private const BLOCK_TYPES = [
        // Tiptap block names
        'paragraph',
        'heading',
        'blockquote',
        'codeBlock',
        'listItem',
        'bulletList',
        'orderedList',
        'taskItem',
        'taskList',
        // BlockNote block names
        'bulletListItem',
        'numberedListItem',
        'checkListItem',
        'quote',
        'table',
    ];

    /**
     * @param  array<string, mixed>|array<int, mixed>|null  $content
     */
    public function extract(?array $content): ?string
    {
        if (! is_array($content) || $content === []) {
            return null;
        }

        $buffer = '';

        // BlockNote ships content as a plain list of block objects.
        // Tiptap wraps everything under a root `doc` node. `array_is_list`
        // distinguishes the two without inspecting keys manually.
        if (array_is_list($content)) {
            foreach ($content as $block) {
                if (is_array($block)) {
                    $this->walk($block, $buffer);
                }
            }
        } else {
            $this->walk($content, $buffer);
        }

        $trimmed = trim(preg_replace('/[ \t]+/', ' ', $buffer));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function walk(array $node, string &$buffer): void
    {
        $type = (string) ($node['type'] ?? '');

        if ($type === 'text' && isset($node['text']) && is_string($node['text'])) {
            $buffer .= $node['text'];
        }

        // Tiptap and BlockNote both store inline text runs under
        // `content` on block + mark nodes. BlockNote also nests child
        // blocks under `children` (e.g. indented list items). Walk
        // both so nothing hides in the tree.
        foreach (['content', 'children'] as $edge) {
            if (isset($node[$edge]) && is_array($node[$edge])) {
                foreach ($node[$edge] as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $this->walk($child, $buffer);
                }
            }
        }

        if (in_array($type, self::BLOCK_TYPES, true)) {
            $buffer .= "\n";
        }
    }
}

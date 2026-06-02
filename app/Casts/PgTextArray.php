<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for native PostgreSQL TEXT[] columns.
 *
 * Eloquent's built-in `array` cast serialises to JSON, which Postgres
 * rejects on a TEXT[] column. This cast converts to/from the Postgres
 * array literal syntax (`{a,"b with spaces"}`).
 */
class PgTextArray implements CastsAttributes
{
    /**
     * @return array<int, string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $stripped = trim((string) $value, '{}');

        if ($stripped === '') {
            return [];
        }

        return array_map(
            static fn (string $item) => stripcslashes(trim($item, '"')),
            str_getcsv($stripped),
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $value = [$value];
        }

        if ($value === []) {
            return '{}';
        }

        $escaped = array_map(static function (mixed $item): string {
            $item = (string) $item;
            // Quote every element to keep parsing safe regardless of contents.
            $item = str_replace(['\\', '"'], ['\\\\', '\\"'], $item);

            return '"'.$item.'"';
        }, $value);

        return '{'.implode(',', $escaped).'}';
    }
}

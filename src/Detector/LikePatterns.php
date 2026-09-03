<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

defined('ABSPATH') || exit;

/**
 * Shared query-building and match-verification logic.
 *
 * Strategy everywhere: one broad SQL pass with OR'd LIKE conditions to find
 * candidate rows cheaply, then precise PHP verification with digit-boundary
 * regexes — so attachment 123 never matches wp-image-1234 or "id":1234.
 */
final class LikePatterns
{
    /** Hard ceiling on how deep a stored structure is walked before it is called used. */
    private const MAX_STRUCTURE_DEPTH = 128;

    /** Depth past which arrays carry a path marker, so reference cycles become visible. */
    private const CYCLE_WATCH_DEPTH = 16;

    /** The path marker's key. NUL-wrapped, so no stored key can collide with it. */
    private const CYCLE_MARK = "\0freshet_unusedmedia_path\0";

    /**
     * OR'd SQL conditions matching an attachment ID inside a text column:
     * exact value, comma lists, serialized int/string, JSON "id", and JSON
     * values under any other key.
     *
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function idConditions(string $column, int $id): array
    {
        global $wpdb;

        $like = $wpdb->esc_like((string) $id);
        $serializedString = sprintf('s:%d:"%d"', strlen((string) $id), $id);

        $conditions = [
            "{$column} = %s",          // exact '123'
            "{$column} LIKE %s",       // '123,…' comma list start
            "{$column} LIKE %s",       // '…,123' comma list end
            "{$column} LIKE %s",       // '…,123,…' comma list middle
            "{$column} LIKE %s",       // serialized int i:123;
            "{$column} LIKE %s",       // serialized string s:3:"123"
            "{$column} LIKE %s",       // JSON "id":123
            "{$column} LIKE %s",       // JSON "id":"123"
            "{$column} LIKE %s",       // JSON string value "123" (any key, or array member)
            "{$column} LIKE %s",       // JSON number value :123, (any key)
            "{$column} LIKE %s",       // JSON number value :123} (last key)
        ];

        $params = [
            (string) $id,
            $like . ',%',
            '%,' . $like,
            '%,' . $like . ',%',
            '%' . $wpdb->esc_like('i:' . $id . ';') . '%',
            '%' . $wpdb->esc_like($serializedString) . '%',
            '%' . $wpdb->esc_like('"id":' . $id) . '%',
            '%' . $wpdb->esc_like('"id":"' . $id . '"') . '%',
            '%' . $wpdb->esc_like('"' . $id . '"') . '%',
            '%' . $wpdb->esc_like(':' . $id . ',') . '%',
            '%' . $wpdb->esc_like(':' . $id . '}') . '%',
        ];

        return [$conditions, $params];
    }

    /**
     * OR'd LIKE conditions for the attachment's basenames.
     *
     * @param string[] $basenames
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function basenameConditions(string $column, array $basenames): array
    {
        global $wpdb;

        $conditions = [];
        $params = [];

        foreach ($basenames as $name) {
            $conditions[] = "{$column} LIKE %s";
            $params[] = '%' . $wpdb->esc_like($name) . '%';
        }

        return [$conditions, $params];
    }

    // ------------------------------------------------------------ verification

    /** @param string[] $basenames */
    public static function containsBasename(string $text, array $basenames): bool
    {
        foreach ($basenames as $name) {
            if ($name !== '' && str_contains($text, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Whole value is the ID: '123'. */
    public static function isExactId(string $text, int $id): bool
    {
        return trim($text) === (string) $id;
    }

    /** ID as member of a comma-separated list ('4,123,9'), boundaries enforced. */
    public static function inCommaList(string $text, int $id): bool
    {
        return in_array((string) $id, array_map('trim', explode(',', $text)), true);
    }

    /** Serialized int value i:123; (ambiguous: could be an array key). */
    public static function hasSerializedInt(string $text, int $id): bool
    {
        return str_contains($text, 'i:' . $id . ';');
    }

    /** Serialized string value s:3:"123" (strlen-exact, unambiguous match). */
    public static function hasSerializedString(string $text, int $id): bool
    {
        return str_contains($text, sprintf('s:%d:"%d"', strlen((string) $id), $id));
    }

    /** JSON "id":123 or "id":"123" with a digit boundary. */
    public static function hasJsonId(string $text, int $id): bool
    {
        return (bool) preg_match('/"id":\s*"?' . $id . '(?!\d)/', $text);
    }

    /**
     * The ID as a whole double-quoted value — "123" — anywhere in a string:
     * a shortcode attribute, a data- attribute, a JSON string leaf in markup
     * that is not itself decodable. The quotes are the boundary, so "1234"
     * cannot satisfy 123. This is the verifier for the '%"123"%' condition
     * idConditions() binds; single quotes are deliberately not matched here,
     * because no condition there admits them.
     */
    public static function hasQuotedId(string $text, int $id): bool
    {
        return str_contains($text, '"' . $id . '"');
    }

    /**
     * Recursively search an unserialized/decoded structure for the ID or a
     * basename. A string leaf that is itself a JSON object/array is decoded and
     * searched too — settings blobs keep IDs under arbitrary keys, and
     * serialized theme mods nest JSON strings.
     *
     * A stored value is not guaranteed to be a tree. Serialized reference
     * tokens (r:/R:) unserialize into a graph that points back at itself, and
     * walking one of those without a guard never returns — it climbs until the
     * process runs out of memory, with no chance for the scan time-box to
     * interrupt it. The walk is bounded three ways, in order of precision:
     * object identity, a path marker on arrays, and a hard depth cap.
     *
     * @param string[] $basenames
     */
    public static function structureContains(mixed $value, int $id, array $basenames): bool
    {
        $seenObjects = [];

        return self::searchStructure($value, $id, $basenames, $seenObjects, 0);
    }

    /**
     * The bounded walk behind structureContains().
     *
     * $value is taken by reference so a cyclic array can be marked in place:
     * a reference cycle is only visible if the array the cycle points back at
     * is the one carrying the mark, and a copy would not be. Marking costs a
     * copy-on-write separation per array, so it only starts at
     * CYCLE_WATCH_DEPTH — stored data nests shallowly, a cycle does not, so the
     * common path stays allocation-free and a cycle is still caught within a
     * few levels of entering it.
     *
     * @param string[]           $basenames
     * @param array<int, true>   $seenObjects Objects already walked, by identity.
     */
    private static function searchStructure(
        mixed &$value,
        int $id,
        array $basenames,
        array &$seenObjects,
        int $depth
    ): bool {
        // The only inexact bound, and the only one that can be reached by data
        // that is merely deep rather than cyclic. It answers "used": keeping a
        // file we cannot resolve is recoverable, deleting a used one is not.
        if ($depth > self::MAX_STRUCTURE_DEPTH) {
            return true;
        }

        if (is_int($value)) {
            return $value === $id;
        }

        if (is_string($value)) {
            if ($value === (string) $id || self::containsBasename($value, $basenames)) {
                return true;
            }

            $decoded = self::decodeJson($value);

            return $decoded !== null
                && self::searchStructure($decoded, $id, $basenames, $seenObjects, $depth + 1);
        }

        if (is_object($value)) {
            $handle = spl_object_id($value);

            // Walked already: its verdict was false, or it is an ancestor of
            // this frame and still being walked. Either way there is nothing
            // here that the caller is not already looking at.
            if (isset($seenObjects[$handle])) {
                return false;
            }

            $seenObjects[$handle] = true;
            $properties = get_object_vars($value);

            return self::searchStructure($properties, $id, $basenames, $seenObjects, $depth + 1);
        }

        if (!is_array($value)) {
            return false;
        }

        if ($depth < self::CYCLE_WATCH_DEPTH) {
            foreach ($value as $item) {
                if (self::searchStructure($item, $id, $basenames, $seenObjects, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($value[self::CYCLE_MARK])) {
            return false; // On the current path already — this branch is walked.
        }

        $value[self::CYCLE_MARK] = true;

        try {
            foreach (array_keys($value) as $key) {
                if ($key === self::CYCLE_MARK) {
                    continue;
                }

                if (self::searchStructure($value[$key], $id, $basenames, $seenObjects, $depth + 1)) {
                    return true;
                }
            }
        } finally {
            unset($value[self::CYCLE_MARK]);
        }

        return false;
    }

    /**
     * A stored value as a structure: PHP-serialized data is unserialized with
     * classes disabled (meta can be author-written), JSON text is decoded,
     * anything else is returned as the plain string.
     */
    public static function decodeStored(string $value): mixed
    {
        if (is_serialized($value)) {
            $data = @unserialize(trim($value), ['allowed_classes' => false]);

            return $data === false ? $value : $data;
        }

        return self::decodeJson($value) ?? $value;
    }

    /** Decodes a string that is a JSON object or array; null for anything else. */
    public static function decodeJson(string $text): ?array
    {
        $first = ltrim($text)[0] ?? '';

        if ($first !== '{' && $first !== '[') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}

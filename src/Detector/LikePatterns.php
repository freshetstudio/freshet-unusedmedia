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
    /**
     * OR'd SQL conditions matching an attachment ID inside a text column:
     * exact value, comma lists, serialized int/string, JSON "id".
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

    /** Recursively search an unserialized/decoded structure for the ID or a basename. */
    public static function structureContains(mixed $value, int $id, array $basenames): bool
    {
        if (is_int($value)) {
            return $value === $id;
        }

        if (is_string($value)) {
            return $value === (string) $id || self::containsBasename($value, $basenames);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::structureContains($item, $id, $basenames)) {
                    return true;
                }
            }
        }

        if (is_object($value)) {
            return self::structureContains(get_object_vars($value), $id, $basenames);
        }

        return false;
    }
}

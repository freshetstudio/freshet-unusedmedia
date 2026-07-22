<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Cached scan results, stored as postmeta on each attachment.
 */
final class ResultStore
{
    public const META_STATUS = '_freshet_unusedmedia_status';
    public const META_REFS = '_freshet_unusedmedia_refs';
    public const META_SCANNED_AT = '_freshet_unusedmedia_scanned_at';

    public const STATUS_USED = 'used';
    public const STATUS_UNUSED = 'unused';

    private const MAX_STORED_REFS = 20;

    /** @param Reference[] $refs */
    public function save(int $attachmentId, string $status, array $refs): void
    {
        update_post_meta($attachmentId, self::META_STATUS, $status);
        update_post_meta($attachmentId, self::META_REFS, wp_json_encode([
            'count' => count($refs),
            'refs' => array_map(
                static fn(Reference $r): array => $r->toArray(),
                array_slice($refs, 0, self::MAX_STORED_REFS)
            ),
        ]));
        update_post_meta($attachmentId, self::META_SCANNED_AT, time());
    }

    public function status(int $attachmentId): ?string
    {
        $status = (string) get_post_meta($attachmentId, self::META_STATUS, true);

        return in_array($status, [self::STATUS_USED, self::STATUS_UNUSED], true) ? $status : null;
    }

    public function scannedAt(int $attachmentId): int
    {
        return (int) get_post_meta($attachmentId, self::META_SCANNED_AT, true);
    }

    /** @return array{count: int, refs: Reference[]} */
    public function refs(int $attachmentId): array
    {
        $raw = json_decode((string) get_post_meta($attachmentId, self::META_REFS, true), true);

        if (!is_array($raw)) {
            return ['count' => 0, 'refs' => []];
        }

        return [
            'count' => (int) ($raw['count'] ?? 0),
            'refs' => array_map(
                static fn(array $a): Reference => Reference::fromArray($a),
                array_values(array_filter((array) ($raw['refs'] ?? []), 'is_array'))
            ),
        ];
    }

    public function clear(int $attachmentId): void
    {
        delete_post_meta($attachmentId, self::META_STATUS);
        delete_post_meta($attachmentId, self::META_REFS);
        delete_post_meta($attachmentId, self::META_SCANNED_AT);
    }

    /** @return array{used: int, unused: int, unscanned: int, total: int} */
    public function counts(): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- aggregate counts over postmeta; no WP API equivalent.
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status <> 'trash'"
        );

        $used = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND pm.meta_value = %s",
            self::META_STATUS,
            self::STATUS_USED
        ));

        $unused = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND pm.meta_value = %s",
            self::META_STATUS,
            self::STATUS_UNUSED
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery

        return [
            'used' => $used,
            'unused' => $unused,
            'unscanned' => max(0, $total - $used - $unused),
            'total' => $total,
        ];
    }

    /**
     * Paginated list of attachments currently marked unused.
     *
     * @return array{ids: int[], total: int}
     */
    public function unused(int $page, int $perPage): array
    {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => ['inherit', 'private'],
            'posts_per_page' => $perPage,
            'paged' => max(1, $page),
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- core index on meta_key; this is the feature.
            'meta_value' => self::STATUS_UNUSED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ]);

        return [
            'ids' => array_map('intval', $query->posts),
            'total' => (int) $query->found_posts,
        ];
    }

    /** All attachment IDs currently marked unused (for delete-all batching). */
    public function unusedIds(int $limit): array
    {
        global $wpdb;

        // Trash is excluded: already-trashed files are handled by the trash UI,
        // and a second pass here would permanently delete them.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- indexed meta lookup; batch source for delete-all.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' AND pm.meta_value = %s
             ORDER BY p.ID ASC LIMIT %d",
            self::META_STATUS,
            self::STATUS_UNUSED,
            $limit
        )));
    }
}

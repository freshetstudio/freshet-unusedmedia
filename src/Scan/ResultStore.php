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

    /**
     * How many IDs are sized in one pass. Only reached when a size filter is on,
     * and it exists to keep the postmeta cache priming batched rather than to
     * bound the work: the whole narrowed set still gets sized.
     */
    private const SIZE_CHUNK = 200;

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
    public function unused(int $page, int $perPage, ?ResultFilters $filters = null): array
    {
        return $this->byStatus(self::STATUS_UNUSED, $page, $perPage, $filters);
    }

    /**
     * Paginated list of attachments carrying a given scan status. One query,
     * one paging rule, whichever half of the library is being read — so the
     * used set can never be paged differently from the unused one.
     *
     * Date and filename narrow the query itself. A size bound cannot: no column
     * records a file's size, so when one is set the whole narrowed set is sized
     * in PHP and paged from the result — the alternative, filtering the visible
     * page only, would report a total the screen and the delete-all set both
     * disagreed with.
     *
     * @return array{ids: int[], total: int}
     */
    public function byStatus(string $status, int $page, int $perPage, ?ResultFilters $filters = null): array
    {
        $filters ??= ResultFilters::none();
        $page = max(1, $page);

        if ($filters->hasSizeFilter()) {
            $ids = $this->sizedIds($status, $filters);

            return [
                'ids' => array_slice($ids, ($page - 1) * $perPage, $perPage),
                'total' => count($ids),
            ];
        }

        $query = new \WP_Query($this->queryArgs($status, $filters) + [
            'posts_per_page' => $perPage,
            'paged' => $page,
        ]);

        return [
            'ids' => array_map('intval', $query->posts),
            'total' => (int) $query->found_posts,
        ];
    }

    /**
     * One batch of attachment IDs for the delete-all loop, honouring whatever
     * the screen was filtered to — a loop that ignored the filter would delete
     * files the user was never shown.
     *
     * Without a size filter there is no cursor and none is needed: every ID the
     * batch touches leaves the unused pool (deleted, re-scanned as used, or
     * cleared), so the front of the list always moves and the loop terminates.
     * With one, the files that fail the size test stay in the pool and would be
     * re-sized on every batch — hence `cursor`, which the caller passes back as
     * `$after` so the walk costs one pass over the set rather than one per batch.
     *
     * @return array{ids: int[], cursor: int}
     */
    public function unusedIds(int $limit, ?ResultFilters $filters = null, int $after = 0): array
    {
        $filters ??= ResultFilters::none();

        if (!$filters->hasSizeFilter()) {
            return ['ids' => $this->idsAfter(self::STATUS_UNUSED, $filters, 0, $limit), 'cursor' => 0];
        }

        $found = [];
        $cursor = $after;

        while (count($found) < $limit) {
            $chunk = $this->idsAfter(self::STATUS_UNUSED, $filters, $cursor, self::SIZE_CHUNK);

            if ($chunk === []) {
                break;
            }

            update_postmeta_cache($chunk);

            // The cursor moves per ID rather than per chunk: it has to name the
            // last file *examined*, or breaking out mid-chunk would step the
            // next batch over everything after it.
            foreach ($chunk as $id) {
                $cursor = $id;

                if ($filters->matchesSize($id)) {
                    $found[] = $id;

                    if (count($found) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return ['ids' => $found, 'cursor' => $cursor];
    }

    /**
     * The shared query: one status, plus whatever date and filename the filter
     * asked for. Trash is excluded by the status allow-list — already-trashed
     * files are the trash UI's business, and a second delete pass over them
     * would erase them permanently.
     *
     * @return array<string, mixed>
     */
    private function queryArgs(string $status, ResultFilters $filters): array
    {
        $meta = [['key' => self::META_STATUS, 'value' => $status]];

        if ($filters->filename !== '') {
            // The stored path, which is what the File column shows underneath
            // the title — matching the title instead would filter on a label
            // the user can rename without touching the file.
            $meta[] = [
                'key' => '_wp_attached_file',
                'value' => $filters->filename,
                'compare' => 'LIKE',
            ];
        }

        $args = [
            'post_type' => 'attachment',
            'post_status' => ['inherit', 'private'],
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- core index on meta_key; this is the feature.
        ];

        $dates = $filters->dateQuery();

        if ($dates !== []) {
            $args['date_query'] = [$dates];
        }

        return $args;
    }

    /**
     * IDs in ascending order starting after a given one. The cursor is a
     * posts_where clause because WP_Query has no argument for it; it is added
     * and removed around this one query so nothing else on the request sees it.
     *
     * @return int[]
     */
    private function idsAfter(string $status, ResultFilters $filters, int $after, int $limit): array
    {
        global $wpdb;

        $cursor = static fn(string $where): string => $after > 0
            ? $where . $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after)
            : $where;

        add_filter('posts_where', $cursor);

        $query = new \WP_Query($this->queryArgs($status, $filters) + [
            'posts_per_page' => $limit,
            'paged' => 1,
            'no_found_rows' => true,
        ]);

        remove_filter('posts_where', $cursor);

        return array_map('intval', $query->posts);
    }

    /**
     * Every ID matching the status, the date and the filename, then sized one by
     * one against the size bounds. Priming the postmeta cache in chunks is what
     * keeps this one query per SIZE_CHUNK rather than one per attachment.
     *
     * @return int[]
     */
    private function sizedIds(string $status, ResultFilters $filters): array
    {
        $query = new \WP_Query($this->queryArgs($status, $filters) + [
            'posts_per_page' => -1,
            'no_found_rows' => true,
        ]);

        $matched = [];

        foreach (array_chunk(array_map('intval', $query->posts), self::SIZE_CHUNK) as $chunk) {
            update_postmeta_cache($chunk);

            foreach ($chunk as $id) {
                if ($filters->matchesSize($id)) {
                    $matched[] = $id;
                }
            }
        }

        return $matched;
    }
}

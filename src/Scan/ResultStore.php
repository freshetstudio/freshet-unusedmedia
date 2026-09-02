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

    /**
     * How many *files* the library holds, and what the last scan made of them.
     *
     * Files, not attachment rows: several rows can point at one path, and the
     * saving this plugin is sold on is claimed against disk. See FileGroups.
     *
     * @return array{used: int, unused: int, unscanned: int, total: int}
     */
    public function counts(): array
    {
        global $wpdb;

        $counts = [self::STATUS_USED => 0, self::STATUS_UNUSED => 0, FileGroups::STATUS_UNSCANNED => 0];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate over the grouped subquery; no WP API groups on _wp_attached_file.
        $rows = (array) $wpdb->get_results($this->prepared(
            FileGroups::subquery(),
            'SELECT fg.fg_status AS status, COUNT(*) AS files FROM ({{groups}}) fg GROUP BY fg.fg_status'
        ), ARRAY_A);

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');

            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int) ($row['files'] ?? 0);
            }
        }

        return [
            'used' => $counts[self::STATUS_USED],
            'unused' => $counts[self::STATUS_UNUSED],
            'unscanned' => $counts[FileGroups::STATUS_UNSCANNED],
            'total' => array_sum($counts),
        ];
    }

    /**
     * Paginated list of the files currently unused.
     *
     * @return array{ids: int[], total: int}
     */
    public function unused(int $page, int $perPage, ?ResultFilters $filters = null): array
    {
        return $this->byStatus(self::STATUS_UNUSED, $page, $perPage, $filters);
    }

    /**
     * Paginated list of the files carrying a given scan status. One query, one
     * paging rule, whichever half of the library is being read — so the used set
     * can never be paged differently from the unused one, and neither can be
     * paged differently from what counts() counted: both wrap the same grouped
     * subquery, so a disagreement between the heading and the list is not
     * something the code can express.
     *
     * The IDs are representatives — one attachment row standing for one file.
     * Everything downstream reads them that way: the table shows one row per
     * file, the space totals size each file once, and the delete loop expands a
     * representative back to every row on its path before touching anything.
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
        global $wpdb;

        $filters ??= ResultFilters::none();
        $page = max(1, $page);

        if ($filters->hasSizeFilter()) {
            $ids = $this->sizedIds($status, $filters);

            return [
                'ids' => array_slice($ids, ($page - 1) * $perPage, $perPage),
                'total' => count($ids),
            ];
        }

        $groups = FileGroups::subquery($status, $filters);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- the grouped subquery; no WP API groups on _wp_attached_file.
        $ids = array_map('intval', (array) $wpdb->get_col($this->prepared(
            $groups,
            'SELECT fg.fg_id FROM ({{groups}}) fg ORDER BY fg.fg_id ASC LIMIT %d OFFSET %d',
            [$perPage, ($page - 1) * $perPage]
        )));

        $total = (int) $wpdb->get_var($this->prepared(
            $groups,
            'SELECT COUNT(*) FROM ({{groups}}) fg'
        ));
        // phpcs:enable WordPress.DB.DirectDatabaseQuery

        return ['ids' => $ids, 'total' => $total];
    }

    /**
     * One batch of files for the delete-all loop, honouring whatever the screen
     * was filtered to — a loop that ignored the filter would delete files the
     * user was never shown.
     *
     * Without a size filter there is no cursor and none is needed: every file
     * the batch touches leaves the unused pool (deleted, re-scanned as used, or
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

            // The cursor moves per file rather than per chunk: it has to name
            // the last file *examined*, or breaking out mid-chunk would step the
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
     * One query around the grouped subquery. The subquery's own placeholders
     * come first in the string, so its parameters lead the list.
     *
     * @param array{sql: string, params: array<int, string>} $groups
     * @param array<int, int|string> $params
     */
    private function prepared(array $groups, string $wrapper, array $params = []): string
    {
        global $wpdb;

        $sql = str_replace('{{groups}}', $groups['sql'], $wrapper);
        $params = array_merge($groups['params'], $params);

        return $params === [] ? $sql : $wpdb->prepare($sql, $params);
    }

    /**
     * Representative IDs in ascending order starting after a given one. The
     * cursor is a plain WHERE on the grouped set rather than a filter hook,
     * because the set is a subquery here and not a WP_Query.
     *
     * @return int[]
     */
    private function idsAfter(string $status, ResultFilters $filters, int $after, int $limit): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the grouped subquery; no WP API groups on _wp_attached_file.
        return array_map('intval', (array) $wpdb->get_col($this->prepared(
            FileGroups::subquery($status, $filters),
            'SELECT fg.fg_id FROM ({{groups}}) fg WHERE fg.fg_id > %d ORDER BY fg.fg_id ASC LIMIT %d',
            [$after, $limit]
        )));
    }

    /**
     * Every file matching the status, the date and the filename, then sized one
     * by one against the size bounds. Priming the postmeta cache in chunks is
     * what keeps this one query per SIZE_CHUNK rather than one per file.
     *
     * @return int[]
     */
    private function sizedIds(string $status, ResultFilters $filters): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the grouped subquery; no WP API groups on _wp_attached_file.
        $ids = array_map('intval', (array) $wpdb->get_col($this->prepared(
            FileGroups::subquery($status, $filters),
            'SELECT fg.fg_id FROM ({{groups}}) fg ORDER BY fg.fg_id ASC'
        )));

        $matched = [];

        foreach (array_chunk($ids, self::SIZE_CHUNK) as $chunk) {
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

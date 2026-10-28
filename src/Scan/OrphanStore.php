<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Where the scan puts the size files it found with no original, one row each.
 *
 * **A table rather than the option it replaces, and the reason is the count.**
 * The option held at most a hundred paths a directory and five hundred in all,
 * with no sizes, because it was read on every render of the screen and had to
 * stay small. That was enough for a list to look at and not enough for anything
 * else: a library with 465 of them could not export 465 rows, put a byte figure
 * on them or say which ones a page still uses. A chunked option was the other
 * candidate and loses on every count that matters here — the listing has to be
 * paged, sorted by size and filtered by whether content names the file, and all
 * three are an `ORDER BY` / `LIMIT` / `WHERE` on a table and a whole-set load
 * through PHP on an option.
 *
 * **One short row per file, never a structure per attachment.** The scan writes
 * a directory's finding as it leaves the directory, and nothing is held between
 * directories but the rows already on disk — the memory discipline
 * BasenameVocabulary's docblock records the 126 MB fatal behind.
 *
 * **`referenced` is three-valued on purpose.** NULL is "not checked yet": the
 * content check runs once per scan, after the file pass has found everything
 * (OrphanReferences), so a row written mid-scan has no answer, and an unchecked
 * row reading as "referenced nowhere" is the one mistake this table must not
 * make.
 *
 * Every read goes through `Db`, so a read that did not answer raises rather than
 * coming back as an empty library (freshet-141).
 */
final class OrphanStore
{
    /** Rows per INSERT, and per page of an export. */
    private const CHUNK = 500;

    public const FILTER_ALL = 'all';
    public const FILTER_REFERENCED = 'referenced';
    public const FILTER_UNREFERENCED = 'unreferenced';

    /** Whether this request has made sure of the table. */
    private static bool $installed = false;

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'freshet_unusedmedia_orphans';
    }

    /**
     * Create the table if it is not there — on activation, and at the start of
     * every scan, for the site that was updated rather than activated. The same
     * two paths and the same `CREATE TABLE IF NOT EXISTS` as ReferenceIndex, for
     * the same reason: the table holds what one scan found, and the next scan
     * produces it again.
     */
    public static function install(): void
    {
        global $wpdb;

        $table = self::table();
        $collate = $wpdb->get_charset_collate();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- DDL takes no placeholders; the table name is this plugin's own, built from $wpdb->prefix.
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                dir varchar(255) NOT NULL DEFAULT '',
                file varchar(255) NOT NULL DEFAULT '',
                bytes bigint(20) unsigned NOT NULL DEFAULT 0,
                referenced tinyint(1) DEFAULT NULL,
                post_ids text NULL,
                PRIMARY KEY (id),
                KEY dir (dir(191)),
                KEY bytes (bytes)
            ) {$collate}"
        );

        Db::assertAnswered('orphan table');
    }

    /** Whether the table is there. A site updated and not yet re-scanned has none. */
    public static function exists(): bool
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- SHOW TABLES LIKE, value bound via prepare().
        return Db::value('orphan table', $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) === $table;
    }

    /** Empty it for a new scan. DELETE rather than TRUNCATE: see ReferenceIndex::truncate(). */
    public static function clear(): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own, built from $wpdb->prefix.
        $wpdb->query("DELETE FROM {$table}");

        Db::assertAnswered('orphan reset');
    }

    /** Drop it, at uninstall. */
    public static function drop(): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own, built from $wpdb->prefix.
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    /**
     * One directory's finding, replacing whatever it said before — a directory
     * that spans two batches is examined in both, and must record the same
     * answer twice rather than accumulate it.
     *
     * @param array<string, int> $files Basename => bytes.
     */
    public static function replaceDirectory(string $dir, array $files): void
    {
        global $wpdb;

        // A scan started before the table existed and resumed after an update
        // reaches this without having passed reset(). Asked once a request.
        if (!self::$installed) {
            self::install();
            self::$installed = true;
        }

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; the value is bound via prepare().
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE dir = %s", $dir));

        Db::assertAnswered('orphan write');

        foreach (array_chunk($files, self::CHUNK, true) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $file => $bytes) {
                $values[] = '(%s, %s, %d)';
                array_push($params, $dir, (string) $file, max(0, (int) $bytes));
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, every value bound via prepare().
            $wpdb->query($wpdb->prepare("INSERT INTO {$table} (dir, file, bytes) VALUES " . implode(', ', $values), ...$params));

            Db::assertAnswered('orphan write');
        }
    }

    /**
     * The figures the screen prints: how many, how large, and how many content
     * names and does not. `unchecked` is the rows the content check has not
     * reached, which is zero once a scan has finished.
     *
     * @return array{count: int, bytes: int, referenced: int, unreferenced: int, unchecked: int}
     */
    public static function summary(): array
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own, built from $wpdb->prefix; no input.
        $row = (array) Db::value('orphan summary', $wpdb->get_row(
            "SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes,
                    COALESCE(SUM(referenced = 1), 0) AS referenced,
                    COALESCE(SUM(referenced = 0), 0) AS unreferenced
             FROM {$table}",
            ARRAY_A
        ));

        $count = (int) ($row['files'] ?? 0);
        $referenced = (int) ($row['referenced'] ?? 0);
        $unreferenced = (int) ($row['unreferenced'] ?? 0);

        return [
            'count' => $count,
            'bytes' => (int) ($row['bytes'] ?? 0),
            'referenced' => $referenced,
            'unreferenced' => $unreferenced,
            'unchecked' => max(0, $count - $referenced - $unreferenced),
        ];
    }

    /**
     * Every row, a page at a time, in id order, handed to $each. What the export
     * streams from, so a library of any size costs one page of memory.
     *
     * @param callable(array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}): void $each
     */
    public static function each(callable $each): void
    {
        $after = 0;

        do {
            $rows = self::rowsAfter($after, self::CHUNK);

            foreach ($rows as $row) {
                $each($row);
                $after = $row['id'];
            }
        } while (count($rows) === self::CHUNK);
    }

    /**
     * One page of the listing, filtered and sorted by size.
     *
     * @return array{rows: list<array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}>, total: int}
     */
    public static function page(string $filter, bool $descending, int $page, int $perPage): array
    {
        global $wpdb;

        $table = self::table();
        $where = match ($filter) {
            self::FILTER_REFERENCED => 'WHERE referenced = 1',
            self::FILTER_UNREFERENCED => 'WHERE referenced = 0',
            default => '',
        };
        $order = $descending ? 'DESC' : 'ASC';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; the filter is one of three literals.
        $total = (int) Db::value('orphan listing', $wpdb->get_var("SELECT COUNT(*) FROM {$table} {$where}"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; filter and order are literals, the window is bound via prepare().
        $rows = Db::rows('orphan listing', $wpdb->get_results($wpdb->prepare(
            "SELECT id, dir, file, bytes, referenced, post_ids FROM {$table} {$where} ORDER BY bytes {$order}, id ASC LIMIT %d OFFSET %d",
            max(1, $perPage),
            max(0, ($page - 1) * $perPage)
        ), ARRAY_A));

        return ['rows' => array_map([self::class, 'shape'], $rows), 'total' => $total];
    }

    /**
     * One row by id, or null when it is not there — the delete path's way in,
     * so a request names a row and never a path.
     *
     * @return array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}|null
     */
    public static function find(int $id): ?array
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; the id is bound via prepare().
        $row = Db::value('orphan row', $wpdb->get_row($wpdb->prepare(
            "SELECT id, dir, file, bytes, referenced, post_ids FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A));

        return is_array($row) ? self::shape($row) : null;
    }

    /** Forget one row, once its file is gone. */
    public static function remove(int $id): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; the id is bound via prepare().
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id = %d", $id));
    }

    /**
     * Rows the content check has not reached, oldest first.
     *
     * @return list<array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}>
     */
    public static function unchecked(int $limit): array
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; the limit is bound via prepare().
        $rows = Db::rows('orphan check', $wpdb->get_results($wpdb->prepare(
            "SELECT id, dir, file, bytes, referenced, post_ids FROM {$table} WHERE referenced IS NULL ORDER BY id ASC LIMIT %d",
            max(1, $limit)
        ), ARRAY_A));

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * Write the content check's answer for one row.
     *
     * @param int[] $postIds
     */
    public static function resolve(int $id, array $postIds): void
    {
        global $wpdb;

        $table = self::table();
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        sort($postIds);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; every value bound via prepare().
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET referenced = %d, post_ids = %s WHERE id = %d",
            $postIds === [] ? 0 : 1,
            implode(' ', $postIds),
            $id
        ));

        Db::assertAnswered('orphan check');
    }

    /**
     * @return list<array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}>
     */
    private static function rowsAfter(int $after, int $limit): array
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; cursor and limit bound via prepare().
        $rows = Db::rows('orphan export', $wpdb->get_results($wpdb->prepare(
            "SELECT id, dir, file, bytes, referenced, post_ids FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $after,
            $limit
        ), ARRAY_A));

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, path: string, dir: string, file: string, bytes: int, referenced: ?bool, post_ids: int[]}
     */
    private static function shape(array $row): array
    {
        $dir = (string) ($row['dir'] ?? '');
        $file = (string) ($row['file'] ?? '');
        $referenced = $row['referenced'] ?? null;
        $ids = trim((string) ($row['post_ids'] ?? ''));

        return [
            'id' => (int) ($row['id'] ?? 0),
            'path' => $dir === '' ? $file : $dir . '/' . $file,
            'dir' => $dir,
            'file' => $file,
            'bytes' => (int) ($row['bytes'] ?? 0),
            'referenced' => $referenced === null || $referenced === '' ? null : (bool) (int) $referenced,
            'post_ids' => $ids === '' ? [] : array_map('intval', explode(' ', $ids)),
        ];
    }
}

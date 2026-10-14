<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Where the one pass over the corpus puts what it found, and where a per-file
 * lookup reads it back.
 *
 * **What the table is for.** The index itself fits in memory on every library on
 * hand — §2c of `_unusedmedia-1-1.md` measured the payload at 0.02–0.58 MB — so
 * this is not about size. It is about **durability and resumability**: a browser
 * scan is a chain of requests a person can stop, a host's
 * `max_execution_time` can cut a pass in half, and a pass that lived in a PHP
 * array would start again from nothing every time either happened.
 *
 * **One row is one `Reference`, already verified.** The pass had the row and the
 * attachment in hand at the moment it extracted the candidate, so it ran the
 * detector's own chain there and stored the answer. That is the whole reason the
 * evidence panel reads the same thing after this change as before it: the match
 * type and confidence in these columns were produced by
 * `PostmetaDetector::references()` and its seven siblings, not by anything here.
 *
 * `source_table` and `row_pk` are not read by a lookup. They are what makes a
 * row traceable back to the value it was found in — the thing a disagreement is
 * diagnosed from, and the thing a future incremental re-index would delete by.
 *
 * **A read that does not answer must not read as "no references".** Every query
 * here goes through `Db`, so a failed index read raises `QueryFailed` and
 * `Scanner` turns it into no verdict at all rather than into *unused*
 * (freshet-141). Silence and emptiness are the same bytes out of `$wpdb`, and
 * this table is now the thing that decides whether a file is offered for
 * deletion.
 *
 * **The memo is primed per batch and never outlives it** — the same discipline
 * as `FileGroups::prime()`, for the same reason: rows change, and a memo
 * outliving the rows it describes is a wrong verdict rather than a slow one.
 */
final class ReferenceIndex
{
    /** Rows buffered before one multi-row INSERT is issued. */
    private const WRITE_CHUNK = 500;

    /**
     * Attachment ids per `prime()` read. A batch is 10 (browser) or 100 (CLI),
     * so this is only ever reached by a caller priming something larger.
     */
    private const PRIME_CHUNK = 500;

    /** Pending rows, as the ordered value lists the INSERT binds. */
    private static array $pending = [];

    /**
     * attachment id => detector id => compact Reference arrays.
     *
     * @var array<int, array<string, array<int, array<string, mixed>>>>
     */
    private static array $rows = [];

    /**
     * Attachment ids this request has read the index for — including ones with
     * no rows, which is the half that matters: "primed and empty" is an answer
     * and "not primed" is not.
     *
     * @var array<int, true>
     */
    private static array $primed = [];

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'freshet_unusedmedia_refs';
    }

    /**
     * Create the table if it is not there.
     *
     * Called on activation, and again at the top of a pass: a site that was
     * updated rather than activated never fires the activation hook, and a scan
     * that found no table would otherwise index into nothing and report success.
     *
     * `CREATE TABLE IF NOT EXISTS` rather than `dbDelta()`, deliberately. This
     * table holds no content — it is what one pass over the corpus found, and a
     * pass can always be run again — so there is nothing here a migration would
     * have to preserve, and the honest answer to a future schema change is to
     * drop it and re-index. `dbDelta()` also lives in `wp-admin/includes/`, which
     * neither the front end nor WP-CLI has loaded.
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
                attachment_id bigint(20) unsigned NOT NULL,
                source_table varchar(32) NOT NULL DEFAULT '',
                row_pk bigint(20) unsigned NOT NULL DEFAULT 0,
                meta_key varchar(191) NOT NULL DEFAULT '',
                detector varchar(32) NOT NULL DEFAULT '',
                object_type varchar(16) NOT NULL DEFAULT 'post',
                object_id bigint(20) unsigned NOT NULL DEFAULT 0,
                match_type varchar(32) NOT NULL DEFAULT '',
                confidence varchar(16) NOT NULL DEFAULT 'possible',
                PRIMARY KEY (id),
                KEY attachment (attachment_id),
                KEY source_row (source_table, row_pk)
            ) {$collate}"
        );

        Db::assertAnswered('index table');
    }

    /** Whether the table is actually there. */
    public static function exists(): bool
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- SHOW TABLES LIKE, value bound below.
        $found = Db::value('index table', $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));

        return $found === $table;
    }

    /**
     * Empty it, for a pass that is starting over rather than resuming.
     *
     * `DELETE FROM` and not `TRUNCATE TABLE`, and that is the difference between
     * a scan that starts and one that cannot. MySQL implements `TRUNCATE` as a
     * drop-and-recreate, so it requires the **DROP** privilege — a privilege
     * nothing else in this plugin needs and plenty of managed hosts do not grant
     * on a site's own database. The shape of the failure is what makes it worth
     * this paragraph: `CREATE TABLE IF NOT EXISTS` above succeeds, so the plugin
     * installs, activates and renders its screen, and then the first "Start
     * scan" — the one request that resets the index — comes back
     * `ERROR 1142: DROP command denied`, every time, for ever.
     *
     * Measured rather than reasoned (freshet-406): a user granted
     * SELECT/INSERT/UPDATE/DELETE/CREATE/INDEX/ALTER/LOCK TABLES and no DROP
     * creates this table and writes to it, is refused the `TRUNCATE`, and runs
     * the `DELETE` below without complaint.
     *
     * What `TRUNCATE` gave up with it is the AUTO_INCREMENT reset, and nothing
     * reads `id` — it exists to give the rows a primary key — so a counter that
     * keeps climbing across passes costs a bigint nobody looks at.
     */
    public static function truncate(): void
    {
        global $wpdb;

        self::$pending = [];
        self::flush();

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own, built from $wpdb->prefix.
        $wpdb->query("DELETE FROM {$table}");

        Db::assertAnswered('index reset');
    }

    /**
     * Forget everything one read wrote past a primary key.
     *
     * What an interrupted page leaves behind. The cursor only moves once a page
     * is fully committed, so any row of that read beyond the cursor came from a
     * page that was cut short — and a resume is about to read those same rows
     * again. Without this the second reading would add a duplicate of every
     * reference the first one wrote, which is two evidence lines where there is
     * one reference.
     *
     * Called once when a read is entered, not once per page: inside a page the
     * writes are buffered and the cursor follows them.
     */
    public static function forgetAfter(string $sourceTable, int $afterPk): void
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; both values bound via prepare().
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE source_table = %s AND row_pk > %d",
            $sourceTable,
            $afterPk
        ));

        Db::assertAnswered('index rewind');
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
     * Record one verified reference.
     *
     * Buffered: a pass produces tens of thousands of these and one INSERT each
     * would be most of its wall clock. `commit()` is what actually writes, and
     * the pass calls it before it advances its cursor — so a row is never on
     * disk behind a cursor that has already moved past it.
     *
     * @param string $sourceTable The read that found it, as the pass names it.
     * @param int    $rowPk       That table's primary key for the row.
     */
    public static function add(int $attachmentId, string $sourceTable, int $rowPk, Reference $reference): void
    {
        self::$pending[] = [
            $attachmentId,
            $sourceTable,
            $rowPk,
            $reference->detail,
            $reference->detector,
            $reference->objectType,
            $reference->objectId,
            $reference->match,
            $reference->confidence,
        ];

        if (count(self::$pending) >= self::WRITE_CHUNK) {
            self::commit();
        }
    }

    /** How many rows are waiting to be written. */
    public static function pending(): int
    {
        return count(self::$pending);
    }

    /**
     * Write whatever is buffered. One multi-row INSERT per chunk.
     *
     * @return int Rows written.
     */
    public static function commit(): int
    {
        global $wpdb;

        if (self::$pending === []) {
            return 0;
        }

        $table = self::table();
        $written = 0;

        foreach (array_chunk(self::$pending, self::WRITE_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(%d,%s,%d,%s,%s,%s,%d,%s,%s)'));
            $params = array_merge(...$chunk);

            $sql = "INSERT INTO {$table}
                        (attachment_id,source_table,row_pk,meta_key,detector,object_type,object_id,match_type,confidence)
                    VALUES {$placeholders}";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, every value bound via prepare().
            $wpdb->query($wpdb->prepare($sql, ...$params));

            Db::assertAnswered('index write');

            $written += count($chunk);
        }

        self::$pending = [];

        return $written;
    }

    /** How many rows the index holds. */
    public static function size(): int
    {
        global $wpdb;

        $table = self::table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is this plugin's own; no user input.
        return (int) Db::value('index size', $wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
    }

    /**
     * Read the index for a batch of attachments in one query.
     *
     * The twin of FileGroups::prime(), and it has the same lifetime: a batch.
     * flush() is called when the batch's cursor has been written past.
     *
     * @param int[] $attachmentIds
     * @throws QueryFailed A read that did not answer; see the class note.
     */
    public static function prime(array $attachmentIds): void
    {
        global $wpdb;

        $wanted = [];

        foreach ($attachmentIds as $attachmentId) {
            $attachmentId = (int) $attachmentId;

            if ($attachmentId > 0 && !isset(self::$primed[$attachmentId])) {
                $wanted[$attachmentId] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        $table = self::table();

        foreach (array_chunk(array_keys($wanted), self::PRIME_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));

            $sql = "SELECT attachment_id, detector, meta_key, object_type, object_id, match_type, confidence
                    FROM {$table}
                    WHERE attachment_id IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, every value bound via prepare().
            $rows = Db::rows('index lookup', $wpdb->get_results($wpdb->prepare($sql, ...$chunk)));

            // Marked primed only once the read has answered. A failure leaves
            // these ids unprimed, so a later lookup asks again rather than
            // reading the absence of rows as the absence of references.
            foreach ($chunk as $attachmentId) {
                self::$primed[(int) $attachmentId] = true;
            }

            foreach ($rows as $row) {
                self::$rows[(int) $row->attachment_id][(string) $row->detector][] = [
                    'd' => (string) $row->detector,
                    't' => (string) $row->object_type,
                    'i' => (int) $row->object_id,
                    'k' => (string) $row->meta_key,
                    'm' => (string) $row->match_type,
                    'c' => (string) $row->confidence,
                ];
            }
        }
    }

    /**
     * What the index holds for one attachment under one detector.
     *
     * Primes for this attachment alone where a batch did not — the single
     * "Check usage" button and the delete path's re-verification both scan one
     * row at a time.
     *
     * @return Reference[]
     * @throws QueryFailed A read that did not answer; see the class note.
     */
    public static function references(int $attachmentId, string $detectorId): array
    {
        if (!isset(self::$primed[$attachmentId])) {
            self::prime([$attachmentId]);
        }

        $refs = [];

        foreach (self::$rows[$attachmentId][$detectorId] ?? [] as $stored) {
            $refs[] = Reference::fromArray($stored);
        }

        return $refs;
    }

    /** Drop the in-memory copy, once the batch that primed it is behind us. */
    public static function flush(): void
    {
        self::$rows = [];
        self::$primed = [];
    }
}

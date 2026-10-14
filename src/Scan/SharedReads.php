<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * One sibling group's broad passes, issued once for the group instead of once
 * per row.
 *
 * **What is shared, and why it is safe to share it.** A sibling group is every
 * attachment row standing on one `_wp_attached_file` (FileGroups), so the rows
 * of a group name the same file. Their detector queries are a broad `LIKE` pass
 * over a whole table on two kinds of needle: the row's **id**, which differs
 * across the group, and the row's **basenames**, which barely do — they are cut
 * from the shared path, from metadata describing the same upload, and from a
 * disk read of the one directory the path names. Every row of the group was
 * therefore asking the same expensive question about the file and a cheap
 * different one about itself, once each, ten times over on a library where a
 * file averages ten rows.
 *
 * So the pass is issued once with the **union** of the group's ids and the
 * **union** of its basenames. A union can only widen what the broad pass
 * fetches, and widening a broad pass cannot change an answer: the SQL selects
 * candidates and the PHP verification decides, per row, against that row's own
 * id and its own basenames (freshet-155). What it must not do is *narrow* —
 * which is why the union is taken over every member's basenames rather than
 * assumed from the shared path. Two rows on one file can carry different
 * `_wp_attachment_metadata`, and a row whose own size file was left out of the
 * needles is a row whose reference is not fetched and whose file is then
 * offered for deletion.
 *
 * **Every row still gets its own verdict.** Nothing here answers for a row.
 * Detectors read the shared candidate rows and verify each one against
 * `$ctx->id` and `$ctx->basenames`, so two rows of one group reach different
 * statuses exactly as they did before — which they must, because
 * `FileGroups::verdict()` counts them individually and one used row keeps the
 * whole file.
 *
 * **A shared read that fails, fails every row behind it.** The memo holds the
 * QueryFailed as well as the rows, and re-throws it for every later row of the
 * group. A cached failure that decayed into a cached empty set would read as
 * "no references found" for nine rows out of ten, which is freshet-141's
 * refusal-to-answer turned back into the silent "unused" this plugin spent a
 * week closing.
 *
 * **Lifetime: one call to Scanner::scanGroup(), and never longer.** open() is
 * called there and close() runs in its `finally`, so nothing here outlives the
 * group it was read for — a narrower bound than FileGroups' request-scoped memo
 * and for the same reason: rows change, and a memo outliving the rows it
 * describes is a wrong verdict rather than a slow one. Within that window the
 * only writer is Scanner itself, and it writes `_freshet_unusedmedia_*` keys
 * that every detector query already excludes, so no row of the group can act on
 * an entry read before something it depends on changed. A row that is not in
 * the open group does not read the memo at all (AttachmentContext sets
 * `shared` false for it), so a scan of an unrelated attachment cannot pick up
 * another group's rows.
 */
final class SharedReads
{
    /**
     * Rows of one group whose needles may be asked for in a single query.
     *
     * The bound is on the size of the SQL rather than on the work: the group
     * pass evaluates the same number of `LIKE` conditions per table row that
     * the per-row passes evaluated between them, so it is never the more
     * expensive of the two however large the group — but the query *text* grows
     * with the group, and a group is bounded by nothing. Chosen against the
     * libraries on hand rather than picked: the largest real group measured is
     * **45** rows (on a 38,797-row library), against 14, 9, 2 and 2 on four
     * others. A group past this is scanned the way it was before, one row at a
     * time.
     */
    public const MAX_ROWS = 64;

    /** The group the memo belongs to, ascending. Empty when nothing is open. */
    private static array $ids = [];

    /** The union of that group's basenames. */
    private static array $basenames = [];

    /**
     * The broad passes already issued for it: read id => candidate rows.
     *
     * @var array<string, array<int|string, mixed>>
     */
    private static array $rows = [];

    /**
     * The broad passes that did not answer: read id => the refusal to re-throw.
     *
     * @var array<string, QueryFailed>
     */
    private static array $failed = [];

    /**
     * Arm the shared pass for one sibling group.
     *
     * A group of one has nothing to share, and one past MAX_ROWS is left alone;
     * in both cases nothing is opened and every row queries for itself, which is
     * the behaviour this class replaces rather than a fallback that needs its
     * own reading.
     *
     * @param int[] $rowIds Attachment rows standing on one file — every one of
     *                      them on the delete path, the ones inside the current
     *                      batch on the scan path (Scanner::scanGroup()).
     */
    public static function open(array $rowIds): void
    {
        self::close();

        $rowIds = array_values(array_unique(array_map('intval', $rowIds)));

        if (count($rowIds) < 2 || count($rowIds) > self::MAX_ROWS) {
            return;
        }

        // The basenames below are cut from each row's own postmeta. Primed here,
        // that is one read for the group instead of one per row — and it is the
        // same priming FileGroups::fileStatus() does before it reads a group's
        // statuses.
        update_postmeta_cache($rowIds);

        $names = [];

        foreach ($rowIds as $rowId) {
            foreach (AttachmentContext::basenamesFor($rowId) as $name) {
                $names[$name] = true;
            }
        }

        self::$ids = $rowIds;
        self::$basenames = array_keys($names);
    }

    /** Disarm, and drop everything read for the group. */
    public static function close(): void
    {
        self::$ids = [];
        self::$basenames = [];
        self::$rows = [];
        self::$failed = [];
    }

    public static function isOpen(): bool
    {
        return self::$ids !== [];
    }

    /**
     * The open group's rows, ascending.
     *
     * @return int[]
     */
    public static function ids(): array
    {
        return self::$ids;
    }

    /**
     * The union of the open group's basenames.
     *
     * @return string[]
     */
    public static function basenames(): array
    {
        return self::$basenames;
    }

    /**
     * One detector's broad pass, issued once for the group.
     *
     * $fetch is handed the ids and the basenames the pass must bind and returns
     * its candidate rows — it is the detector's own query, unchanged, and it is
     * called exactly once per group when a group is open and once per row when
     * one is not.
     *
     * @param string   $read  Which pass this is; a detector reading two tables
     *                        names them apart.
     * @param callable(int[], string[]): array<int|string, mixed> $fetch
     * @return array<int|string, mixed>
     * @throws QueryFailed The detector's own, raised on the first row of the
     *                     group and re-thrown for every row behind it.
     */
    public static function candidates(string $read, AttachmentContext $ctx, callable $fetch): array
    {
        // Shared, and shared with *this* group. A context outliving the group it
        // was built for would otherwise file its rows under whatever is open now
        // and read them back — the memo is only ever the open group's.
        if (!$ctx->shared || $ctx->queryIds !== self::$ids) {
            return $fetch($ctx->queryIds, $ctx->queryBasenames);
        }

        if (isset(self::$failed[$read])) {
            throw self::$failed[$read];
        }

        if (array_key_exists($read, self::$rows)) {
            return self::$rows[$read];
        }

        try {
            $rows = $fetch($ctx->queryIds, $ctx->queryBasenames);
        } catch (QueryFailed $e) {
            self::$failed[$read] = $e;

            throw $e;
        }

        return self::$rows[$read] = $rows;
    }
}

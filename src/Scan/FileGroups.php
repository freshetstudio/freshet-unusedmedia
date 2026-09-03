<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * The unit of work: one file on disk, not one attachment row.
 *
 * A media library has fewer files than it has attachments. Translation copies,
 * duplicated posts and the same image uploaded twice each get their own row in
 * `wp_posts`, and every one of those rows points at the *same* path in
 * `_wp_attached_file`. Libraries in the wild run from two rows per file to well
 * over twelve, so this is the common case rather than an edge one.
 *
 * Treating a row as a file breaks this plugin in both directions:
 *
 * - **It deletes live files.** Detection is partly ID-based (a featured image,
 *   a custom field holding an ID), so two rows on one file can honestly reach
 *   opposite verdicts. Deleting the row marked unused erases the file the row
 *   marked used still displays — the catastrophic false positive this plugin
 *   exists to avoid.
 * - **It inflates the saving.** Size is a property of the file. Counting it
 *   once per row multiplies the reclaimable figure by the duplication factor.
 *
 * So everything here groups on `_wp_attached_file`, which is WordPress core's
 * own column. Nothing in this class knows or asks which plugin produced the
 * duplicates, and nothing should: whatever made two rows point at one path,
 * they are one file.
 *
 * **Match on basenames, group on paths.** The detectors match basenames,
 * because that is how a reference appears in content. Grouping must not:
 * `2024/01/logo.png` and `2025/06/logo.png` share a basename and are two
 * unrelated files, and merging them would delete a live one from the other
 * side. Every key here is the full stored path, compared whole.
 */
final class FileGroups
{
    /** Core's own record of where an attachment's file lives. The grouping key. */
    public const META_FILE = '_wp_attached_file';

    /** A file nothing has decided about yet — some rows scanned, some not. */
    public const STATUS_UNSCANNED = 'unscanned';

    /** A row reaching its group's verdict on its own terms. */
    public const HELD_NONE = '';

    /** A row kept because another entry on the same path is in use. */
    public const HELD_SIBLING = 'sibling';

    /** A row kept because a copy of its file is in the trash. */
    public const HELD_TRASH = 'trash';

    /**
     * Rows with no `_wp_attached_file` at all have no file to share, so each is
     * its own group. A stored path can never start with this, so the two key
     * spaces cannot collide.
     */
    private const ROW_KEY_PREFIX = '#';

    /**
     * Sibling groups resolved during this request: path => every attachment row
     * standing on it, ascending.
     *
     * A static array, and deliberately not `wp_cache_*`. Group membership stops
     * being true the moment a row is deleted, so a persistent cache would hand a
     * later request rows that are gone — and a stale sibling set is a wrong
     * verdict, which is worse than a slow one. This one dies with the request,
     * and the delete loop drops it as it goes (DeleteController).
     *
     * Only the membership is remembered, never the verdict: statuses are read
     * fresh on every fileStatus() call, so a row moving to the trash is seen at
     * once.
     *
     * @var array<string, int[]>
     */
    private static array $groups = [];

    // ------------------------------------------------------------- the key

    /**
     * The grouping key for one attachment — the full stored path, never a
     * basename. Twin of keySql(); the delete loop reads this one.
     */
    public static function keyFor(int $attachmentId): string
    {
        $file = trim((string) get_post_meta($attachmentId, self::META_FILE, true));

        return $file !== '' ? $file : self::ROW_KEY_PREFIX . $attachmentId;
    }

    /** The same key in SQL. Twin of keyFor(); the counts and the listing read this one. */
    public static function keySql(): string
    {
        return "COALESCE(NULLIF(fgf.meta_value, ''), CONCAT('" . self::ROW_KEY_PREFIX . "', p.ID))";
    }

    /**
     * Every attachment row pointing at this attachment's file, ascending, with
     * the attachment itself always in it.
     *
     * Trashed rows are included on purpose. They are excluded from the unused
     * pool, but they still hold the file: a group with one in it is not
     * deletable, and the delete loop has to be able to see that.
     *
     * The comparison is on the whole path. A LIKE on the basename here is the
     * mirror of the bug this class fixes.
     *
     * @return int[]
     */
    public static function siblings(int $attachmentId): array
    {
        $key = self::keyFor($attachmentId);

        if (str_starts_with($key, self::ROW_KEY_PREFIX)) {
            return [$attachmentId];
        }

        if (!array_key_exists($key, self::$groups)) {
            self::$groups[$key] = self::fetch([$key])[$key] ?? [];
        }

        $ids = self::$groups[$key];

        return in_array($attachmentId, $ids, true) ? $ids : array_merge($ids, [$attachmentId]);
    }

    /**
     * Resolve the sibling groups for a whole page of rows at once.
     *
     * `wp_postmeta` is indexed on `meta_key` and `post_id`, never on
     * `meta_value`, so every sibling lookup scans each attached-file row in the
     * library. One per rendered thumbnail is twenty scans of a five-figure key
     * on a default Media Library page; primed here it is one, however many rows
     * the screen lists.
     *
     * An optimisation and never a precondition. siblings() answers a row nobody
     * primed by querying for it, so every caller behaves identically whether
     * this ran or not — it only decides how many round trips that costs.
     *
     * @param int[] $attachmentIds
     */
    public static function prime(array $attachmentIds): void
    {
        $attachmentIds = array_unique(array_map('intval', $attachmentIds));

        if ($attachmentIds === []) {
            return;
        }

        // keyFor() reads core's postmeta cache, which a list screen's own query
        // has usually primed already; where it has not, this is the one round
        // trip that fetches the paths, and core skips the rows it already holds.
        update_postmeta_cache(array_values($attachmentIds));

        $wanted = [];

        foreach ($attachmentIds as $id) {
            $key = self::keyFor($id);

            if (!str_starts_with($key, self::ROW_KEY_PREFIX) && !array_key_exists($key, self::$groups)) {
                $wanted[$key] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        $wanted = array_keys($wanted);
        $found = self::fetch($wanted);

        foreach ($wanted as $key) {
            // A path that came back with no rows is still an answer, and caching
            // it is what stops the next call querying for it again.
            self::$groups[$key] = $found[$key] ?? [];
        }
    }

    /**
     * Forget every memoised group.
     *
     * The delete loop calls it as it goes: it removes whole groups, so what was
     * remembered about them describes a library that no longer exists.
     */
    public static function flush(): void
    {
        self::$groups = [];
    }

    /**
     * Every attachment row standing on any of these paths, grouped by path,
     * ascending. One query however many paths are asked for.
     *
     * The comparison is on whole paths — an `IN` over equalities, never a LIKE
     * on a basename, which is the mirror of the bug this class fixes.
     *
     * @param string[] $keys
     * @return array<string, int[]>
     */
    private static function fetch(array $keys): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- rows sharing one file; no WP API groups on _wp_attached_file. Placeholders are counted from the key list and every value is prepared.
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS fg_id, pm.meta_value AS fg_key FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND pm.meta_value IN ({$placeholders})
             ORDER BY p.ID ASC",
            array_merge([self::META_FILE], $keys)
        ), ARRAY_A);

        $groups = [];

        foreach ($rows as $row) {
            $groups[(string) $row['fg_key']][] = (int) $row['fg_id'];
        }

        return $groups;
    }

    // --------------------------------------------------------- the verdict

    /**
     * One file's status from its rows. **A file is unused only when every row
     * pointing at it is unused** — one row still in use keeps the whole file,
     * which is the default-closed direction and the one that stops a live file
     * being deleted.
     *
     * A trashed row is neither: it is a deletion someone has already started
     * and can still undo, and its file must survive for that. A group holding
     * one is therefore never unused.
     *
     * statusSql() is this sentence in SQL. The two live side by side so they
     * change together.
     */
    public static function verdict(int $live, int $trash, int $used, int $unused): string
    {
        if ($used > 0) {
            return ResultStore::STATUS_USED;
        }

        if ($live > 0 && $trash === 0 && $unused === $live) {
            return ResultStore::STATUS_UNUSED;
        }

        return self::STATUS_UNSCANNED;
    }

    /** verdict(), transcribed. Read it against the method above, not on its own. */
    public static function statusSql(): string
    {
        return 'CASE'
            . ' WHEN ' . self::scannedSql(ResultStore::STATUS_USED) . ' > 0 THEN ' . self::quote(ResultStore::STATUS_USED)
            . ' WHEN ' . self::liveSql() . ' > 0 AND ' . self::trashSql() . ' = 0'
            . ' AND ' . self::scannedSql(ResultStore::STATUS_UNUSED) . ' = ' . self::liveSql()
            . ' THEN ' . self::quote(ResultStore::STATUS_UNUSED)
            . ' ELSE ' . self::quote(self::STATUS_UNSCANNED)
            . ' END';
    }

    /**
     * One row's file, judged as a file — the verdict a per-row screen has to
     * show, and the rows that decided it.
     *
     * The Media Library lists rows; everything else in this plugin lists files.
     * This is the bridge: it tallies the group the way statusSql() tallies it in
     * SQL and hands the numbers to verdict(), which stays the only place the
     * rule lives. A screen reading the row's own `_freshet_unusedmedia_status`
     * instead draws **Unused** against a file this plugin is deliberately
     * keeping, and offers a deletion the delete loop would refuse.
     *
     * `held` names why a row is not the verdict it would have reached alone, for
     * the surface that has to say so in words: HELD_SIBLING when another entry
     * on the same path is in use, HELD_TRASH when a copy of the file is in the
     * trash and every live row has been scanned. Both are the group's doing
     * rather than the row's, which is exactly what a user cannot see from the
     * row.
     *
     * One sibling lookup per *file*, not per row: siblings() answers from the
     * request's memo after the first call, and prime() resolves a whole screen
     * of them in one query. The postmeta cache for the siblings is primed here
     * so the status reads that follow cost nothing.
     *
     * @return array{status: string, siblings: int[], used: int[], trashed: int[], unscanned: int, held: string}
     */
    public static function fileStatus(int $attachmentId): array
    {
        $siblings = self::siblings($attachmentId);

        if (count($siblings) > 1) {
            update_postmeta_cache($siblings);
        }

        $live = 0;
        $unused = 0;
        $unscanned = 0;
        $used = [];
        $trashed = [];

        foreach ($siblings as $rowId) {
            if (get_post_status($rowId) === 'trash') {
                $trashed[] = $rowId;

                continue;
            }

            ++$live;
            $status = (string) get_post_meta($rowId, ResultStore::META_STATUS, true);

            if ($status === ResultStore::STATUS_USED) {
                $used[] = $rowId;
            } elseif ($status === ResultStore::STATUS_UNUSED) {
                ++$unused;
            } else {
                ++$unscanned;
            }
        }

        $status = self::verdict($live, count($trashed), count($used), $unused);
        $held = self::HELD_NONE;

        if ($status === ResultStore::STATUS_USED && !in_array($attachmentId, $used, true)) {
            $held = self::HELD_SIBLING;
        } elseif ($status === self::STATUS_UNSCANNED && $trashed !== [] && $live > 0 && $unscanned === 0) {
            // Every live row has a verdict and none of them is used, so the only
            // thing standing between this file and the unused list is the trash.
            $held = self::HELD_TRASH;
        }

        return [
            'status' => $status,
            'siblings' => $siblings,
            'used' => $used,
            'trashed' => $trashed,
            'unscanned' => $unscanned,
            'held' => $held,
        ];
    }

    /**
     * Why a row on a shared file is not the verdict it would have reached
     * alone, in the one sentence pattern this plugin uses wherever it is
     * protecting a file rather than offering it (freshet-D95):
     * "Held back — <why>", em dash, lower case, no alarm.
     *
     * UploadGrace::heldBack() is the same shape for the upload grace. These are
     * its siblings rather than a second idea, which is the whole point of the
     * ruling: a user meets one pattern instead of three inventions.
     */
    public static function heldBackReason(string $held): string
    {
        return match ($held) {
            self::HELD_SIBLING => __('Held back — another library entry uses this file', 'freshet-unused-media'),
            self::HELD_TRASH => __('Held back — a copy of this file is in the trash', 'freshet-unused-media'),
            default => '',
        };
    }

    // ------------------------------------------------------- the subquery

    /**
     * One file per row: the grouped set every count, every listing, the space
     * total and the delete loop are built on.
     *
     * It is raw SQL rather than WP_Query, and that is the point rather than an
     * optimisation. WP_Query is filterable, so a listing that went through it
     * could be narrowed by other plugins while an aggregate count beside it was
     * not — which is exactly how a screen ends up counting dozens of files and
     * listing one. Files on disk are not a per-request opinion, so both halves
     * read this.
     *
     * Columns: `fg_key` the path, `fg_id` the row that represents the file,
     * `fg_status` the verdict, `fg_date` the earliest upload of any of its rows.
     * The filter clauses sit in HAVING and never in WHERE: narrowing the rows
     * before they are grouped would hide a *used* row from its own group and
     * hand back a file marked unused on half its evidence.
     *
     * @param string|null $status  Keep only files with this verdict; null keeps all.
     * @return array{sql: string, params: array<int, string>}
     */
    public static function subquery(?string $status = null, ?ResultFilters $filters = null): array
    {
        global $wpdb;

        $filters ??= ResultFilters::none();

        // Files whose every row is in the trash are not in the library any more.
        $having = [self::liveSql() . ' > 0'];
        $params = [];

        if ($status !== null) {
            $having[] = 'fg_status = ' . self::quote($status);
        }

        if ($filters->filename !== '') {
            // The stored path, which is what the File column shows underneath
            // the title — matching the title instead would filter on a label
            // the user can rename without touching the file.
            $having[] = 'fg_key LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters->filename) . '%';
        }

        // Both bounds name a whole day: "uploaded to 2024-06-30" has to include
        // the thirtieth, or the filter reads as off-by-one against the Uploaded
        // column.
        if ($filters->from !== '') {
            $having[] = 'fg_date >= %s';
            $params[] = $filters->from . ' 00:00:00';
        }

        if ($filters->to !== '') {
            $having[] = 'fg_date <= %s';
            $params[] = $filters->to . ' 23:59:59';
        }

        $sql = 'SELECT ' . self::keySql() . ' AS fg_key,'
            . ' ' . self::representativeSql() . ' AS fg_id,'
            . ' ' . self::statusSql() . ' AS fg_status,'
            . " MIN(CASE WHEN p.post_status <> 'trash' THEN p.post_date END) AS fg_date"
            . " FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} fgf ON fgf.post_id = p.ID AND fgf.meta_key = " . self::quote(self::META_FILE)
            . " LEFT JOIN {$wpdb->postmeta} fgs ON fgs.post_id = p.ID AND fgs.meta_key = " . self::quote(ResultStore::META_STATUS)
            . " WHERE p.post_type = 'attachment'"
            . ' GROUP BY fg_key'
            . ' HAVING ' . implode(' AND ', $having);

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * Every attachment **row** whose file carries a given verdict, as a SELECT
     * for an `IN (…)` in someone else's WHERE clause.
     *
     * The Media Library is the one screen that lists rows rather than files, so
     * a file-level filter has to come back down: the grouped subquery decides
     * which *files* match, and this maps those back to every row standing on
     * them. Both halves of that sentence are the same builder the counts and
     * the listings read, so a filtered library cannot select a set the badges
     * beside it disagree with.
     *
     * It is a WHERE fragment rather than a meta_query because no meta_query can
     * express it: "every row on this path agrees" is a property of the group,
     * and a meta_query only ever sees one row's meta.
     *
     * `$status` is one of this plugin's own constants and `subquery()` takes no
     * placeholders without filters, so there is nothing here to prepare.
     */
    public static function rowsWithStatusSql(string $status): string
    {
        global $wpdb;

        return 'SELECT p.ID'
            . " FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} fgf ON fgf.post_id = p.ID AND fgf.meta_key = " . self::quote(self::META_FILE)
            . " WHERE p.post_type = 'attachment'"
            . ' AND ' . self::keySql() . ' IN (SELECT fg.fg_key FROM (' . self::subquery($status)['sql'] . ') fg)';
    }

    // ------------------------------------------------------------ fragments

    /**
     * The row that stands for the file on screen: the first row that carries
     * the verdict, so a used file is represented by a row whose references are
     * the reason it was kept. Falls back to the first live row, then to any row
     * at all, so a group always has one.
     */
    private static function representativeSql(): string
    {
        return 'COALESCE('
            . "MIN(CASE WHEN p.post_status <> 'trash' AND fgs.meta_value = " . self::quote(ResultStore::STATUS_USED) . ' THEN p.ID END), '
            . "MIN(CASE WHEN p.post_status <> 'trash' THEN p.ID END), "
            . 'MIN(p.ID))';
    }

    private static function liveSql(): string
    {
        return "SUM(CASE WHEN p.post_status <> 'trash' THEN 1 ELSE 0 END)";
    }

    private static function trashSql(): string
    {
        return "SUM(CASE WHEN p.post_status = 'trash' THEN 1 ELSE 0 END)";
    }

    private static function scannedSql(string $status): string
    {
        return "SUM(CASE WHEN p.post_status <> 'trash' AND fgs.meta_value = " . self::quote($status) . ' THEN 1 ELSE 0 END)';
    }

    /**
     * Meta keys and status values are constants declared in this plugin's own
     * source, never request data — so they are quoted here rather than threaded
     * through every caller as a placeholder. Everything that *does* come from a
     * request goes through $wpdb->prepare() in subquery().
     */
    private static function quote(string $literal): string
    {
        return "'" . esc_sql($literal) . "'";
    }
}

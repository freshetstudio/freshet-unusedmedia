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
 * **What this key does NOT catch, so nobody reads it as more than it is.** An
 * attachment owns more files than the one it is grouped on — its `sizes`, its
 * pre-scale `original_image` — and one of those can be a *different*
 * attachment's own `_wp_attached_file`. Those two rows key on different paths
 * and are correctly two groups, yet core's deleter would unlink the shared file
 * for either of them. That is not fixable here: the relation lives inside a
 * serialized metadata blob, and this key has to stay expressible as keySql().
 * FileClaims answers it on the delete path instead, and DeleteController asks
 * before it removes anything.
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

    /**
     * The two sets the grouped read can answer for, and a group is in exactly
     * one of them. The library is every file with a live row; the trash set is
     * every file whose rows are all in the trash — the mirror HAVING, so
     * nothing is in both and nothing is in neither. fileStatus() names the
     * set a row's file is in under `set`.
     */
    public const SET_LIBRARY = 'library';

    /** See SET_LIBRARY. */
    public const SET_TRASH = 'trash';

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
     *
     * **The stored value is taken exactly as it stands, whitespace and all.**
     * This used to trim it and keySql() never has, so a `_wp_attached_file`
     * carrying a stray space keyed one way in PHP and another in SQL — one file
     * counted in one group and listed from another. Twins have to move
     * together, and it is this side that moved, because the stored value *is*
     * the file's address: core resolves it untrimmed (`get_attached_file()`),
     * fetch() looks rows up by equality against it, and a trimmed key therefore
     * names a path this row does not stand on — merging it with whichever rows
     * genuinely hold the trimmed path, which is two different files in one
     * group.
     *
     * What no expression *here* can settle is what the **database** calls
     * equal: the column's collation decides that, and no PHP written in this
     * method reaches it. So the answer lives in keySql(), which compares bytes
     * rather than the `_ci`, PAD SPACE reading of them — one rule for case, for
     * accents and for a trailing space alike (freshet-165).
     */
    public static function keyFor(int $attachmentId): string
    {
        $file = (string) get_post_meta($attachmentId, self::META_FILE, true);

        return $file !== '' ? $file : self::ROW_KEY_PREFIX . $attachmentId;
    }

    /**
     * The same key in SQL. Twin of keyFor(); the counts and the listing read
     * this one.
     *
     * **It compares bytes, because the column does not.** `wp_postmeta`'s
     * `meta_value` carries a `_ci` collation on every WordPress there is, and
     * the `unicode` family is accent-insensitive with it — so `GROUP BY` and
     * `=` on the bare column answer that `HERO-BANNER.jpg` and
     * `Hero-Banner.jpg` are one value while keyFor(), which is PHP and
     * therefore byte-exact, reads two. That is one physical file in one group
     * on one side and two on the other, which is the divergence this key
     * exists to not have: measured at 7 such pairs on a 9,828-file library and
     * 7 more on a second, and harmful in both directions — a case-sensitive
     * filesystem has two files and SQL hides one of them from the listing,
     * while a case-insensitive one has a single file that PHP's split offers
     * up for deletion out from under the other row (freshet-165).
     *
     * The same collation is PAD SPACE, so it also folds a trailing space into
     * the bare path and reads a whitespace-only value as empty. One cast
     * settles all three, which is why there is no second rule here for spaces.
     *
     * **The cast is on the column rather than around the whole expression, and
     * that is not cosmetic.** `NULLIF(meta_value, '')` is itself a comparison
     * the collation answers — it is what reads `'   '` as empty — so a cast
     * applied to the result would leave that half folded and keyFor() would go
     * on disagreeing about exactly one value.
     *
     * What it costs: the GROUP BY is a byte comparison on an expression rather
     * than on the column. Nothing is lost to it, because `wp_postmeta` is
     * indexed on `meta_key` and `post_id` and never on `meta_value` — the
     * grouped read was already scanning every attached-file row.
     */
    public static function keySql(): string
    {
        return "COALESCE(NULLIF(CAST(fgf.meta_value AS BINARY), ''), CONCAT('" . self::ROW_KEY_PREFIX . "', p.ID))";
    }

    /**
     * The key as a **selectable column**: the same value, handed back in the
     * column's own collation.
     *
     * keySql() is binary, and selecting it as `fg_key` would make the filename
     * filter and the File sort byte-exact too — a search box that misses
     * `Logo.png` when you type `logo`, and a listing ordered with every capital
     * ahead of every lower-case letter. Neither is an identity question, so
     * neither follows the key.
     *
     * Aggregated because the SELECT is grouped on the binary key: every row in
     * a group carries the same bytes, so MIN() is that key rather than a choice
     * between values. And emptiness is asked with CHAR_LENGTH rather than
     * NULLIF for the reason keySql() casts the column instead of the
     * expression — NULLIF is answered by the collation, which reads `'   '` as
     * empty while the key does not. Two expressions that can disagree about one
     * row is the whole defect this class is here to not have.
     */
    private static function keyColumnSql(): string
    {
        return 'COALESCE('
            . 'MIN(CASE WHEN CHAR_LENGTH(fgf.meta_value) > 0 THEN fgf.meta_value END), '
            . "CONCAT('" . self::ROW_KEY_PREFIX . "', MIN(p.ID)))";
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
     * @throws QueryFailed if the lookup could not be answered — see fetch().
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

        try {
            $found = self::fetch($wanted);
        } catch (QueryFailed) {
            // An optimisation and never a precondition, so a read that did not
            // answer caches nothing and says nothing: siblings() will ask again
            // for each row, and it is the one that has to refuse.
            return;
        }

        foreach ($wanted as $key) {
            // A path that came back with no rows is still an answer, and caching
            // it is what stops the next call querying for it again.
            self::$groups[$key] = $found[$key] ?? [];
        }
    }

    /**
     * One batch of rows, cut into the sibling groups **the batch itself holds**.
     *
     * The scan walks the library as an ID-ascending cursor, and the rows it
     * hands over are whatever page of that walk it is on. Grouped here, the
     * eight whole-table `LIKE` passes are issued once per file in the page
     * instead of once per row of it (Scanner::scanGroup, freshet-161) — which is
     * the same trick the delete path got in freshet-155, arrived at from the
     * other side.
     *
     * **The groups are cut to the batch on purpose, not for want of the rest.**
     * A file's other rows can sit past the cursor, and scanning them here would
     * scan them again in the batch they belong to: work done twice, and `done`
     * counting rows twice against `total`. Splitting the group at the batch
     * boundary instead leaves the cursor exactly as it was — the batch scans its
     * own rows, and the rest of the group is scanned, as its own group, in the
     * batch that reaches it. What it costs is measured rather than assumed:
     * duplicates of one upload are made at the same moment and therefore carry
     * consecutive ids, so a default batch of ten already holds nearly the whole
     * group. On a library averaging 10.48 rows per file this shares **80.7%** of
     * the passes against a ceiling of 90.5% if the scan chased whole groups
     * across the cursor, and on one averaging 2.76, **57.3%** against 63.7%.
     * The last tenth is not worth a cursor that can no longer say which rows it
     * has read.
     *
     * **A part of a group shares as safely as the whole of one.** SharedReads
     * binds the union of the ids and basenames it is given, and a union can only
     * widen a broad pass; every row still verifies against its own id and its
     * own basenames, so two rows of one file can still disagree.
     *
     * Membership is read with keyFor(), which reads postmeta core has cached —
     * prime() above fetches the whole batch's in one query — so this costs no
     * query of its own.
     *
     * @param int[] $attachmentIds A page of the cursor, in the order it walks.
     * @return array<int, int[]> One list per file, in the order the batch first
     *                           reaches it, rows in the batch's own order.
     */
    public static function groupsWithin(array $attachmentIds): array
    {
        $groups = [];

        foreach ($attachmentIds as $attachmentId) {
            $groups[self::keyFor((int) $attachmentId)][] = (int) $attachmentId;
        }

        return array_values($groups);
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
     * on a basename, which is the mirror of the bug this class fixes. And it is
     * cast for the reason keySql() is: the column's collation would answer that
     * a differently-cased spelling of the path is this path, and hand back rows
     * standing on a file these keys do not name (freshet-165).
     *
     * **A read that did not answer raises rather than returning an empty set.**
     * This is the delete path's first query: an empty result here reads as "no
     * other row stands on this file", and deleting on that answer unlinks a
     * file the rows it could not see are still using (freshet-141).
     *
     * @param string[] $keys
     * @return array<string, int[]>
     * @throws QueryFailed
     */
    private static function fetch(array $keys): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($keys), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- rows sharing one file; no WP API groups on _wp_attached_file. Placeholders are counted from the key list and every value is prepared.
        $rows = Db::rows('file grouping', $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS fg_id, pm.meta_value AS fg_key FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = 'attachment' AND CAST(pm.meta_value AS BINARY) IN ({$placeholders})
             ORDER BY p.ID ASC",
            array_merge([self::META_FILE], $keys)
        ), ARRAY_A));

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
     * One wholly-trashed file's status from its rows — the trash set's
     * verdict, over the trashed rows the library verdict deliberately ignores.
     *
     * Trashing an attachment removes no file: a page that references it still
     * renders it. So a binned file is judged on the same stored verdicts as a
     * live one and in the same direction — one row scanned used keeps it, it
     * is unused only when every row was scanned unused, and anything else has
     * not been decided. The In-trash section offers only the unused ones.
     *
     * trashStatusSql() is this sentence in SQL; the pair live side by side for
     * the reason verdict() and statusSql() do.
     */
    public static function trashVerdict(int $trash, int $used, int $unused): string
    {
        if ($used > 0) {
            return ResultStore::STATUS_USED;
        }

        if ($trash > 0 && $unused === $trash) {
            return ResultStore::STATUS_UNUSED;
        }

        return self::STATUS_UNSCANNED;
    }

    /** trashVerdict(), transcribed. Read it against the method above, not on its own. */
    public static function trashStatusSql(): string
    {
        return 'CASE'
            . ' WHEN ' . self::trashScannedSql(ResultStore::STATUS_USED) . ' > 0 THEN ' . self::quote(ResultStore::STATUS_USED)
            . ' WHEN ' . self::trashSql() . ' > 0'
            . ' AND ' . self::trashScannedSql(ResultStore::STATUS_UNUSED) . ' = ' . self::trashSql()
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
     * `set` says which of the two sets the file is in. A row whose every
     * sibling is in the trash with it is SET_TRASH, and its `status` is then
     * trashVerdict() over those rows — the same figure the In-trash section
     * lists it under — rather than the library verdict, which would call a
     * file the library no longer holds unscanned.
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
     * @return array{status: string, set: string, siblings: int[], used: int[], trashed: int[], unscanned: int, held: string}
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

        // The trashed rows' own verdicts, which the library verdict ignores
        // and the trash set's is made of.
        $trashUsed = 0;
        $trashUnused = 0;

        foreach ($siblings as $rowId) {
            $status = (string) get_post_meta($rowId, ResultStore::META_STATUS, true);

            if (get_post_status($rowId) === 'trash') {
                $trashed[] = $rowId;

                if ($status === ResultStore::STATUS_USED) {
                    ++$trashUsed;
                } elseif ($status === ResultStore::STATUS_UNUSED) {
                    ++$trashUnused;
                }

                continue;
            }

            ++$live;

            if ($status === ResultStore::STATUS_USED) {
                $used[] = $rowId;
            } elseif ($status === ResultStore::STATUS_UNUSED) {
                ++$unused;
            } else {
                ++$unscanned;
            }
        }

        if ($live === 0) {
            // Every row is in the trash, this one included: the file is in the
            // other set, judged by the other rule, and nothing holds it.
            return [
                'status' => self::trashVerdict(count($trashed), $trashUsed, $trashUnused),
                'set' => self::SET_TRASH,
                'siblings' => $siblings,
                'used' => [],
                'trashed' => $trashed,
                'unscanned' => 0,
                'held' => self::HELD_NONE,
            ];
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
            'set' => self::SET_LIBRARY,
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
     * Columns: `fg_key` the path — keyColumnSql(), which is keySql()'s value in
     * the column's own collation so the filter and the sort go on folding case;
     * `fg_id` the row that represents the file,
     * `fg_status` the verdict, `fg_date` the earliest upload of any of its rows.
     * The filter clauses sit in HAVING and never in WHERE: narrowing the rows
     * before they are grouped would hide a *used* row from its own group and
     * hand back a file marked unused on half its evidence.
     *
     * **Two sets, one builder.** The library — every file with a live row — is
     * what every count, listing, total and delete loop has always read, and
     * `$set` defaults to it. SET_TRASH is the mirror: `live = 0` in place of
     * `live > 0`, so a file whose every row is in the trash is in exactly one
     * of the two, and the library's own SQL is byte for byte what it was. The
     * trash set's `fg_status` is trashVerdict() over the trashed rows, and its
     * `fg_date` is the earliest upload of any row — the library's reads live
     * rows only, which is none of these.
     *
     * @param string|null $status  Keep only files with this verdict; null keeps all.
     * @param string      $set     SET_LIBRARY or SET_TRASH.
     * @return array{sql: string, params: array<int, string>}
     */
    public static function subquery(?string $status = null, ?ResultFilters $filters = null, string $set = self::SET_LIBRARY): array
    {
        global $wpdb;

        $filters ??= ResultFilters::none();
        $trashSet = $set === self::SET_TRASH;

        // Files whose every row is in the trash are not in the library any more.
        $having = [self::liveSql() . ($trashSet ? ' = 0' : ' > 0')];
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

        $sql = 'SELECT ' . self::keyColumnSql() . ' AS fg_key,'
            . ' ' . self::representativeSql() . ' AS fg_id,'
            . ' ' . ($trashSet ? self::trashStatusSql() : self::statusSql()) . ' AS fg_status,'
            . ($trashSet
                ? ' MIN(p.post_date) AS fg_date'
                : " MIN(CASE WHEN p.post_status <> 'trash' THEN p.post_date END) AS fg_date")
            . " FROM {$wpdb->posts} p"
            . " LEFT JOIN {$wpdb->postmeta} fgf ON fgf.post_id = p.ID AND fgf.meta_key = " . self::quote(self::META_FILE)
            . " LEFT JOIN {$wpdb->postmeta} fgs ON fgs.post_id = p.ID AND fgs.meta_key = " . self::quote(ResultStore::META_STATUS)
            . " WHERE p.post_type = 'attachment'"
            . ' GROUP BY ' . self::keySql()
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
     * at all, so a group always has one — the last step is what represents a
     * file in the trash set, whose rows are all trashed.
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

    /** scannedSql()'s twin over the trashed rows, for the trash set's verdict. */
    private static function trashScannedSql(string $status): string
    {
        return "SUM(CASE WHEN p.post_status = 'trash' AND fgs.meta_value = " . self::quote($status) . ' THEN 1 ELSE 0 END)';
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

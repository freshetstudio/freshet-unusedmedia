<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * The other attachments that stand on, or name, a file a deletion would unlink.
 *
 * FileGroups keeps every row pointing at one `_wp_attached_file` together, and
 * that is the right unit for the *stored path*. It is not the whole story about
 * the *file*, because an attachment owns more files than the one its
 * `_wp_attached_file` names, and one of those extra files can be another
 * attachment's own file:
 *
 * - **`original_image`.** A big upload is scaled: the row's own file becomes
 *   `hero-scaled.jpg` and its metadata names `hero.jpg` as the original. If
 *   `hero.jpg` is also, on its own, some other attachment's `_wp_attached_file`
 *   — a duplicate import, a translation copy, a re-upload that reused the name
 *   — then the two rows key on different paths and never meet, while
 *   `wp_delete_attachment_files()` unlinks `hero.jpg` for the first one.
 * - **A generated size.** The same collision one level down: an upload whose
 *   own name is already size-shaped (`photo-300x225.jpg`) has its sizes cut
 *   from the whole of that name (`photo-300x225-300x225.jpg`), and that is
 *   sometimes exactly the file another row was uploaded as.
 *
 * Core does not catch either. `wp_delete_attachment_files()` unlinks every size
 * and the original **by name**; its only cross-attachment guard is the legacy
 * `$meta['thumb']` lookup, which covers neither.
 *
 * So this is not a grouping question and it is deliberately not answered by
 * changing the grouping key. `FileGroups::keyFor()` and `keySql()` are twins —
 * the same key in PHP and in SQL — and both of the relations above live inside
 * a **serialized** `_wp_attachment_metadata` blob, which SQL cannot read
 * without a `LIKE` over serialized data. That needle is the shape this plugin
 * has already ruled out, and any key that could express it would stop being
 * expressible in the grouped subquery every count and listing is built on.
 *
 * The guard therefore goes where the harm is. The harm is an unlink, the only
 * thing in this plugin that unlinks is the delete path, and the question it has
 * to answer first is the one this class answers: **would removing these rows
 * take a file that some attachment outside them still stands on or still
 * names?** If it would, the file is not this plugin's to delete.
 *
 * **And the scan asks the same question, of this same class** (freshet-153).
 * The guard alone left the two halves of the plugin disagreeing out loud: the
 * listing went on offering a file the delete path would always refuse, and the
 * refusal reached the screen as a "skipped" tally with no reason on the row.
 * FileClaimDetector asks claimants() during the scan, so a claimed file never
 * reaches the unused list and says on its own row why. That narrows what the
 * delete path sees; it does not replace the guard, which still runs against a
 * library that changed after the scan.
 *
 * **Directory-scoped, because core builds every derivative path in the
 * attachment's own directory.** `wp_delete_attachment_files()` composes each
 * size, the original and every companion as `dirname($file) . '/' . $name`, so
 * nothing outside that one directory can be either the file this deletion
 * unlinks or an attachment that would lose it. That bound is what makes the
 * read affordable, and it was chosen by measurement rather than by argument:
 * on a 38,796-row library, one directory read of `_wp_attached_file` costs
 * ~75 ms and the metadata blobs behind it ~0.4 ms, while the core-shaped
 * alternative — `_wp_attachment_metadata LIKE '%basename%'`, one needle per
 * file being unlinked — costs **7.9 s** for the same answer.
 *
 * The two queries are the shapes `OrphanSizes` already uses per directory, for
 * the same reason and with the same root-directory case.
 */
final class FileClaims
{
    /** Core's record of the files it generated for an attachment. */
    private const META_DATA = '_wp_attachment_metadata';

    /** Core's record of the generation an in-admin image edit superseded. */
    private const META_BACKUP = '_wp_attachment_backup_sizes';

    /**
     * The match value of the evidence a claim produces.
     *
     * A claim is a reason a file is kept, so it reaches a screen the way every
     * other reason does — as a Reference, resolved to a label at render time.
     * FileClaimDetector writes it; holdsAlone() reads it back.
     */
    public const MATCH = 'file-claim';

    /**
     * Metadata rows read per query while a directory's index is built.
     *
     * The bound is on memory rather than on round trips: a blob for an image
     * with two dozen registered sizes is a few kilobytes, and a directory is
     * not bounded by anything. See eachMetadataIn().
     */
    private const METADATA_PAGE = 500;

    /**
     * The directory whose two reads are remembered. Exactly one, ever.
     *
     * Nullable rather than '' as the empty marker, because '' is a real
     * directory here — a library whose "organize by date" is off.
     */
    private static ?string $dir = null;

    /**
     * That directory's two relations, path => the rows claiming it, ascending.
     *
     * `attached` is the rows whose own `_wp_attached_file` is that path;
     * `named` is the rows whose metadata names it as one of their files. Both
     * are derived once and the metadata blobs they were built from are dropped
     * — the blobs are the part that grows with the library rather than with
     * the directory, and a request that kept them has kept the wrong thing
     * (the freshet-124 shape).
     *
     * `folded` is the same directory's paths indexed by their lower-case
     * spelling, which is what caseTwins() reads — built here rather than per
     * lookup because this is the memo, and the fold grows with the directory
     * rather than with the question.
     *
     * @var array{attached: array<string, int[]>, named: array<string, int[]>, folded: array<string, string[]>}
     */
    private static array $index = ['attached' => [], 'named' => [], 'folded' => []];

    /**
     * Metadata keys naming a single companion file beside the original.
     *
     * Read from core's own delete path rather than from a list of things it
     * seemed likely to write: `original_image` is the pre-scale upload, and the
     * rest are the sideloaded companions of a client-side conversion. A key a
     * given WordPress does not write is simply absent, so naming one that only
     * newer versions produce costs nothing and stops this guard lagging behind
     * the deleter it is guarding.
     */
    private const COMPANION_KEYS = [
        'original_image',
        'source_image',
        'animated_video',
        'animated_video_poster',
        'thumb',
    ];

    /**
     * Would deleting these rows unlink a file something else is standing on?
     *
     * @param int[] $rows Every row on one `_wp_attached_file` — a whole group.
     * @return array<string, int> The paths that are claimed, each mapped to one
     *                            attachment outside $rows that claims it.
     *                            Empty means the deletion takes nothing else's
     *                            file with it.
     * @throws QueryFailed if a read did not answer — the caller must refuse to
     *                     delete rather than read the silence as "nobody else".
     */
    public static function claimants(array $rows): array
    {
        $paths = self::unlinks($rows);

        if ($paths === []) {
            return [];
        }

        $group = [];

        foreach ($rows as $rowId) {
            $group[(int) $rowId] = true;
        }

        $dirs = [];

        foreach (array_keys($paths) as $path) {
            $dirs[self::directoryOf($path)] = true;
        }

        $claimed = [];

        foreach (array_keys($dirs) as $dir) {
            $index = self::index($dir);

            // And what this directory's filesystem would unlink alongside them,
            // which is not the same list on a disk that folds case. See
            // caseTwins(): the key stays byte-exact, and the volume's answer
            // is added here, where the harm is an unlink().
            $paths += self::caseTwins($index, $paths);

            // The rows in this directory, and the path each one stands on. This
            // is the half that answers both measured collisions: in each of
            // them the file about to go is another attachment's own file.
            //
            // And then the mirror of it, which is the same collision with the
            // other row chosen for deletion: a file this deletion unlinks that
            // a neighbour's metadata names as one of *its* sizes, originals or
            // superseded generations. Without this half, deleting the row that
            // owns the shared file destroys the row that merely names it.
            //
            // Standing on a path is checked before naming it, so a file with
            // both kinds of claimant is reported against the row that would
            // lose its own file.
            foreach (['attached', 'named'] as $relation) {
                foreach ($index[$relation] as $file => $rowIds) {
                    if (!isset($paths[$file]) || isset($claimed[$file])) {
                        continue;
                    }

                    foreach ($rowIds as $rowId) {
                        if (!isset($group[$rowId])) {
                            $claimed[$file] = $rowId;

                            break;
                        }
                    }
                }
            }
        }

        return $claimed;
    }

    /**
     * Is a claim the only thing keeping this file out of the unused pool?
     *
     * The peer of UploadGrace::holdsAlone(), and the same three-part test minus
     * the clock: a claim is not time-bound the way the upload grace is, so
     * there is nothing here to re-read at render (freshet-D98 bites on the
     * grace because its sentence quotes a window that expires).
     *
     * Nothing truncated is the part that matters. Refs are capped at storage,
     * so a file with more references than were kept cannot be claim-only
     * however the kept ones read — believing a truncated list is how a used
     * file gets called held.
     *
     * @param array{count: int, refs: Reference[]} $data exactly what ResultStore::refs() returns
     */
    public static function holdsAlone(array $data): bool
    {
        if ($data['refs'] === [] || $data['count'] !== count($data['refs'])) {
            return false;
        }

        foreach ($data['refs'] as $ref) {
            if ($ref->match !== self::MATCH) {
                return false;
            }
        }

        return true;
    }

    /**
     * How a file kept by a claim reads wherever one file is named — the
     * listing cell and the Media Library badge.
     *
     * The settled phrasing for a file this plugin is protecting rather than
     * missing (freshet-D95): "Held back — <why>", em dash, lower case, no
     * alarm. UploadGrace::heldBack() and FileGroups::heldBackReason() are its
     * siblings; a user meets one sentence pattern rather than three
     * inventions.
     *
     * It says what a sibling hold does not: the entry that would lose
     * something is on a *different* file, and what it shares with this one is
     * a generated size or an original that core would unlink by name.
     */
    public static function heldBack(): string
    {
        return __('Held back — deleting it would remove a file another library entry uses', 'freshet-unused-media');
    }

    /**
     * Drop the remembered directory. Called at every batch boundary, and by
     * the delete loop, which removes rows the index describes.
     */
    public static function flush(): void
    {
        self::$dir = null;
        self::$index = ['attached' => [], 'named' => [], 'folded' => []];
    }

    /**
     * The paths in this directory that a case-insensitive filesystem would
     * unlink along with the files this deletion removes.
     *
     * **The key stays byte-exact and this does not contradict it.** A stored
     * `_wp_attached_file` is an address, so two spellings of one name are two
     * addresses and FileGroups keys them apart on both sides (freshet-165).
     * But the harm this class guards against is an `unlink()`, and unlink is
     * answered by the volume rather than by the key: on macOS, on Windows and
     * on a case-insensitive volume mounted under Linux, `hero.jpg` and
     * `HERO.jpg` are **one file**, so removing the row standing on one destroys
     * the file the other row renders. Two questions, answered where each
     * belongs.
     *
     * Generous on purpose, and that is the asymmetry claimsOf() is already
     * built on: nothing here can know the folding rule of the volume the
     * uploads sit on, and over-claiming keeps a file that could have gone while
     * over-unlinking destroys one that could not. What it costs on a
     * case-sensitive host is that two spellings of one name are held back with
     * a reason on the row rather than offered — measured, 14 such pairs in
     * 105,396 attachment rows across eight libraries.
     *
     * The fold is byte-wise ASCII: that is what every case-insensitive
     * filesystem folds at minimum and what all 14 measured pairs are. A twin
     * differing only outside ASCII is not covered, and is not claimed to be.
     *
     * **Both halves of the path fold, not just the filename** (freshet-166).
     * The index this reads is scoped to the folded directory rather than the
     * byte-exact one, so a twin whose *folder* is the half spelled differently
     * is inside the scope and is found here. Measured before the change at 0
     * such pairs — and 0 mixed-case directories at all — in 1,760 directories
     * across 29 local libraries, so this closes a class rather than a victim;
     * it is fixed anyway because the direction of the miss is an unlink.
     *
     * @param array{attached: array<string, int[]>, named: array<string, int[]>, folded: array<string, string[]>} $index
     * @param array<string, true> $paths
     * @return array<string, true>
     */
    private static function caseTwins(array $index, array $paths): array
    {
        $twins = [];

        foreach (array_keys($paths) as $path) {
            foreach ($index['folded'][strtolower((string) $path)] ?? [] as $file) {
                if (!isset($paths[$file])) {
                    $twins[$file] = true;
                }
            }
        }

        return $twins;
    }

    /**
     * One directory's two relations, read once.
     *
     * Both queries are per directory rather than per attachment, so the memo is
     * what makes asking this on every scanned row affordable: a month's worth
     * of uploads shares one directory, and reads it once per batch instead of
     * once per file. One directory is remembered at a time, for the reason
     * SizeSiblings remembers one listing at a time — the structure grows with
     * the library rather than with the batch.
     *
     * @return array{attached: array<string, int[]>, named: array<string, int[]>, folded: array<string, string[]>}
     * @throws QueryFailed
     */
    private static function index(string $dir): array
    {
        if (self::$dir === $dir) {
            return self::$index;
        }

        $index = ['attached' => [], 'named' => [], 'folded' => []];

        foreach (self::attachedIn($dir) as $rowId => $file) {
            $index['attached'][$file][$rowId] = true;
        }

        self::eachMetadataIn($dir, static function (int $rowId, string $file, string $key, mixed $value) use (&$index): void {
            $backup = $key === self::META_BACKUP;

            foreach (array_keys(self::claimsOf($file, $backup ? null : $value, $backup ? $value : null)) as $claim) {
                $index['named'][$claim][$rowId] = true;
            }
        });

        // Keyed by row while it is built so a row named twice — its two meta
        // keys, or two pages — is one claimant; ascending afterwards so which
        // claimant a shared path is reported against does not depend on the
        // order the database happened to return.
        $folded = [];

        foreach (['attached', 'named'] as $relation) {
            foreach ($index[$relation] as $file => $rowIds) {
                ksort($rowIds);
                $index[$relation][$file] = array_keys($rowIds);
                // Keyed by the real path while it is built, so a path in both
                // relations is one entry rather than two.
                $folded[strtolower((string) $file)][(string) $file] = true;
            }
        }

        foreach ($folded as $lower => $files) {
            $index['folded'][$lower] = array_keys($files);
        }

        self::$dir = $dir;
        self::$index = $index;

        return $index;
    }

    /**
     * Every uploads-relative path deleting these rows would remove.
     *
     * Read against `wp_delete_attachment_files()`: the attached file itself,
     * every size the current metadata names, the companions above, and the
     * generation a `_wp_attachment_backup_sizes` record superseded. Metadata is
     * fetched the way the deleter fetches it — `wp_get_attachment_metadata()`,
     * filters and all — so an offload plugin that rewrites it is read here as
     * it will be read there.
     *
     * Deliberately **not** generous: this is a list of files that are about to
     * be unlinked, so a name core would never touch does not belong in it. A
     * name added here that core leaves alone becomes a file this plugin refuses
     * to delete for no reason. (claimsOf() is the generous one, and that
     * asymmetry is the point — over-claiming protects, over-unlinking does not.)
     *
     * @param int[] $rows
     * @return array<string, true>
     */
    public static function unlinks(array $rows): array
    {
        $paths = [];

        foreach ($rows as $rowId) {
            $rowId = (int) $rowId;
            $file = self::pathOf($rowId);

            if ($file === '') {
                continue;
            }

            $paths[$file] = true;
            $dir = self::directoryOf($file);

            foreach (self::namesIn(wp_get_attachment_metadata($rowId), get_post_meta($rowId, self::META_BACKUP, true)) as $name) {
                $paths[self::join($dir, $name)] = true;
            }
        }

        return $paths;
    }

    /**
     * Every file one attachment depends on, as uploads-relative paths.
     *
     * The generous list, and generous on purpose: this decides whether some
     * *other* attachment would lose something, so a name included here at worst
     * keeps a file that could have gone. It adds the alternate-mime copies
     * (`hero-300x200.jpg.webp`) that unlinks() leaves out, because a row that
     * renders one still needs it whether or not core's deleter names it.
     *
     * @return array<string, true>
     */
    private static function claimsOf(string $file, mixed $meta, mixed $backup): array
    {
        if ($file === '') {
            return [];
        }

        $dir = self::directoryOf($file);
        $paths = [$file => true];

        foreach (self::namesIn($meta, $backup, true) as $name) {
            $paths[self::join($dir, $name)] = true;
        }

        return $paths;
    }

    /**
     * The basenames a metadata blob and a backup-sizes record name.
     *
     * @param bool $withSources Include the alternate-mime copies — see claimsOf().
     * @return string[]
     */
    private static function namesIn(mixed $meta, mixed $backup, bool $withSources = false): array
    {
        $names = [];

        if (is_array($meta)) {
            foreach (self::COMPANION_KEYS as $key) {
                if (!empty($meta[$key]) && is_string($meta[$key])) {
                    $names[] = wp_basename($meta[$key]);
                }
            }

            if ($withSources) {
                foreach ((array) ($meta['sources'] ?? []) as $source) {
                    if (is_array($source) && !empty($source['file'])) {
                        $names[] = wp_basename((string) $source['file']);
                    }
                }
            }

            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!is_array($size)) {
                    continue;
                }

                if (!empty($size['file'])) {
                    $names[] = wp_basename((string) $size['file']);
                }

                if (!$withSources) {
                    continue;
                }

                foreach ((array) ($size['sources'] ?? []) as $source) {
                    if (is_array($source) && !empty($source['file'])) {
                        $names[] = wp_basename((string) $source['file']);
                    }
                }
            }
        }

        if (is_array($backup)) {
            foreach ($backup as $superseded) {
                if (is_array($superseded) && !empty($superseded['file'])) {
                    $names[] = wp_basename((string) $superseded['file']);
                }
            }
        }

        return array_values(array_filter($names));
    }

    /**
     * The rows whose own file is in this directory: id => stored path.
     *
     * The `LIKE` also matches subdirectories, so the directory is compared
     * whole afterwards — the same correction OrphanSizes makes, and for the
     * same reason: a same-named file one level down is a different file.
     *
     * **And the comparison folds case, because two spellings of one folder are
     * one folder to the volume** (freshet-166). The fetch above is answered by
     * the column's own collation, which is `_ci` on every WordPress there is,
     * so `Uploads/2019/` and `uploads/2019/` both come back; a byte-exact
     * comparison then dropped everything the fetch had folded in, leaving a
     * library that holds both spellings with **two index scopes that never see
     * each other** — and a caseTwins() pair living across that boundary
     * invisible to the guard that exists to catch it. That is a false negative
     * in the one direction this class may not err in, so the scope is the
     * folded directory and the fold is the same byte-wise ASCII one caseTwins()
     * uses (freshet-D138: case is the only fold all four live collations agree
     * on). It is not a loosening of the whole-directory comparison: a
     * subdirectory still does not fold onto its parent and an unrelated folder
     * still does not fold onto this one.
     *
     * **What that does NOT do is merge anything.** The index stays keyed on the
     * real stored path — bytes, per freshet-D136 — so a folded row is reachable
     * only through caseTwins(), which is the one place a path is looked up by
     * its folded spelling. Every other read here asks `isset($paths[$file])`
     * against the byte-exact list unlinks() derived from the rows being
     * deleted, and no path in that list can differ from this directory in
     * anything but case. The cost is the fold itself, and it is charged only
     * where byte-equality already failed: measured at **+0.001 to +0.003 ms per
     * thousand rows** on the three benchmark libraries, against the ~75 ms the
     * directory read itself costs.
     *
     * @return array<int, string>
     * @throws QueryFailed
     */
    private static function attachedIn(string $dir): array
    {
        global $wpdb;

        $sql = $dir === ''
            // No folder in the path at all: an "organize by date" that is off.
            ? $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT LIKE %s",
                FileGroups::META_FILE,
                '%/%'
            )
            : $wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                FileGroups::META_FILE,
                $wpdb->esc_like($dir . '/') . '%'
            );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one read per directory, and only when something is being deleted from it.
        $rows = Db::rows('file claims', $wpdb->get_results($sql, ARRAY_A));

        $found = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = self::normalise((string) ($row['meta_value'] ?? ''));

            if ($path !== '' && self::sameDirectory($dir, $path)) {
                $found[(int) ($row['post_id'] ?? 0)] = $path;
            }
        }

        return $found;
    }

    /**
     * Every metadata blob belonging to a row whose own file is in this
     * directory, handed to $fold one at a time and then let go.
     *
     * One query per page rather than `get_post_meta()` per row — the per-row
     * shape is what ran a scan out of memory once already — but deliberately
     * **not** one query for the whole directory. A directory is not bounded by
     * anything: a real library carries a flat 4,535-row upload folder whose
     * blobs come to **137 MB read in one go**, which is past a stock PHP
     * memory limit on its own. Paged and folded, the same directory costs one
     * page. It was one query before this was on the scan path, where it ran
     * only for a directory something was being deleted from; now every scanned
     * row asks, so an unbounded read here is a scan that dies on a library it
     * should merely be slow on (the freshet-124 shape).
     *
     * A row's two keys may land in different pages, and that needs no handling:
     * $fold is given one key at a time and every path it yields goes into the
     * same index, so the union is the same however the pages fall.
     *
     * The directory is compared through sameDirectory(), so this half of the
     * index carries the same folded scope the attached half does — a twin whose
     * only claimant *names* the file rather than standing on it is the same
     * missed refusal.
     *
     * @param callable(int, string, string, mixed): void $fold rowId, its own
     *        path, the meta key, the unserialized value.
     * @throws QueryFailed
     */
    private static function eachMetadataIn(string $dir, callable $fold): void
    {
        global $wpdb;

        $where = $dir === ''
            ? ['f.meta_value NOT LIKE %s', '%/%']
            : ['f.meta_value LIKE %s', $wpdb->esc_like($dir . '/') . '%'];

        $after = 0;

        while (true) {
            $sql = $wpdb->prepare(
                "SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value, f.meta_value AS fc_file FROM {$wpdb->postmeta} m"
                . " INNER JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = %s"
                . " WHERE m.meta_key IN (%s, %s) AND {$where[0]} AND m.meta_id > %d"
                . ' ORDER BY m.meta_id ASC LIMIT %d',
                FileGroups::META_FILE,
                self::META_DATA,
                self::META_BACKUP,
                $where[1],
                $after,
                self::METADATA_PAGE
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one read per page of one directory, and only for a directory being scanned or deleted from.
            $rows = Db::rows('file claims', $wpdb->get_results($sql, ARRAY_A));

            if ($rows === []) {
                return;
            }

            $cursor = $after;

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $after = max($after, (int) ($row['meta_id'] ?? 0));
                $path = self::normalise((string) ($row['fc_file'] ?? ''));

                if ($path === '' || !self::sameDirectory($dir, $path)) {
                    continue;
                }

                $fold(
                    (int) ($row['post_id'] ?? 0),
                    $path,
                    (string) ($row['meta_key'] ?? ''),
                    maybe_unserialize((string) ($row['meta_value'] ?? ''))
                );
            }

            // A full page that did not move the cursor would be read again for
            // ever. It takes a `meta_id` that is missing or zero to happen, so
            // it should not — and a scan that never ends is not the failure to
            // find out on.
            if (count($rows) < self::METADATA_PAGE || $after <= $cursor) {
                return;
            }
        }
    }

    /** One row's stored path, normalised the way every key here is. */
    private static function pathOf(int $attachmentId): string
    {
        return self::normalise((string) get_post_meta($attachmentId, FileGroups::META_FILE, true));
    }

    /** Forward slashes, no surrounding whitespace, no leading slash. */
    private static function normalise(string $path): string
    {
        return trim(str_replace('\\', '/', trim($path)), '/');
    }

    /**
     * Is this path's own directory the one the index is being built for?
     *
     * Byte-equality first, and it answers on every library measured; the fold
     * behind it is what makes two spellings of one folder one index scope
     * rather than two that never meet. See attachedIn() for why that is the
     * right scope and why it merges nothing.
     */
    private static function sameDirectory(string $dir, string $path): bool
    {
        $found = self::directoryOf($path);

        return $found === $dir || strtolower($found) === strtolower($dir);
    }

    /** The directory of a stored path, '' for a library not foldered by date. */
    private static function directoryOf(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' || $dir === '/' ? '' : trim($dir, '/');
    }

    private static function join(string $dir, string $name): string
    {
        return $dir === '' ? $name : $dir . '/' . $name;
    }
}

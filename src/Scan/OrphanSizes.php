<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Size files whose original is gone — found here, never deleted here.
 *
 * A generated size has no attachment row and no `_wp_attached_file` value, so
 * it is outside this plugin's unit of work by design (FileGroups): it belongs
 * to its original and is swept with it. That reasoning runs out when the
 * original is not there any more. A `hero-300x200.jpg` with no `hero.*` beside
 * it and no row naming it belongs to nothing in the library, and is the one
 * derivative that can be judged on its own.
 *
 * This class finds them and records them (OrphanStore); whether post content
 * still names one is OrphanReferences' answer, taken once at the end of the
 * scan. There is no row to corroborate the finding against, so the two absence
 * tests below are the only thing standing between the record and a live
 * thumbnail — which is why recheck() exists: anything that acts on a recorded
 * file runs the same two tests again, on that one file, at the moment it acts,
 * and never trusts what the scan wrote down.
 *
 * Both tests must fail for a file to be recorded:
 *
 * - **No file at the stem's own path.** Anything named `hero.*`, `hero-scaled.*`
 *   or `hero-rotated.*` in the same directory means the original is there.
 * - **No attachment row carrying that stem, and no attachment row standing on
 *   the file itself.** A row whose file is missing from disk is still a row:
 *   the library knows about the original, so the size is its derivative and not
 *   an orphan. A row carries a stem two ways, and both count: through its
 *   `_wp_attached_file`, and through the sizes its `_wp_attachment_metadata`
 *   names. The two are not the same set — an image edited in wp-admin has its
 *   attached file rewritten to a new stem (`hero-e1673970542774.png`) while its
 *   metadata still names sizes cut from the old one (`hero-1280x640.png`) — and
 *   reading only the first listed a file the same plugin's AttachmentContext
 *   was already protecting as one of that live row's basenames.
 *
 *   The stem is not the whole of that read, either. `Logo-Bikepal-300x200.png`
 *   can be an upload's *own* name — nobody generated it, someone exported it at
 *   that size and dropped it in — and then the row's stem is
 *   `Logo-Bikepal-300x200`, never `Logo-Bikepal`. A size-shaped name never
 *   enters the on-disk originals either, so both tests missed the one record
 *   that settles it: the path is that row's `_wp_attached_file`. The paths the
 *   same query already returns are therefore kept beside the stems it derives,
 *   and a candidate standing on one of them is the library's own file rather
 *   than an orphan.
 *
 * The enumeration rides along with the scan rather than walking the tree: the
 * scan already visits every attachment, and each attachment's directory is read
 * once per request for the basenames anyway. The seam that leaves is real and
 * is stated rather than hidden — a directory holding no live attachment at all
 * is never visited, so its orphans are not listed. Closing that would mean
 * walking `uploads/` whole, which is a different and much more expensive shape.
 */
final class OrphanSizes
{
    /**
     * Where the finding lived until it moved to OrphanStore's table: at most a
     * hundred paths a directory and five hundred in all, with no sizes. Kept so
     * a reset and an uninstall still remove what an earlier version left.
     */
    public const OPTION = 'freshet_unusedmedia_orphan_sizes';

    /** Where core records the sizes it generated. FileGroups owns the other key. */
    private const META_DATA = '_wp_attachment_metadata';

    /** @var array<string, true> Directories already examined in this request. */
    private static array $seen = [];

    /**
     * Examine the directory this attachment's file lives in, once per request.
     *
     * Called from the scan loops rather than from Scanner, deliberately: a
     * re-scan of one row happens on every delete verification and on a couple of
     * admin screens, and none of those is an enumeration of the library.
     */
    public static function observeAttachment(int $attachmentId): void
    {
        $relative = trim((string) get_post_meta($attachmentId, FileGroups::META_FILE, true));
        $absolute = get_attached_file($attachmentId);

        if ($relative === '' || !is_string($absolute) || $absolute === '') {
            return;
        }

        $dir = self::directoryOf($relative);

        if (isset(self::$seen[$dir])) {
            return;
        }

        self::$seen[$dir] = true;

        $listing = SizeSiblings::listing(dirname($absolute));

        if ($listing === []) {
            return;
        }

        [$present, $sizes] = self::split($listing);

        $candidates = [];

        foreach ($sizes as $entry => $stem) {
            if (self::anyPresent($stem, $present) || !is_file(dirname($absolute) . '/' . $entry)) {
                continue;
            }

            $candidates[$entry] = $stem;
        }

        if ($candidates === []) {
            return;
        }

        // The second test, and the only one that costs a query: one read of the
        // paths this directory's attachment rows point at. Not a needle and not
        // a match — an enumeration of a directory, asked once for the whole of
        // it rather than once per candidate stem.
        //
        // Two readings of the same rows, from the one read: the stem a row's
        // sizes would be cut from, and the path the row itself stands on. The
        // second costs nothing — the paths are already in hand — and is the
        // only thing that answers a size-shaped upload, whose stem is its own
        // full name and so matches nothing.
        $attached = self::attachedIn($dir);

        foreach ($candidates as $entry => $stem) {
            $path = $dir === '' ? $entry : $dir . '/' . $entry;

            if (isset($attached['paths'][$path]) || self::anyPresent($stem, $attached['stems'])) {
                unset($candidates[$entry]);
            }
        }

        if ($candidates === []) {
            return;
        }

        // The other half of that same test, kept as a second read rather than
        // folded into the first: it carries every metadata blob in the
        // directory, and asking for those is only worth it once something has
        // survived the cheap half. A directory whose sizes all have their
        // originals never reaches this line.
        $namedStems = self::namedStemsIn($dir);

        foreach ($candidates as $entry => $stem) {
            if (self::anyPresent($stem, $namedStems)) {
                unset($candidates[$entry]);
            }
        }

        if ($candidates === []) {
            return;
        }

        // One stat per file that survived both tests — the byte figure is the
        // only thing the record needs that the listing did not already say.
        $files = [];

        foreach (array_keys($candidates) as $entry) {
            $bytes = @filesize(dirname($absolute) . '/' . $entry);
            $files[$entry] = $bytes === false ? 0 : $bytes;
        }

        ksort($files, SORT_STRING);

        // A write that fails loses this directory's finding and nothing else:
        // the attachment verdicts this scan exists for are not in this table,
        // and a database that refuses this write is refusing the scan's own
        // reads too, which the scan counts and reports as errors.
        try {
            OrphanStore::replaceDirectory($dir, $files);
        } catch (QueryFailed) {
            return;
        }
    }

    /**
     * Is the file at $relative still what the scan recorded it as?
     *
     * The same two absence tests observeAttachment() applies to a directory,
     * applied to one file and read fresh: the directory is listed again rather
     * than taken from SizeSiblings' memo, and both attachment reads are issued
     * again. Null when the file is still a size with no original and no row;
     * otherwise the reason it is not, as one of `not-a-size`, `original` or
     * `attachment`.
     *
     * A read that does not answer raises QueryFailed (through Db) rather than
     * coming back as "no row names it" — for a caller about to act on the
     * answer, silence and absence must not be the same thing.
     *
     * @param string $relative Path under the uploads directory.
     * @param string $absolute The same file on disk.
     * @throws QueryFailed
     */
    public static function recheck(string $relative, string $absolute): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $entry = wp_basename($relative);
        $stem = SizeSiblings::sizeStem($entry);

        if ($stem === '') {
            return 'not-a-size';
        }

        $listing = @scandir(dirname($absolute), SCANDIR_SORT_NONE);
        [$present] = self::split(is_array($listing) ? array_diff($listing, ['.', '..']) : []);

        if (self::anyPresent($stem, $present)) {
            return 'original';
        }

        $dir = self::directoryOf($relative);
        $attached = self::attachedIn($dir);

        Db::assertAnswered('orphan recheck');

        if (isset($attached['paths'][$relative]) || self::anyPresent($stem, $attached['stems'])) {
            return 'attachment';
        }

        $named = self::namedStemsIn($dir);

        Db::assertAnswered('orphan recheck');

        return self::anyPresent($stem, $named) ? 'attachment' : null;
    }

    /** Forget which directories this request has examined. Called per batch. */
    public static function flush(): void
    {
        self::$seen = [];
    }

    /**
     * Start again: a new scan describes the library as it is now. Creates the
     * table where an update rather than an activation left none.
     */
    public static function reset(): void
    {
        self::$seen = [];
        delete_option(self::OPTION);

        OrphanStore::install();
        OrphanStore::clear();
    }

    /**
     * What the last scan found, in figures: how many, how many bytes, and how
     * many post content names and does not. The files themselves are read a
     * page at a time from OrphanStore, never all at once.
     *
     * All zeroes where no scan has made the table yet.
     *
     * @return array{count: int, bytes: int, referenced: int, unreferenced: int, unchecked: int}
     * @throws QueryFailed
     */
    public static function read(): array
    {
        if (!OrphanStore::exists()) {
            return ['count' => 0, 'bytes' => 0, 'referenced' => 0, 'unreferenced' => 0, 'unchecked' => 0];
        }

        return OrphanStore::summary();
    }

    /**
     * A listing, split into the originals it holds and the generated sizes it
     * holds. An original is anything that is not size-shaped, read through the
     * same stem strip, so `hero.jpg`, `hero-scaled.jpg` and a `hero.webp`
     * alternate all say the same thing: hero is still here.
     *
     * **And every name also stands for its own path** (freshet-438). The strip
     * reads `hero-scaled.jpg` as `hero` and nothing else, so a size cut *from
     * the scaled file* — `hero-scaled-1024x683.jpg`, which is what regenerating
     * thumbnails from an attachment whose file is the scaled copy writes — found
     * no `hero-scaled` here and was recorded as having no original, while its
     * original sat beside it. On one production library that was every one of
     * the 465 files recorded. A file at the stem's own path is the first absence
     * test as this class states it, so both readings of each name count; reading
     * an original into a name too readily is the harmless direction.
     *
     * @param iterable<string> $listing
     * @return array{0: array<string, true>, 1: array<string, string>} [stems present, size entry => stem]
     */
    private static function split(iterable $listing): array
    {
        $present = [];
        $sizes = [];

        foreach ($listing as $entry) {
            $entry = (string) $entry;
            $present[self::bare($entry)] = true;
            $stem = SizeSiblings::sizeStem($entry);

            if ($stem === '') {
                $present[SizeSiblings::stem($entry)] = true;

                continue;
            }

            $sizes[$entry] = $stem;
        }

        return [$present, $sizes];
    }

    /** A name without its last extension: `hero-scaled.jpg` is `hero-scaled`. */
    private static function bare(string $basename): string
    {
        $dot = strrpos($basename, '.');

        return $dot === false ? $basename : substr($basename, 0, $dot);
    }

    /**
     * Could anything in $stems be the original this size was cut from?
     *
     * Usually the stem is the original's own name and the answer is one lookup.
     * A document is the exception: core renders `doc.pdf` to `doc-pdf.jpg` and
     * cuts the previews from *that*, so a `doc-pdf-300x169.jpg` whose original
     * is `doc.pdf` would look orphaned to a straight comparison. Reading one
     * trailing `-pdf` as optional is what keeps a live PDF's previews off this
     * list; being too willing to find an original is the harmless direction.
     *
     * @param array<string, true> $stems
     */
    private static function anyPresent(string $stem, array $stems): bool
    {
        if (isset($stems[$stem])) {
            return true;
        }

        return preg_match('/^(.+)-pdf$/i', $stem, $matches) === 1 && isset($stems[$matches[1]]);
    }

    /** The directory of a stored path, '' for a library that is not foldered by date. */
    private static function directoryOf(string $relative): string
    {
        $dir = dirname(str_replace('\\', '/', $relative));

        return $dir === '.' || $dir === '/' ? '' : trim($dir, '/');
    }

    /**
     * What this directory's attachment rows say, in the two readings that count.
     *
     * `stems` is the name each row's sizes would be cut from — the original
     * behind a `hero-300x200.jpg`. `paths` is the rows' own stored paths, kept
     * because a stem cannot answer for an upload whose *own* filename is
     * size-shaped: `Logo-Bikepal-300x200.png` yields the stem
     * `Logo-Bikepal-300x200`, so the row it belongs to is invisible to the stem
     * test while the row plainly stands on the file.
     *
     * Both come off the same `get_col()`, so this is the same one query per
     * directory it has always been; the paths are compared whole rather than by
     * basename, which keeps a same-named file in a subdirectory the `LIKE`
     * also matches from answering for one here.
     *
     * @return array{stems: array<string, true>, paths: array<string, true>}
     */
    private static function attachedIn(string $dir): array
    {
        global $wpdb;

        $sql = $dir === ''
            // No folder in the path at all: an "organize by date" that is off.
            ? $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT LIKE %s",
                FileGroups::META_FILE,
                '%/%'
            )
            : $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
                FileGroups::META_FILE,
                $wpdb->esc_like($dir . '/') . '%'
            );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one read per directory the scan visits.
        $files = (array) $wpdb->get_col($sql);

        $stems = [];
        $paths = [];

        foreach ($files as $file) {
            $path = trim(str_replace('\\', '/', (string) $file), '/');

            if ($path === '') {
                continue;
            }

            $paths[$path] = true;

            // Both readings, as split() reads the disk: a row standing on
            // `hero-scaled.jpg` carries `hero` and `hero-scaled` alike.
            foreach ([SizeSiblings::stem(wp_basename($path)), self::bare(wp_basename($path))] as $stem) {
                if ($stem !== '') {
                    $stems[$stem] = true;
                }
            }
        }

        return ['stems' => $stems, 'paths' => $paths];
    }

    /**
     * The stems named by the sizes in this directory's attachment metadata.
     *
     * `_wp_attached_file` is what the row points at; the sizes it generated are
     * recorded separately, and the two can name different stems. That is not an
     * edge case — every image edited in wp-admin is one — and reading only the
     * attached file made this list disagree with `AttachmentContext`, which has
     * always admitted every `sizes[…]['file']` as a basename of the live row.
     *
     * A stem here is read the way `SizeSiblings::naming()` reads it: off the
     * *named size*, not off the attached file, so a document's previews
     * (`doc-pdf-300x169.jpg` → `doc-pdf`) need no special case, and so a
     * leftover size beside a named one is covered by the same stem.
     *
     * One query per directory, joined rather than looped: `get_post_meta()` per
     * attachment is the shape that ran a scan out of memory once already.
     *
     * @return array<string, true>
     */
    private static function namedStemsIn(string $dir): array
    {
        global $wpdb;

        $where = $dir === ''
            ? ['f.meta_value NOT LIKE %s', '%/%']
            : ['f.meta_value LIKE %s', $wpdb->esc_like($dir . '/') . '%'];

        $sql = $wpdb->prepare(
            "SELECT m.meta_value FROM {$wpdb->postmeta} m"
            . " INNER JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = %s"
            . " WHERE m.meta_key = %s AND {$where[0]}",
            FileGroups::META_FILE,
            self::META_DATA,
            $where[1]
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one read per directory that still holds a candidate.
        $blobs = (array) $wpdb->get_col($sql);

        $stems = [];

        foreach ($blobs as $blob) {
            $meta = maybe_unserialize((string) $blob);

            foreach ((array) (is_array($meta) ? ($meta['sizes'] ?? []) : []) as $size) {
                if (!is_array($size)) {
                    continue;
                }

                foreach (array_merge([$size], array_values((array) ($size['sources'] ?? []))) as $named) {
                    if (!is_array($named) || empty($named['file'])) {
                        continue;
                    }

                    $stem = SizeSiblings::sizeStem(wp_basename((string) $named['file']));

                    if ($stem !== '') {
                        $stems[$stem] = true;
                    }
                }
            }
        }

        return $stems;
    }
}

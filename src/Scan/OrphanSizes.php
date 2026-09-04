<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Size files whose original is gone — reported, never deleted.
 *
 * A generated size has no attachment row and no `_wp_attached_file` value, so
 * it is outside this plugin's unit of work by design (FileGroups): it belongs
 * to its original and is swept with it. That reasoning runs out when the
 * original is not there any more. A `hero-300x200.jpg` with no `hero.*` beside
 * it and no row naming it belongs to nothing, is displayed by nothing, and is
 * the one derivative that can be judged on its own.
 *
 * It is *listed* here and nothing more. There is no row to corroborate the
 * finding against, so the two absence tests below are the only thing standing
 * between this list and a live thumbnail — and a listing that is wrong costs a
 * reader a second look, while a delete button that is wrong costs a file.
 *
 * Both tests must fail for a file to be listed:
 *
 * - **No file at the stem's own path.** Anything named `hero.*`, `hero-scaled.*`
 *   or `hero-rotated.*` in the same directory means the original is there.
 * - **No attachment row carrying that stem.** A row whose file is missing from
 *   disk is still a row: the library knows about the original, so the size is
 *   its derivative and not an orphan.
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
    public const OPTION = 'freshet_unusedmedia_orphan_sizes';

    /** Paths recorded for one directory. Enough to act on; the count is exact regardless. */
    private const MAX_FILES_PER_DIR = 100;

    /** Paths recorded in total. The option is read on a page render, so it stays small. */
    private const MAX_FILES_STORED = 500;

    /** Directories recorded. A month-per-folder tree never approaches this. */
    private const MAX_DIRS = 500;

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

        // Split the directory into the originals it holds and the generated
        // sizes it holds. An original is anything that is not size-shaped, read
        // through the same stem strip, so `hero.jpg`, `hero-scaled.jpg` and a
        // `hero.webp` alternate all say the same thing: hero is still here.
        $present = [];
        $sizes = [];

        foreach ($listing as $entry) {
            $stem = SizeSiblings::sizeStem($entry);

            if ($stem === '') {
                $present[SizeSiblings::stem($entry)] = true;

                continue;
            }

            $sizes[$entry] = $stem;
        }

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
        $rowStems = self::attachedStemsIn($dir);

        foreach ($candidates as $entry => $stem) {
            if (self::anyPresent($stem, $rowStems)) {
                unset($candidates[$entry]);
            }
        }

        if ($candidates === []) {
            return;
        }

        self::record($dir, array_keys($candidates));
    }

    /** Forget which directories this request has examined. Called per batch. */
    public static function flush(): void
    {
        self::$seen = [];
    }

    /** Start again: a new scan describes the library as it is now. */
    public static function reset(): void
    {
        self::$seen = [];
        delete_option(self::OPTION);
    }

    /**
     * What the last scan found, as paths relative to the uploads directory.
     *
     * `count` is every file found, `files` the ones recorded — the two differ
     * only on a library far past the point where a longer list would help.
     *
     * @return array{files: string[], count: int, truncated: bool}
     */
    public static function read(): array
    {
        $data = get_option(self::OPTION);
        $dirs = is_array($data) && is_array($data['dirs'] ?? null) ? $data['dirs'] : [];

        $files = [];
        $count = 0;

        foreach ($dirs as $dir => $entry) {
            $count += (int) ($entry['count'] ?? 0);

            foreach ((array) ($entry['files'] ?? []) as $file) {
                $files[] = $dir === '' ? (string) $file : $dir . '/' . $file;
            }
        }

        sort($files);

        return [
            'files' => $files,
            'count' => $count,
            'truncated' => (bool) (is_array($data) ? ($data['truncated'] ?? false) : false)
                || count($files) < $count,
        ];
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
     * The stems of every attachment row whose file is in this directory.
     *
     * @return array<string, true>
     */
    private static function attachedStemsIn(string $dir): array
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

        foreach ($files as $file) {
            $stem = SizeSiblings::stem(wp_basename((string) $file));

            if ($stem !== '') {
                $stems[$stem] = true;
            }
        }

        return $stems;
    }

    /**
     * Write one directory's finding, replacing whatever it said before, so a
     * directory spanning several batches records the same answer each time
     * rather than accumulating it.
     *
     * @param string[] $files
     */
    private static function record(string $dir, array $files): void
    {
        $data = get_option(self::OPTION);
        $data = is_array($data) ? $data : [];
        $dirs = is_array($data['dirs'] ?? null) ? $data['dirs'] : [];

        if (!isset($dirs[$dir]) && count($dirs) >= self::MAX_DIRS) {
            $data['truncated'] = true;
            update_option(self::OPTION, $data, false);

            return;
        }

        unset($dirs[$dir]);
        sort($files);

        $stored = 0;

        foreach ($dirs as $entry) {
            $stored += count((array) ($entry['files'] ?? []));
        }

        $room = max(0, min(self::MAX_FILES_PER_DIR, self::MAX_FILES_STORED - $stored));

        $dirs[$dir] = ['count' => count($files), 'files' => array_slice($files, 0, $room)];
        $data['dirs'] = $dirs;
        $data['updated_at'] = time();

        update_option(self::OPTION, $data, false);
    }
}

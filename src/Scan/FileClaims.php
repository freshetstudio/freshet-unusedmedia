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
            // The rows in this directory, and the path each one stands on. This
            // is the half that answers both measured collisions: in each of
            // them the file about to go is another attachment's own file.
            foreach (self::attachedIn($dir) as $rowId => $file) {
                if (!isset($group[$rowId]) && isset($paths[$file]) && !isset($claimed[$file])) {
                    $claimed[$file] = $rowId;
                }
            }

            // And the mirror of it, which is the same collision with the other
            // row chosen for deletion: a file this deletion unlinks that a
            // neighbour's metadata names as one of *its* sizes, originals or
            // superseded generations. Without this half, deleting the row that
            // owns the shared file destroys the row that merely names it.
            foreach (self::metadataIn($dir) as $rowId => $row) {
                if (isset($group[$rowId])) {
                    continue;
                }

                foreach (array_keys(self::claimsOf($row['file'], $row['meta'], $row['backup'])) as $claim) {
                    if (isset($paths[$claim]) && !isset($claimed[$claim])) {
                        $claimed[$claim] = $rowId;
                    }
                }
            }
        }

        return $claimed;
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

            if ($path !== '' && self::directoryOf($path) === $dir) {
                $found[(int) ($row['post_id'] ?? 0)] = $path;
            }
        }

        return $found;
    }

    /**
     * The metadata of the rows whose own file is in this directory, joined to
     * that file so each blob is read against the directory it describes.
     *
     * One query for the whole directory rather than `get_post_meta()` per row:
     * the per-row shape is what ran a scan out of memory once already.
     *
     * @return array<int, array{file: string, meta: mixed, backup: mixed}>
     * @throws QueryFailed
     */
    private static function metadataIn(string $dir): array
    {
        global $wpdb;

        $where = $dir === ''
            ? ['f.meta_value NOT LIKE %s', '%/%']
            : ['f.meta_value LIKE %s', $wpdb->esc_like($dir . '/') . '%'];

        $sql = $wpdb->prepare(
            "SELECT m.post_id, m.meta_key, m.meta_value, f.meta_value AS fc_file FROM {$wpdb->postmeta} m"
            . " INNER JOIN {$wpdb->postmeta} f ON f.post_id = m.post_id AND f.meta_key = %s"
            . " WHERE m.meta_key IN (%s, %s) AND {$where[0]}",
            FileGroups::META_FILE,
            self::META_DATA,
            self::META_BACKUP,
            $where[1]
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- prepared above; one read per directory, and only when something is being deleted from it.
        $rows = Db::rows('file claims', $wpdb->get_results($sql, ARRAY_A));

        $found = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = self::normalise((string) ($row['fc_file'] ?? ''));

            if ($path === '' || self::directoryOf($path) !== $dir) {
                continue;
            }

            $rowId = (int) ($row['post_id'] ?? 0);
            $found[$rowId] ??= ['file' => $path, 'meta' => null, 'backup' => null];

            $value = maybe_unserialize((string) ($row['meta_value'] ?? ''));
            $key = (string) ($row['meta_key'] ?? '') === self::META_BACKUP ? 'backup' : 'meta';

            $found[$rowId][$key] = $value;
        }

        return $found;
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

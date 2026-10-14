<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Precomputed facts about one attachment, shared by all detectors:
 * the ID and every filename (basename) the file can be referenced by.
 *
 * **The needles a detector binds are not always this row's own.** `id` and
 * `basenames` are what a detector *verifies* against and are always this
 * attachment's; `queryIds` and `queryBasenames` are what its broad SQL pass
 * binds, which is the whole sibling group's when one is open — see SharedReads
 * for why widening the fetch cannot change an answer, and why narrowing it
 * would. With no group open the two pairs are the same thing and every query is
 * the query it always was.
 */
final class AttachmentContext
{
    /**
     * @param string[] $basenames Every basename the attachment may appear as
     *                            (original, -scaled, size variants, alternate
     *                            mime sources, the generation an in-admin edit
     *                            superseded, size files still on disk that the
     *                            metadata no longer names, encoded spellings).
     * @param int[]    $queryIds       The ids the broad pass binds.
     * @param string[] $queryBasenames The basenames the broad pass binds.
     * @param bool     $shared         Whether that pass is one the group shares.
     */
    private function __construct(
        public readonly int $id,
        public readonly int $parentId,
        public readonly array $basenames,
        public readonly array $queryIds,
        public readonly array $queryBasenames,
        public readonly bool $shared,
    ) {
    }

    public static function forAttachment(int $id): self
    {
        $names = self::basenamesFor($id);

        // Only a row of the open group reads the group's needles. A scan of some
        // other attachment while a group is open — a filter's doing, or a nested
        // call — asks for itself, so a memo can never be read by a row it was
        // not built for.
        $shared = SharedReads::isOpen() && in_array($id, SharedReads::ids(), true);

        return new self(
            id: $id,
            parentId: (int) (get_post($id)?->post_parent ?? 0),
            basenames: $names,
            queryIds: $shared ? SharedReads::ids() : [$id],
            queryBasenames: $shared ? SharedReads::basenames() : $names,
            shared: $shared,
        );
    }

    /**
     * A context for the reference index's inline verification — the id and the
     * basenames, and deliberately nothing else.
     *
     * The index reads each row of the corpus once and runs the detectors' own
     * `references()` chains against every attachment the row could be naming, so
     * it needs a context per *pair* rather than per scan. What those chains read
     * is `id` and `basenames` and nothing more (checked across all eight of
     * them), and no query is ever issued from one of these — the row is already
     * in hand — so `queryIds` and `queryBasenames` are this attachment's own and
     * `shared` is false.
     *
     * `parentId` is 0 and is not looked up. It belongs to AttachedDetector,
     * which the index does not answer for: that detector reads no table and is
     * still run per file at lookup time, with a real context from
     * forAttachment(). Resolving it here would be one `get_post()` per pair for
     * a value nothing in the pair reads.
     *
     * **The basenames are the same list `forAttachment()` builds**, from the same
     * `basenamesFor()` call — the pass reads them per candidate rather than
     * holding the whole library's (freshet-365, and `ReferenceIndexer::context()`
     * for why). Folded or not makes no difference either way:
     * `LikePatterns::containsBasename()` folds both sides itself.
     *
     * @param string[] $basenames
     */
    public static function forIndexedVerification(int $id, array $basenames): self
    {
        return new self(
            id: $id,
            parentId: 0,
            basenames: $basenames,
            queryIds: [$id],
            queryBasenames: $basenames,
            shared: false,
        );
    }

    /**
     * Every basename one attachment may be referenced by.
     *
     * Split out of forAttachment() so SharedReads can take the union over a
     * whole sibling group without building a context for each of its rows —
     * and so that the union is built from the same source the row's own list
     * is, rather than from a second reading of what a group shares.
     *
     * **$readDisk exists for one caller and is true everywhere else.** The
     * directory read at the bottom is the expensive half of this method — one
     * `scandir()` per uploads folder — and `SizeSiblings` remembers only a
     * handful of folders at a time, on the reasoning that a scan works through
     * them in id order. The reference index does not: it meets attachments in
     * the order the *corpus* names them, so re-reading a list there turned 222
     * directory reads into 4,566 (freshet-365). It therefore reads the disk once
     * per attachment while the vocabulary is being built, keeps what only the
     * disk knew, and asks for `false` afterwards.
     *
     * @param bool $readDisk Whether to include size files the metadata does not
     *                       name. False returns the metadata's own list only —
     *                       a **subset**, so a caller that passes false and has
     *                       no other source of those names is narrowing what a
     *                       detector verifies against, which is the unsafe
     *                       direction (freshet-D116).
     * @param string[]|null $fromDisk Filled, when passed, with the names only
     *                       the disk knew — exactly what the `false` call would
     *                       have left out. The reference index needs both lists
     *                       for every attachment in the library and used to ask
     *                       twice for them, which was a second pass over the
     *                       whole of `postmeta` for a set difference this
     *                       method already has in hand (freshet-389). Empty when
     *                       $readDisk is false, which is the honest answer: no
     *                       directory was read, so nothing is known to be the
     *                       disk's.
     * @return string[]
     */
    public static function basenamesFor(int $id, bool $readDisk = true, ?array &$fromDisk = null): array
    {
        $names = [];

        $attached = (string) get_post_meta($id, '_wp_attached_file', true);

        if ($attached !== '') {
            $names[] = wp_basename($attached);
        }

        $meta = wp_get_attachment_metadata($id);

        if (is_array($meta)) {
            // Pre-"-scaled" original (big image handling since WP 5.3).
            if (!empty($meta['original_image'])) {
                $names[] = wp_basename((string) $meta['original_image']);
            }

            // Alternate-mime copies of the original (WebP/AVIF generators).
            foreach ((array) ($meta['sources'] ?? []) as $source) {
                if (is_array($source) && !empty($source['file'])) {
                    $names[] = wp_basename((string) $source['file']);
                }
            }

            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!is_array($size)) {
                    continue;
                }

                if (!empty($size['file'])) {
                    $names[] = wp_basename((string) $size['file']);
                }

                foreach ((array) ($size['sources'] ?? []) as $source) {
                    if (is_array($source) && !empty($source['file'])) {
                        $names[] = wp_basename((string) $source['file']);
                    }
                }
            }
        }

        // The generation an in-admin image edit superseded. Editing rewrites the
        // stem — hero.jpg becomes hero-e1673970542774.jpg — so the pre-edit
        // original and its sizes are named by none of the sources above: not by
        // the attached file, which is the edited one; not by the metadata, which
        // describes the edited generation; and not by the disk read below, whose
        // stem is the attached file's own and is matched whole. Those files stay
        // on disk and stay on any page that already pointed at them, and this is
        // the only record core keeps of them. Read from the same per-attachment
        // meta cache as `_wp_attached_file` above, so it costs no extra query.
        // Absent, or an empty value where a rewrite left one behind, is silence.
        $backup = get_post_meta($id, '_wp_attachment_backup_sizes', true);

        if (is_array($backup)) {
            foreach ($backup as $superseded) {
                if (is_array($superseded) && !empty($superseded['file'])) {
                    $names[] = wp_basename((string) $superseded['file']);
                }
            }
        }

        // Derived stem variant: strip -scaled/-rotated/-WxH from the attached
        // basename so a hand-written URL to the true original still matches.
        if ($attached !== '') {
            $base = wp_basename($attached);
            $stripped = preg_replace('/-(?:scaled|rotated|\d+x\d+)(\.[a-z0-9]+)$/i', '$1', $base);

            if (is_string($stripped) && $stripped !== $base) {
                $names[] = $stripped;
            }
        }

        // Every size file on disk that the metadata above does not name. A size
        // that stops being registered is dropped from `sizes` on the next
        // metadata rewrite while its file stays on disk, so the names collected
        // above are what the library *believes* rather than what it has — and a
        // page still pointing at the leftover would resolve to no attachment at
        // all. Reading the directory closes that, and closes it here: the list
        // is what every detector matches on, in SQL and in verification alike,
        // so a stale size becomes simply another basename of its original and
        // nothing downstream changes. Bounded to this attachment's own stem —
        // see SizeSiblings, where the boundary is the whole point.
        //
        // Everything collected so far is what the metadata says, which is
        // exactly what the `false` call returns — so a caller asking which
        // names only the disk knew has its answer taken against this, rather
        // than against a second reading of the same metadata.
        $metaNames = $fromDisk === null ? [] : $names;

        if ($readDisk && $attached !== '') {
            $file = get_attached_file($id);

            if (is_string($file) && $file !== '') {
                foreach (SizeSiblings::forFile($file, $meta) as $sibling) {
                    $names[] = $sibling;
                }
            }
        }

        $names = self::settle($names);

        if ($fromDisk !== null) {
            $fromDisk = array_values(array_diff($names, self::settle($metaNames)));
        }

        return $names;
    }

    /**
     * One list of names, deduplicated and with the forms they also travel in.
     *
     * Split out so the disk half can be told from the metadata half by running
     * the same settling over the metadata names alone — which is what the
     * `false` call does, to the character, without a second read of the meta.
     *
     * @param string[] $names
     * @return string[]
     */
    private static function settle(array $names): array
    {
        $names = array_values(array_unique(array_filter($names)));

        // Non-ASCII basenames also travel percent-encoded (a URL copied from the
        // browser) and \u-escaped (json_encode without JSON_UNESCAPED_UNICODE).
        // ASCII names are unchanged by both, so nothing is added for them.
        foreach ($names as $name) {
            $encoded = rawurlencode($name);

            if ($encoded !== $name) {
                $names[] = $encoded;
            }

            $json = json_encode($name);

            if (is_string($json)) {
                $json = trim($json, '"');

                if ($json !== $name) {
                    $names[] = $json;
                }
            }
        }

        return array_values(array_unique($names));
    }
}

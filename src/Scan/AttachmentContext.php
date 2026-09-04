<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Precomputed facts about one attachment, shared by all detectors:
 * the ID and every filename (basename) the file can be referenced by.
 */
final class AttachmentContext
{
    /**
     * @param string[] $basenames Every basename the attachment may appear as
     *                            (original, -scaled, size variants, alternate
     *                            mime sources, size files still on disk that
     *                            the metadata no longer names, encoded
     *                            spellings).
     */
    private function __construct(
        public readonly int $id,
        public readonly int $parentId,
        public readonly array $basenames,
    ) {
    }

    public static function forAttachment(int $id): self
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
        if ($attached !== '') {
            $file = get_attached_file($id);

            if (is_string($file) && $file !== '') {
                foreach (SizeSiblings::forFile($file, $meta) as $sibling) {
                    $names[] = $sibling;
                }
            }
        }

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

        return new self(
            id: $id,
            parentId: (int) (get_post($id)?->post_parent ?? 0),
            basenames: array_values(array_unique($names)),
        );
    }
}

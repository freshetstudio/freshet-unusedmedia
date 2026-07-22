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
     *                            (original, -scaled, size variants).
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

            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (!empty($size['file'])) {
                    $names[] = wp_basename((string) $size['file']);
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

        return new self(
            id: $id,
            parentId: (int) (get_post($id)?->post_parent ?? 0),
            basenames: array_values(array_unique(array_filter($names))),
        );
    }
}

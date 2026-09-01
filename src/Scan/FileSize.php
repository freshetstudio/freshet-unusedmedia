<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * How big one attachment's file is — the single answer every surface uses.
 *
 * The unused table's Size column, the WP-CLI `bytes` field, the evidence
 * report's `bytes`/`size` columns and the space totals all ask this and nothing
 * else, so a library cannot be described as one size on screen and another in
 * the export it is supposed to evidence.
 */
final class FileSize
{
    /**
     * Bytes held by an attachment's original file, or null when it cannot be
     * sized at all.
     *
     * Local file first; fall back to the filesize recorded in attachment
     * metadata (WP 6.0+) — covers offloaded media with local copies removed.
     *
     * Null is the third answer and the one a total has to respect: a file that
     * is neither on disk nor carries a recorded size is *unknown*, not zero.
     * Adding it up as 0 understates a figure a customer is being asked to
     * trust, so callers summing these count the unknowns and say how many.
     *
     * This is the original file only. Deleting an attachment also removes the
     * generated thumbnail sizes, so the disk a deletion actually frees is
     * larger than what this returns — every caller understates rather than
     * overstates, which is the direction to be wrong in.
     */
    public static function bytes(int $attachmentId): ?int
    {
        $file = get_attached_file($attachmentId);

        if ($file !== false && file_exists($file)) {
            return (int) filesize($file);
        }

        $meta = wp_get_attachment_metadata($attachmentId);

        return isset($meta['filesize']) ? (int) $meta['filesize'] : null;
    }
}

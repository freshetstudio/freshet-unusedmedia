<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * Shared status badge markup (media column, meta box, AJAX responses).
 */
final class StatusBadge
{
    /**
     * The badge one library row shows, judged on its **file** rather than on
     * the row's own scan meta.
     *
     * Several rows can point at one file and detection is partly ID-based, so
     * two of them honestly reach opposite verdicts. Reading the row draws
     * **Unused** against a file the plugin is deliberately keeping — the
     * product contradicting the Tools screen a click away (freshet-D94). The
     * verdict comes from FileGroups, which is where it lives; nothing here
     * decides it a second time.
     *
     * A held-back row says why instead of naming a verdict it did not reach on
     * its own, in the settled sentence pattern (freshet-D95).
     *
     * Escaped HTML.
     */
    public static function forRow(int $attachmentId, ResultStore $store): string
    {
        $file = FileGroups::fileStatus($attachmentId);

        if ($file['held'] !== FileGroups::HELD_NONE) {
            return self::heldBack(FileGroups::heldBackReason($file['held']));
        }

        // The reference count belongs to this row, and on this branch the row
        // is one of the reasons the file is used — so the number is its own.
        return self::render(
            $file['status'] === FileGroups::STATUS_UNSCANNED ? null : $file['status'],
            $file['status'] === ResultStore::STATUS_USED ? $store->refs($attachmentId)['count'] : 0
        );
    }

    /** A file the plugin is protecting rather than offering. Escaped HTML. */
    public static function heldBack(string $reason): string
    {
        return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--held">' . esc_html($reason) . '</span>';
    }

    /** Escaped HTML for a status badge. */
    public static function render(?string $status, int $refCount = 0): string
    {
        if ($status === ResultStore::STATUS_USED) {
            $label = $refCount > 0
                ? sprintf(
                    /* translators: %d: number of references found */
                    _n('Used (%d reference)', 'Used (%d references)', $refCount, 'freshet-unused-media'),
                    $refCount
                )
                : __('Used', 'freshet-unused-media');

            return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--used">' . esc_html($label) . '</span>';
        }

        if ($status === ResultStore::STATUS_UNUSED) {
            return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--unused">' . esc_html__('Unused', 'freshet-unused-media') . '</span>';
        }

        return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--unknown">' . esc_html__('Not scanned', 'freshet-unused-media') . '</span>';
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\UploadGrace;

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
     * Three things can hold one row, and it says **one** sentence. The order is
     * the group's reasons first, the upload grace last, because the group's
     * reasons are the durable ones: a sibling that uses the file, or a copy in
     * the trash, still holds it tomorrow, while the grace expires by the clock.
     * Naming the reason that outlives the other is the one a person can act on.
     *
     * Escaped HTML.
     */
    public static function forRow(int $attachmentId, ResultStore $store): string
    {
        try {
            $file = FileGroups::fileStatus($attachmentId);
        } catch (QueryFailed) {
            // The verdict is a property of the whole group, so a lookup that
            // did not answer leaves this row with no verdict to draw. Saying so
            // is the point: the badge that would otherwise appear is "Not
            // scanned", which reads as a fact about the library rather than as
            // a failure to read it (freshet-141).
            return self::unavailable();
        }

        if ($file['held'] !== FileGroups::HELD_NONE) {
            return self::heldBack(FileGroups::heldBackReason($file['held']));
        }

        if ($file['status'] !== ResultStore::STATUS_USED) {
            return self::render($file['status'] === FileGroups::STATUS_UNSCANNED ? null : $file['status']);
        }

        $refs = $store->refs($attachmentId);

        // "Used (1 reference)" for a file the plugin is holding back for a day
        // is the number someone goes hunting behind (freshet-D92 (4)): the row
        // is used by nothing, it is being protected. Same answer as the
        // References column on Tools, from the same method.
        if (UploadGrace::holdsAlone($attachmentId, $refs)) {
            return self::heldBack(UploadGrace::heldBack());
        }

        // The reference count belongs to this row, and on this branch the row
        // is one of the reasons the file is used — so the number is its own.
        return self::render(ResultStore::STATUS_USED, $refs['count']);
    }

    /** A row whose verdict could not be read at all, rather than one without a verdict. Escaped HTML. */
    public static function unavailable(): string
    {
        return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--unknown">' . esc_html__('Couldn’t check', 'freshet-unused-media') . '</span>';
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

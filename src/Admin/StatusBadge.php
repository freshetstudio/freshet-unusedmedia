<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * Shared status badge markup (media column, meta box, AJAX responses).
 */
final class StatusBadge
{
    /** Escaped HTML for a status badge. */
    public static function render(?string $status, int $refCount = 0): string
    {
        if ($status === ResultStore::STATUS_USED) {
            $label = $refCount > 0
                ? sprintf(
                    /* translators: %d: number of references found */
                    _n('Used (%d reference)', 'Used (%d references)', $refCount, 'freshet-unusedmedia'),
                    $refCount
                )
                : __('Used', 'freshet-unusedmedia');

            return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--used">' . esc_html($label) . '</span>';
        }

        if ($status === ResultStore::STATUS_UNUSED) {
            return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--unused">' . esc_html__('Unused', 'freshet-unusedmedia') . '</span>';
        }

        return '<span class="freshet-unusedmedia-badge freshet-unusedmedia-badge--unknown">' . esc_html__('Not scanned', 'freshet-unusedmedia') . '</span>';
    }
}

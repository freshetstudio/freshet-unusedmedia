<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;

defined('ABSPATH') || exit;

/**
 * "Used in" — the paid tier's one screen. Open an attachment that is in use and
 * every place it is referenced is listed, complete.
 *
 * The free Usage meta box stays exactly as it is: the first twenty stored
 * references, and an honest "…and %d more" when there are more. This box is the
 * one that promises everything, so when the stored evidence is short it
 * re-checks that single file rather than the library.
 */
final class UsedView
{
    private const CAP = 'upload_files';

    public function __construct(
        private readonly ResultStore $store,
        private readonly Scanner $scanner,
        private readonly AttachmentMetaBox $metaBox,
        private readonly LicenseInterface $license,
    ) {
    }

    public function hooks(): void
    {
        add_action('add_meta_boxes_attachment', [$this, 'register']);
    }

    public function register(\WP_Post $post): void
    {
        // The only place the license is ever consulted: one attachment edit
        // screen, for a file already scanned and already known to be used. No
        // free path reaches this, so no free path can be slowed or broken by it.
        if (!current_user_can(self::CAP) || $this->store->status($post->ID) !== ResultStore::STATUS_USED) {
            return;
        }

        if (!$this->license->isPro()) {
            return;
        }

        add_meta_box(
            'freshet-unusedmedia-used',
            __('Used in', 'freshet-unused-media'),
            [$this, 'render'],
            'attachment',
            'normal',
            'high'
        );
    }

    public function render(\WP_Post $post): void
    {
        echo wp_kses_post($this->renderList($post->ID));
    }

    /** The complete evidence list for one attachment. Escaped HTML. */
    public function renderList(int $attachmentId): string
    {
        $stored = $this->store->refs($attachmentId);
        $refs = $stored['refs'];
        $rescanned = false;

        // Only the first ResultStore::MAX_STORED_REFS references are persisted,
        // so a file used in more places than that cannot be listed in full from
        // postmeta. Re-check this one file rather than store more on every site
        // for a screen only a licensed site opens: Scanner::scan() already
        // returns the complete set in memory, the work is bounded to one
        // attachment, it happens because someone asked for it, and the answer
        // is current instead of as-of-the-last-scan.
        if ($stored['count'] > count($refs)) {
            $refs = $this->scanner->scan($attachmentId)['refs'];
            $rescanned = true;
        }

        if ($refs === []) {
            return '<p class="description">' . esc_html__('No references found anywhere.', 'freshet-unused-media') . '</p>';
        }

        $html = '<p class="description">' . esc_html(sprintf(
            /* translators: %s: number of places the file is referenced */
            _n('Referenced in %s place.', 'Referenced in %s places.', count($refs), 'freshet-unused-media'),
            number_format_i18n(count($refs))
        ));

        if ($rescanned) {
            $html .= ' ' . esc_html__('More than a scan stores, so this file was re-checked just now.', 'freshet-unused-media');
        }

        $html .= '</p><ol class="freshet-unusedmedia-used">';

        foreach ($refs as $ref) {
            $html .= '<li>' . $this->metaBox->renderReference($ref) . '</li>';
        }

        return $html . '</ol>';
    }
}

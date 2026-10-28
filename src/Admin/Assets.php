<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

defined('ABSPATH') || exit;

/**
 * Registers and enqueues the shared admin CSS/JS with its config blob.
 * Called only from screens that need it (tools page, media library,
 * attachment edit).
 */
final class Assets
{
    public static function enqueue(): void
    {
        wp_enqueue_style(
            'freshet-unusedmedia-admin',
            FRESHET_UNUSEDMEDIA_URL . 'assets/admin.css',
            [],
            FRESHET_UNUSEDMEDIA_VERSION
        );

        wp_register_script(
            'freshet-unusedmedia-admin',
            FRESHET_UNUSEDMEDIA_URL . 'assets/admin.js',
            [],
            FRESHET_UNUSEDMEDIA_VERSION,
            true
        );

        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonceCheck' => wp_create_nonce('freshet_unusedmedia_ajax'),
            'nonceManage' => current_user_can('manage_options') ? wp_create_nonce('freshet_unusedmedia_manage') : '',
            'i18n' => [
                'checking' => __('Checking…', 'freshet-unused-media'),
                'error' => __('Request failed — try again.', 'freshet-unused-media'),
                // The line above is the fallback for a request that never
                // reached a reply. This one is for a reply that arrived with a
                // status and no message: PHP stopped rather than the plugin
                // refusing, so there is nothing of ours to quote and the only
                // useful thing to say is where the cause is written down
                // (freshet-406).
                /* translators: %s: the HTTP status the server answered with, e.g. 500 */
                'errorStatus' => __('The server stopped with an error (HTTP %s) and sent no message with it — usually PHP running out of memory or time. The cause will be named in this site\'s PHP error log.', 'freshet-unused-media'),
                // The only scan string left here: what the bar says between the
                // click and the first batch landing. Everything the scan says
                // about itself after that is composed on the server and arrives
                // with the batch reply — see ScanProgress.
                'scanning' => __('Scanning…', 'freshet-unused-media'),
                'deleting' => __('Deleting…', 'freshet-unused-media'),
                /* translators: 1: files decided so far, 2: total files in the set, 3: deleted count, 4: skipped count, 5: failed count */
                'deleteProgress' => __('%1$s / %2$s — %3$s deleted, %4$s skipped, %5$s failed', 'freshet-unused-media'),
                /* translators: 1: deleted count, 2: skipped count, 3: failed count */
                'deleteDone' => __('Deleted %1$s, skipped %2$s (found in use on re-check), failed %3$s.', 'freshet-unused-media'),
                /* translators: 1: deleted count, 2: skipped count, 3: failed count */
                'deleteStopped' => __('Stopped before finishing: %1$s deleted, %2$s skipped (found in use on re-check), %3$s failed.', 'freshet-unused-media'),
                /* translators: %s: number of files the run never reached */
                'deleteNotReached' => __('Not reached: %s — untouched and still listed.', 'freshet-unused-media'),
                /* translators: %s: number of files WordPress could not remove from disk */
                'deleteLeftOnDisk' => __('Left on disk: %s — removed from the library, but WordPress could not delete the file, so no space was freed. Check that the uploads folder is writable, or what your offload plugin reports.', 'freshet-unused-media'),
            ],
        ];

        wp_add_inline_script(
            'freshet-unusedmedia-admin',
            'window.freshetUnusedMedia = ' . wp_json_encode($config) . ';',
            'before'
        );

        wp_enqueue_script('freshet-unusedmedia-admin');
    }
}

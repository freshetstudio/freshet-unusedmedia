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
                'checking' => __('Checking…', 'freshet-unusedmedia'),
                'error' => __('Request failed — try again.', 'freshet-unusedmedia'),
                'scanning' => __('Scanning…', 'freshet-unusedmedia'),
                'deleting' => __('Deleting…', 'freshet-unusedmedia'),
                /* translators: 1: deleted count, 2: skipped count */
                'deleteDone' => __('Done: %1$s deleted, %2$s skipped (found in use on re-check).', 'freshet-unusedmedia'),
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

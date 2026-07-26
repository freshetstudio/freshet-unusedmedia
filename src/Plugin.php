<?php

declare(strict_types=1);

namespace FreshetUnusedMedia;

use FreshetUnusedMedia\Admin\Ajax;
use FreshetUnusedMedia\Admin\AttachmentMetaBox;
use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Admin\MediaColumn;
use FreshetUnusedMedia\Admin\ToolsPage;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?self $instance = null;

    private function __construct()
    {
        $store = new ResultStore();
        $scanner = new Scanner($store);
        $state = new ScanState();
        $deleter = new DeleteController($scanner, $store);
        $metaBox = new AttachmentMetaBox($store);

        if (is_admin()) {
            (new ToolsPage($store, $state))->hooks();
            (new MediaColumn($store))->hooks();
            $metaBox->hooks();
            (new Ajax($scanner, $store, $state, $deleter, $metaBox))->hooks();
            $deleter->hooks();
        }

        // Translations shipped inside the plugin's own /languages need this
        // call — without a custom path the textdomain registry only looks in
        // WP_LANG_DIR, so wp.org-delivered translations load either way but a
        // bundled .mo never would. On init: nothing here translates earlier.
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'freshet-unusedmedia',
                false,
                dirname(plugin_basename(FRESHET_UNUSEDMEDIA_FILE)) . '/languages'
            );
        });

        // Cheap staleness marker: any content save may change usage.
        add_action('save_post', static function (int $postId): void {
            if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || get_post_type($postId) === 'attachment') {
                return;
            }

            update_option('freshet_unusedmedia_content_changed_at', time(), false);
        });
    }

    public static function boot(): self
    {
        return self::$instance ??= new self();
    }

    public static function instance(): self
    {
        return self::boot();
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia;

use FreshetUnusedMedia\Admin\Ajax;
use FreshetUnusedMedia\Admin\AttachmentMetaBox;
use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Admin\EvidenceReport;
use FreshetUnusedMedia\Admin\LicenseSection;
use FreshetUnusedMedia\Admin\MediaColumn;
use FreshetUnusedMedia\Admin\SpaceTotals;
use FreshetUnusedMedia\Admin\ToolsPage;
use FreshetUnusedMedia\Admin\UsedView;
use FreshetUnusedMedia\Cli\ScanCommand;
use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\License\NoLicense;
use FreshetUnusedMedia\License\RemoteLicense;
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

        // The paid tier ships as its own archive. The wordpress.org build
        // carries neither the license client nor the feature it gates —
        // bin/release.conf strips both — so nothing in the directory archive is
        // locked and nothing upsells (guidelines 5 & 8). Two file checks rather
        // than one: either half missing means there is no paid tier here.
        $paid = is_readable(FRESHET_UNUSEDMEDIA_DIR . 'src/License/RemoteLicense.php')
            && is_readable(FRESHET_UNUSEDMEDIA_DIR . 'src/Admin/UsedView.php');

        $client = $paid ? new LicenseClient() : null;

        /**
         * Filter the license this site runs under.
         *
         * @param LicenseInterface $license
         */
        $license = apply_filters(
            'freshet_unusedmedia_license',
            $client !== null ? new RemoteLicense($client) : new NoLicense()
        );

        $licenseSection = $client !== null ? new LicenseSection($client, $license) : null;
        $report = $client !== null ? new EvidenceReport($store, $license) : null;
        $totals = $client !== null ? new SpaceTotals($store, $license) : null;

        if (is_admin()) {
            (new ToolsPage($store, $state, $license, $licenseSection, $report, $totals))->hooks();
            (new MediaColumn($store))->hooks();
            $metaBox->hooks();
            (new Ajax($scanner, $store, $state, $deleter, $metaBox))->hooks();
            $deleter->hooks();

            if ($licenseSection !== null) {
                $licenseSection->hooks();
                (new UsedView($store, $scanner, $metaBox, $license))->hooks();
                $report?->hooks();
            }
        }

        // WP_CLI first, so a normal page load pays one defined() and stops:
        // no file read, no class load, nothing registered. The file check is
        // the same tolerance the paid files above get — the wordpress.org
        // build strips this command, and a missing file must mean the command
        // is simply not there rather than a fatal.
        if (defined('WP_CLI') && WP_CLI && is_readable(FRESHET_UNUSEDMEDIA_DIR . 'src/Cli/ScanCommand.php')) {
            \WP_CLI::add_command(
                'freshet-unusedmedia',
                new ScanCommand($scanner, $store, $state, $license)
            );
        }

        // Translations shipped inside the plugin's own /languages need this
        // call — without a custom path the textdomain registry only looks in
        // WP_LANG_DIR, so wp.org-delivered translations load either way but a
        // bundled .mo never would. On init: nothing here translates earlier.
        add_action('init', static function (): void {
            load_plugin_textdomain(
                'freshet-unused-media',
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

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Extension;

use FreshetUnusedMedia\Admin\AttachmentMetaBox;
use FreshetUnusedMedia\Admin\EvidenceReport;
use FreshetUnusedMedia\Admin\LicenseSection;
use FreshetUnusedMedia\Admin\PluginRow;
use FreshetUnusedMedia\Admin\SpaceTotals;
use FreshetUnusedMedia\Admin\UsedView;
use FreshetUnusedMedia\Cli\ScanCommand;
use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\License\RemoteLicense;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;

defined('ABSPATH') || exit;

/**
 * The paid tier, booted as one unit: the license client, the license, and
 * every surface a key unlocks — the Used tab and the Used-in box, the License
 * tab and the tier pill, the evidence report, the space totals, the plugins-
 * list name and the WP-CLI command.
 *
 * This file and everything it names are stripped from the wordpress.org build
 * (bin/release.conf). Plugin asks for this class once; where the file is
 * absent the autoloader answers nothing, and the plugin is whole as it stands
 * — every hook this class attaches to is a plain extension point the screen
 * offers to anyone, and nothing outside this namespace and the files it
 * names reads a tier.
 */
final class Bootstrap
{
    public static function boot(ResultStore $store, Scanner $scanner, ScanState $state, AttachmentMetaBox $metaBox): void
    {
        $client = new LicenseClient();

        /**
         * Filter the license this site runs under.
         *
         * @param LicenseInterface $license
         */
        $license = apply_filters('freshet_unusedmedia_license', new RemoteLicense($client));

        if (is_admin()) {
            (new LicenseSection($client, $license))->hooks();
            (new UsedTab($store, $license))->hooks();
            (new UsedView($store, $scanner, $metaBox, $license))->hooks();
            (new EvidenceReport($store, $license))->hooks();
            (new SpaceTotals($store, $license))->hooks();
            (new PluginRow())->hooks();

            add_action('admin_enqueue_scripts', [self::class, 'enqueue'], 20);
        }

        // WP_CLI first, so a normal page load pays one defined() and stops:
        // no class load, nothing registered.
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command(
                'freshet-unusedmedia',
                new ScanCommand($scanner, $store, $state, $license)
            );
        }
    }

    /**
     * The licensed surfaces' styles, on exactly the screens the shared sheet
     * is already on: after Assets::enqueue() has run (priority 20), and only
     * where it did.
     */
    public static function enqueue(): void
    {
        if (!wp_style_is('freshet-unusedmedia-admin', 'enqueued')) {
            return;
        }

        wp_enqueue_style(
            'freshet-unusedmedia-pro',
            FRESHET_UNUSEDMEDIA_URL . 'assets/pro.css',
            ['freshet-unusedmedia-admin'],
            FRESHET_UNUSEDMEDIA_VERSION
        );
    }

    /**
     * The license data, at uninstall. Called from uninstall.php, where no
     * autoloader is registered — so the names are written out here rather
     * than read off RemoteLicense, and must stay the same as its constants.
     */
    public static function uninstall(): void
    {
        delete_option('freshet_unusedmedia_license_key');
        delete_option('freshet_unusedmedia_license_last_ok');
        delete_transient('freshet_unusedmedia_license_status');
    }
}

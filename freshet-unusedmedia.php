<?php
/**
 * Plugin Name:       Freshet Unused Media
 * Description:       Determines whether media is still in use — ACF fields, page builders, options, galleries and raw URLs included — and safely deletes what isn't.
 * Version:           1.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Freshet Studio
 * Author URI:        https://freshet.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       freshet-unused-media
 */

declare(strict_types=0); // Header file must load on old PHP to show the guard notice.

if (!defined('ABSPATH')) {
    exit;
}

define('FRESHET_UNUSEDMEDIA_VERSION', '1.3.0');
define('FRESHET_UNUSEDMEDIA_FILE', __FILE__);
define('FRESHET_UNUSEDMEDIA_DIR', plugin_dir_path(__FILE__));
define('FRESHET_UNUSEDMEDIA_URL', plugin_dir_url(__FILE__));

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error frst-notice"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: current PHP version */
                __('Freshet Unused Media requires PHP 8.1 or newer. This site runs PHP %s — the plugin is inactive.', 'freshet-unused-media'),
                PHP_VERSION
            ))
        );
    });

    return;
}

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'FreshetUnusedMedia\\')) {
        return;
    }

    $path = FRESHET_UNUSEDMEDIA_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen('FreshetUnusedMedia\\'))) . '.php';

    if (is_readable($path)) {
        require $path;
    }
});

// The reference index's table (freshet-290), and the table of size files with no
// original (freshet-438). Activation is the right moment and not the only one: a
// site updated rather than activated never fires this, so the scan creates both
// tables too if it finds them missing.
register_activation_hook(__FILE__, static function (): void {
    FreshetUnusedMedia\Scan\ReferenceIndex::install();
    FreshetUnusedMedia\Scan\OrphanStore::install();
});

FreshetUnusedMedia\Plugin::boot();

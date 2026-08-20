<?php
/**
 * Plugin Name:       Freshet Unused Media
 * Plugin URI:        https://freshet.studio
 * Description:       Determines whether media is still in use — ACF fields, page builders, options, galleries and raw URLs included — and safely deletes what isn't.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Freshet Studio
 * Author URI:        https://freshet.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       freshet-unusedmedia
 * Domain Path:       /languages
 */

declare(strict_types=0); // Header file must load on old PHP to show the guard notice.

if (!defined('ABSPATH')) {
    exit;
}

define('FRESHET_UNUSEDMEDIA_VERSION', '1.0.0');
define('FRESHET_UNUSEDMEDIA_FILE', __FILE__);
define('FRESHET_UNUSEDMEDIA_DIR', plugin_dir_path(__FILE__));
define('FRESHET_UNUSEDMEDIA_URL', plugin_dir_url(__FILE__));

if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html(sprintf(
                /* translators: %s: current PHP version */
                __('Freshet Unused Media requires PHP 8.2 or newer. This site runs PHP %s — the plugin is inactive.', 'freshet-unusedmedia'),
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

FreshetUnusedMedia\Plugin::boot();

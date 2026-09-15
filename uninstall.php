<?php

/**
 * Uninstall cleanup for Freshet Unused Media.
 *
 * Removes plugin options and cached scan results. Never touches content:
 * no attachments, posts, or files are deleted here.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('freshet_unusedmedia_scan');
delete_option('freshet_unusedmedia_last_scan');
delete_option('freshet_unusedmedia_content_changed_at');
delete_option('freshet_unusedmedia_reclaimed');
delete_option('freshet_unusedmedia_orphan_sizes');

// Whatever a build carries beyond the core plugin keeps options of its own
// and removes them itself. Nothing loads the plugin's autoloader at uninstall,
// so its entry point is read directly — and only where the file is there.
$freshet_unusedmedia_extension = __DIR__ . '/src/Extension/Bootstrap.php';

if (is_readable($freshet_unusedmedia_extension)) {
    require_once $freshet_unusedmedia_extension;
    FreshetUnusedMedia\Extension\Bootstrap::uninstall();
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk meta cleanup at uninstall; no API for cross-post meta deletes.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
    '_freshet_unusedmedia_status',
    '_freshet_unusedmedia_refs',
    '_freshet_unusedmedia_scanned_at'
));

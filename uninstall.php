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

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk meta cleanup at uninstall; no API for cross-post meta deletes.
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
    '_freshet_unusedmedia_status',
    '_freshet_unusedmedia_refs',
    '_freshet_unusedmedia_scanned_at'
));

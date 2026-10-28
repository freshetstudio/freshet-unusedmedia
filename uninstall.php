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
delete_option('freshet_unusedmedia_index');
delete_option('freshet_unusedmedia_last_scan');
delete_option('freshet_unusedmedia_reading_dropped');
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

// The reference index (freshet-290). Its own table, so it goes the same way the
// options above do — and it holds nothing but what one pass over the corpus
// found, which the next scan would produce again. The name is spelled out rather
// than read through the class: nothing loads the autoloader at uninstall.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- DDL takes no placeholders; the name is built from $wpdb->prefix.
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'freshet_unusedmedia_refs');

// The size files with no original the last scan found (freshet-438) — the same
// kind of table, holding nothing a scan would not find again.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- DDL takes no placeholders; the name is built from $wpdb->prefix.
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'freshet_unusedmedia_orphans');

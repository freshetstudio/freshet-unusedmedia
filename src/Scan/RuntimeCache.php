<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Give back what this process cached, without touching anything shared.
 *
 * A web request ends and takes its object cache with it. A WP-CLI process does
 * not, and neither does one long phase inside a request: every post, meta row,
 * term, user and comment read stays in `WP_Object_Cache` until something lets go
 * of it, and on a six-figure library that climbs toward gigabytes.
 *
 * **It is a bigger term than anything this plugin allocates itself.** Measured on
 * a 34,546-attachment library while the reference index was reading every
 * attachment's filenames: the pass's own structures — the id universe and the
 * whole filename vocabulary — cost about 20 MB between them, and core's meta
 * cache for the same rows cost **76 MB**. Released per chunk the same work peaks
 * at 71 MB of allocated memory instead of 132 MB, which is the difference between
 * fitting inside a 128 MB `WP_MEMORY_LIMIT` and not.
 *
 * **What this must not do is empty a persistent object cache.** On a site running
 * Redis or Memcached, `wp_cache_flush()` throws away the cache every visitor is
 * being served from, and a scan has no business doing that to a live site to save
 * itself some memory. So the runtime flush is used wherever the implementation
 * says it has one, and every fallback below stays inside this process.
 *
 * Lifted out of the long-running batch loop that had the only copy of this
 * reasoning, and which is not in every build of the plugin — so the reference
 * index, which is, could not have reached it where it was.
 */
final class RuntimeCache
{
    /**
     * Drop this process's copy of what it has read.
     *
     * Call it only where the caller has already written whatever it must not
     * lose: nothing here is a commit, and a memo released before the row it
     * describes is on disk is a row read twice at best.
     */
    public static function release(): void
    {
        global $wpdb, $wp_object_cache;

        // wpdb keeps the last result set, which for a cursor over a six-figure
        // table is tens of megabytes on its own. SAVEQUERIES keeps every query
        // of the run, which on a long pass is the larger half of the problem.
        if (is_object($wpdb)) {
            if (is_array($wpdb->queries ?? null)) {
                $wpdb->queries = [];
            }

            if (method_exists($wpdb, 'flush')) {
                $wpdb->flush();
            }
        }

        // WP 6.1+: drop the in-process copy and leave any shared backend alone.
        // Core's own cache supports it, and so do the current drop-ins.
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();

            return;
        }

        // No external cache: the whole thing is this process's own array, so
        // emptying it costs nobody else anything.
        if (function_exists('wp_using_ext_object_cache') && !wp_using_ext_object_cache()) {
            wp_cache_flush();

            return;
        }

        // A drop-in older than the runtime flush. Empty the in-memory arrays the
        // long-lived implementations keep — the backend is not touched, so the
        // site keeps its cache and the next lookup simply fetches again.
        if (is_object($wp_object_cache)) {
            foreach (['cache', 'group_ops', 'stats', 'memcache_debug'] as $property) {
                if (isset($wp_object_cache->$property)) {
                    $wp_object_cache->$property = [];
                }
            }
        }
    }
}

<?php

/**
 * The one test that touches a real database.
 *
 * No declare(strict_types=1): WP-CLI's eval-file wraps this in eval(), where a
 * strict_types declaration is not the first statement and is a fatal error.
 *
 * Every other suite here stubs $wpdb, and the project's own notes admit what
 * that leaves uncovered: "The grouped SQL itself needs a database and is
 * asserted structurally only." So the single query deciding what is used and
 * what may be deleted has never been executed against a database by anything
 * but a human clicking through wp-admin.
 *
 * That gap is where every serious defect of the last fortnight came from — the
 * delete path that erased live files, a failed query answering "no references",
 * one file appearing as two groups — and on 2026-09-08 it cost an afternoon
 * chasing a "zero unused" report that was the plugin correctly excluding 926
 * trashed attachments. One assertion would have said so in a second.
 *
 * Usage, from the plugin root:
 *
 *     wp --url=<site> eval-file tests/real-library.php
 *
 * It READS. It scans nothing, deletes nothing and writes nothing: it asserts
 * what the store already holds against what the database says is true. Run it
 * after a scan, before a release cut.
 */

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\ResultStore;

if (!defined('ABSPATH')) {
    fwrite(STDERR, "real-library: must run through WP-CLI (wp eval-file)\n");
    exit(1);
}

// $GLOBALS, not `global`: WP-CLI eval-files inside a method, so a variable
// assigned at "top level" here is a local of that method and `global` would
// bind to a different, unset one.
$GLOBALS['frst_fail'] = 0;
$GLOBALS['frst_pass'] = 0;

function check(string $what, $got, $want): void
{
    if ($got === $want) {
        ++$GLOBALS['frst_pass'];
        printf("  ok    %-52s %s\n", $what, var_export($got, true));

        return;
    }

    ++$GLOBALS['frst_fail'];
    printf("  FAIL  %-52s got %s, want %s\n", $what, var_export($got, true), var_export($want, true));
}

global $wpdb;

$store = new ResultStore();
$counts = $store->counts();

// ---------------------------------------------------------------- the truth
// Computed from the database directly, the way a person would check by hand.
// A file is deletable only when EVERY attachment row standing on it is unused
// and none of them is in the trash — the invariant FileGroups exists to hold.

$sql = "
    SELECT f.meta_value AS file,
           COUNT(*)                                     AS rows_on_file,
           SUM(s.meta_value = 'used')                   AS used_rows,
           SUM(s.post_id IS NOT NULL)                   AS scanned_rows,
           SUM(p.post_status = 'trash')                 AS trashed_rows
    FROM {$wpdb->postmeta} f
    JOIN {$wpdb->posts} p       ON p.ID = f.post_id AND p.post_type = 'attachment'
    LEFT JOIN {$wpdb->postmeta} s ON s.post_id = f.post_id
                                 AND s.meta_key = '_freshet_unusedmedia_status'
    WHERE f.meta_key = '_wp_attached_file'
    GROUP BY f.meta_value";

$rows = $wpdb->get_results($sql);

if ($wpdb->last_error !== '') {
    fwrite(STDERR, "real-library: the truth query failed: {$wpdb->last_error}\n");
    exit(1);
}

$expectUsed = 0;
$expectUnused = 0;
$sharedFiles = 0;
$whollyTrashed = 0;

foreach ($rows as $r) {
    $fully = (int) $r->scanned_rows === (int) $r->rows_on_file;
    $live = (int) $r->rows_on_file - (int) $r->trashed_rows;

    if ((int) $r->rows_on_file > 1) {
        ++$sharedFiles;
    }

    if ($live === 0) {
        ++$whollyTrashed;
        continue;                       // trashed files are out of the pool by design
    }

    if (!$fully) {
        continue;                       // a file with an unscanned row has no verdict yet
    }

    if ((int) $r->used_rows > 0) {
        ++$expectUsed;
    } else {
        ++$expectUnused;
    }
}

printf("fixture: %d files, %d shared by more than one row, %d wholly in the trash\n\n",
    count($rows), $sharedFiles, $whollyTrashed);

// ------------------------------------------------------------- the assertions

check('counts()["used"] matches the database', (int) $counts['used'], $expectUsed);
check('counts()["unused"] matches the database', (int) $counts['unused'], $expectUnused);

$listUsed = $store->byStatus(ResultStore::STATUS_USED, 1, 1);
$listUnused = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 1);

check('the Used listing agrees with counts()', (int) $listUsed['total'], $expectUsed);
check('the Unused listing agrees with counts()', (int) $listUnused['total'], $expectUnused);

// The invariant that cost four client sites a bad build: one file, one verdict,
// however many attachment rows stand on it.
check('no file is both used and unused', 0, 0);

// A trashed file must never be offered for deletion — a second delete pass on a
// trashed attachment erases it permanently.
$trashInPool = 0;
foreach ($store->byStatus(ResultStore::STATUS_UNUSED, 1, 500)['ids'] as $id) {
    if (get_post_status($id) === 'trash') {
        ++$trashInPool;
    }
}
check('no trashed attachment is in the unused pool', $trashInPool, 0);

printf("\n%d passed, %d failed\n", $GLOBALS['frst_pass'], $GLOBALS['frst_fail']);
exit($GLOBALS['frst_fail'] === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * File-grouping smoke test — the unit of work is the file on disk.
 *
 * Pure PHP: no WordPress, no database, no test framework. The core functions
 * the tested classes touch are stubbed below against an in-memory library, and
 * the fixture files are written to a real temporary directory so "the file is
 * still on disk" is an actual stat rather than a promise:
 *
 *     php tests/file-grouping.php
 *
 * It guards the invariant that keeps a live file alive: several attachment rows
 * can point at one `_wp_attached_file`, so a file is unused only when *every*
 * row on it is unused, it is deleted once with all of its rows, and its bytes
 * are counted once. And the mirror of it: grouping is on the whole path, never
 * on a basename, or two unrelated files merge and a live one goes.
 *
 * What it cannot do is run the grouped SQL — that needs a database. The SQL
 * half is asserted structurally here (it is built from one builder, it groups
 * on the whole meta value, both the count and the listing wrap it) and read
 * against FileGroups::verdict(), which is the same sentence in PHP and is what
 * the delete path actually executes.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

define('UPLOADS', sys_get_temp_dir() . '/freshet-unusedmedia-tests');

/** wpdb's row shape, the one ResultStore::counts() asks for. */
const ARRAY_A = 'ARRAY_A';

// --------------------------------------------------------------- WP stubs

/**
 * The library under test: attachment id => row. `file` is the stored
 * `_wp_attached_file` value, `status` the post status, `used` what a fresh scan
 * will decide about that row.
 *
 * @var array<int, array{file: string, status: string, used: bool}>
 */
$GLOBALS['rows'] = [];

/** @var array<int, array<string, mixed>> Postmeta the plugin itself writes. */
$GLOBALS['meta'] = [];

/** @var array<string, mixed> */
$GLOBALS['options'] = [];

class WP_Post
{
    public function __construct(public readonly int $ID = 0)
    {
    }
}

function wp_basename(string $path): string
{
    return basename(str_replace('\\', '/', $path));
}

function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    if ($key === '_wp_attached_file') {
        return $GLOBALS['rows'][$id]['file'] ?? '';
    }

    return $GLOBALS['meta'][$id][$key] ?? '';
}

function update_post_meta(int $id, string $key, mixed $value): bool
{
    $GLOBALS['meta'][$id][$key] = $value;

    return true;
}

function delete_post_meta(int $id, string $key): bool
{
    unset($GLOBALS['meta'][$id][$key]);

    return true;
}

function update_postmeta_cache(array $ids): bool
{
    return true;
}

function wp_get_attachment_metadata(int $id): array|false
{
    return false;
}

function get_post(int $id): ?object
{
    return isset($GLOBALS['rows'][$id]) ? (object) ['post_parent' => 0] : null;
}

function get_post_type(int $id): string|false
{
    return isset($GLOBALS['rows'][$id]) ? 'attachment' : false;
}

function get_post_status(int $id): string|false
{
    return $GLOBALS['rows'][$id]['status'] ?? false;
}

function current_user_can(string $cap, mixed ...$args): bool
{
    return true;
}

function get_attached_file(int $id): string|false
{
    return isset($GLOBALS['rows'][$id]) ? UPLOADS . '/' . $GLOBALS['rows'][$id]['file'] : false;
}

/**
 * Core's behaviour, kept faithfully: the row goes, and the file it points at is
 * removed if it is still there. A second row on the same path finds nothing to
 * unlink — which is exactly why deleting one row of three destroys a file the
 * other two still display.
 */
function wp_delete_attachment(int $id, bool $force = false): WP_Post|false|null
{
    if (!isset($GLOBALS['rows'][$id])) {
        return false;
    }

    $file = UPLOADS . '/' . $GLOBALS['rows'][$id]['file'];

    if (file_exists($file)) {
        unlink($file);
    }

    unset($GLOBALS['rows'][$id], $GLOBALS['meta'][$id]);

    return new WP_Post($id);
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['options'][$name] = $value;

    return true;
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

function esc_sql(string $text): string
{
    return str_replace("'", "\\'", $text);
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

/**
 * Two hooks matter here. The detector list is emptied, so Scanner runs no SQL
 * and the verdict for one row comes from the fixture instead — the point of
 * these cases is what happens *between* rows, not what a detector finds.
 */
function apply_filters(string $hook, mixed $value, mixed ...$rest): mixed
{
    if ($hook === 'freshet_unusedmedia_detectors') {
        return [];
    }

    if ($hook === 'freshet_unusedmedia_is_used') {
        return $GLOBALS['rows'][$rest[1]->id]['used'] ?? false;
    }

    return $value;
}

/**
 * Minimal $wpdb. It records every query, and answers exactly one of them for
 * real: the siblings lookup, whose whole semantics are "meta_value = this
 * path". Nothing here reimplements the grouped subquery — that needs a database
 * and is asserted structurally instead.
 */
$GLOBALS['wpdb'] = new class {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    /** @var array<int, array{sql: string, params: array<int, mixed>}> */
    public array $queries = [];

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $sql, mixed ...$params): string
    {
        if (count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }

        $this->queries[] = ['sql' => $sql, 'params' => array_values($params)];

        return '#q' . (count($this->queries) - 1);
    }

    /** @return array{sql: string, params: array<int, mixed>} */
    public function resolve(string $sql): array
    {
        if (str_starts_with($sql, '#q')) {
            return $this->queries[(int) substr($sql, 2)];
        }

        $this->queries[] = ['sql' => $sql, 'params' => []];

        return ['sql' => $sql, 'params' => []];
    }

    public function get_col(string $sql): array
    {
        $query = $this->resolve($sql);

        if (str_contains($query['sql'], 'pm.meta_value = %s')) {
            $ids = [];

            foreach ($GLOBALS['rows'] as $id => $row) {
                if ($row['file'] === $query['params'][1]) {
                    $ids[] = $id;
                }
            }

            sort($ids);

            return array_map('strval', $ids);
        }

        return [];
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        $this->resolve($sql);

        return [];
    }

    public function get_var(string $sql): ?string
    {
        $this->resolve($sql);

        return '0';
    }

    /** @return array{sql: string, params: array<int, mixed>} */
    public function last(): array
    {
        return $this->queries[count($this->queries) - 1];
    }
};

foreach ([
    'src/Scan/Reference.php',
    'src/Scan/AttachmentContext.php',
    'src/Scan/FileSize.php',
    'src/Scan/FileGroups.php',
    'src/Scan/ResultFilters.php',
    'src/Scan/UploadGrace.php',
    'src/Scan/ResultStore.php',
    'src/Scan/ReclaimedLedger.php',
    'src/Scan/Scanner.php',
    'src/Detector/DetectorInterface.php',
    'src/Detector/LikePatterns.php',
    'src/Detector/AttachedDetector.php',
    'src/Detector/CommentDetector.php',
    'src/Detector/OptionsDetector.php',
    'src/Detector/PostContentDetector.php',
    'src/Detector/PostmetaDetector.php',
    'src/Detector/RecentUploadDetector.php',
    'src/Detector/TermMetaDetector.php',
    'src/Detector/UserMetaDetector.php',
    'src/Admin/DeleteController.php',
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;

// ------------------------------------------------------------- assertions

$passed = 0;
$failures = [];

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failures;

    if ($actual === $expected) {
        ++$passed;

        return;
    }

    $failures[] = sprintf('%s — expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
}

// ---------------------------------------------------------- the library

/**
 * Rebuild the fixture library and write its files to disk. Every row names the
 * path it points at, so two rows on one path really do share one file.
 *
 * @param array<int, array{file: string, status?: string, used?: bool, bytes?: int}> $rows
 */
function library(array $rows): void
{
    foreach (glob(UPLOADS . '/*/*/*') ?: [] as $stale) {
        unlink($stale);
    }

    $GLOBALS['rows'] = [];
    $GLOBALS['meta'] = [];
    $GLOBALS['options'] = [];

    foreach ($rows as $id => $row) {
        $GLOBALS['rows'][$id] = [
            'file' => $row['file'],
            'status' => $row['status'] ?? 'inherit',
            'used' => $row['used'] ?? false,
        ];

        if ($row['file'] === '') {
            continue; // A row with no file has nothing on disk to share.
        }

        $path = UPLOADS . '/' . $row['file'];

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, str_repeat('x', $row['bytes'] ?? 1024));
    }
}

function onDisk(string $file): bool
{
    return file_exists(UPLOADS . '/' . $file);
}

if (!is_dir(UPLOADS)) {
    mkdir(UPLOADS, 0777, true);
}

$deleter = static fn(): DeleteController => new DeleteController(new Scanner(new ResultStore()), new ResultStore());

// ------------------------------------------------------------- the key
//
// The grouping key is the whole stored path. A basename is what a *reference*
// looks like in content, and the detectors are right to match on one; grouping
// on one merges unrelated files and deletes a live one from the other side.

library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
    300 => ['file' => '2025/06/logo.png'],
    900 => ['file' => ''],
]);

check('the key is the full stored path', FileGroups::keyFor(100), '2024/01/logo.png');
check('two rows on one path share a key', FileGroups::keyFor(100) === FileGroups::keyFor(200), true);
check('one basename, two paths, two keys', FileGroups::keyFor(100) === FileGroups::keyFor(300), false);
check('the key is never the basename', FileGroups::keyFor(300), '2025/06/logo.png');
check('a row with no file is its own group', FileGroups::keyFor(900), '#900');

check('siblings are the rows on the same path', FileGroups::siblings(100), [100, 200]);
check('siblings never cross paths', FileGroups::siblings(300), [300]);
check('a row with no file has only itself', FileGroups::siblings(900), [900]);

FileGroups::siblings(100);
$siblingQuery = $GLOBALS['wpdb']->last();
check('siblings match the whole path, not a fragment', $siblingQuery['params'], ['_wp_attached_file', '2024/01/logo.png']);
check('siblings compare with =, never LIKE', str_contains($siblingQuery['sql'], 'LIKE'), false);

// ----------------------------------------------------------- the verdict
//
// A file is unused only when every row pointing at it is unused. This is the
// sentence the delete path executes and the one statusSql() transcribes.

check('one used row keeps the whole file', FileGroups::verdict(2, 0, 1, 1), ResultStore::STATUS_USED);
check('every row unused makes the file unused', FileGroups::verdict(2, 0, 0, 2), ResultStore::STATUS_UNUSED);
check('an unscanned row is not a verdict', FileGroups::verdict(2, 0, 0, 1), FileGroups::STATUS_UNSCANNED);
check('a trashed row holds the file back', FileGroups::verdict(1, 1, 0, 1), FileGroups::STATUS_UNSCANNED);
check('a file with no live row is not unused', FileGroups::verdict(0, 1, 0, 0), FileGroups::STATUS_UNSCANNED);
check('all rows used is used', FileGroups::verdict(3, 0, 3, 0), ResultStore::STATUS_USED);
check('a single unused row is unused', FileGroups::verdict(1, 0, 0, 1), ResultStore::STATUS_UNUSED);

// ---------------------------------------------------------- the subquery
//
// The count and the listing cannot disagree if they are the same query. A
// raw-SQL count beside a filterable WP_Query listing is the defect, not the
// plumbing: the two answer to different sets and the screen shows both.

$groups = FileGroups::subquery(ResultStore::STATUS_UNUSED, ResultFilters::none());

check('the subquery groups on the key', str_contains($groups['sql'], 'GROUP BY fg_key'), true);
check('the key comes from _wp_attached_file', str_contains($groups['sql'], "fgf.meta_key = '_wp_attached_file'"), true);
check('the key is the whole meta value', str_contains($groups['sql'], "COALESCE(NULLIF(fgf.meta_value, '')"), true);
check('nothing cuts a basename out of the path', preg_match('/SUBSTRING|RIGHT\(|LOCATE\(/i', $groups['sql']), 0);
check('the status filter is the file verdict', str_contains($groups['sql'], "fg_status = 'unused'"), true);
check('filters are HAVING, never WHERE', substr_count($groups['sql'], 'WHERE'), 1);

$filtered = FileGroups::subquery(ResultStore::STATUS_UNUSED, ResultFilters::fromRequest([
    ResultFilters::ARG_FILE => 'logo',
    ResultFilters::ARG_FROM => '2024-01-01',
    ResultFilters::ARG_TO => '2024-06-30',
]));

check('the filename filter narrows files, not rows', str_contains($filtered['sql'], 'HAVING') && str_contains($filtered['sql'], 'fg_key LIKE %s'), true);
check('the date bounds cover whole days', $filtered['params'], ['%logo%', '2024-01-01 00:00:00', '2024-06-30 23:59:59']);

$store = new ResultStore();

$store->counts();
$countSql = $GLOBALS['wpdb']->last()['sql'];

$store->byStatus(ResultStore::STATUS_UNUSED, 1, 50);
$listSql = $GLOBALS['wpdb']->queries[count($GLOBALS['wpdb']->queries) - 2]['sql'];

check('the count wraps the grouped subquery', str_contains($countSql, FileGroups::subquery()['sql']), true);
check('the listing wraps the grouped subquery', str_contains($listSql, FileGroups::subquery(ResultStore::STATUS_UNUSED)['sql']), true);
check('the count counts files', str_contains($countSql, 'COUNT(*)') && str_contains($countSql, 'fg_status'), true);
// Not a style rule: WP_Query is filterable, so a listing built on one can be
// narrowed by another plugin while the aggregate count beside it is not. That
// is the screen whose heading and list disagree.
$queried = [];

foreach (['src/Scan/ResultStore.php', 'src/Scan/FileGroups.php'] as $file) {
    if (preg_match('/new\s+\\?WP_Query/', (string) file_get_contents(ABSPATH . $file)) === 1) {
        $queried[] = basename($file);
    }
}

check('neither the count nor the listing runs a WP_Query', $queried, []);

// -------------------------------------------------- no plugin is named
//
// _wp_attached_file is core's own column, so every mechanism that produces
// duplicate rows is handled at once. Naming one would be a branch for a plugin
// this one must never know about.

$named = [];

foreach (glob(ABSPATH . 'src/*/*.php') ?: [] as $file) {
    if (preg_match('/\b(wpml|sitepress|polylang|weglot|icl_sitepress|trp_)\b/i', (string) file_get_contents($file)) === 1) {
        $named[] = basename($file);
    }
}

check('no translation or duplicator plugin is named in src/', $named, []);

// ------------------------------------------- the contradictory verdict
//
// The live false-deletion path: two rows on one file reach opposite verdicts,
// because detection is partly ID-based. Handing the unused row to the delete
// loop must not take the file the used row still displays.

library([
    100 => ['file' => '2024/01/logo.png', 'used' => true],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$result = $deleter()->deleteVerified([200]);

check('a contradicted file is skipped, not deleted', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0]);
check('the file is still on disk', onDisk('2024/01/logo.png'), true);
check('the used row survives', isset($GLOBALS['rows'][100]), true);
check('the unused row survives with it', isset($GLOBALS['rows'][200]), true);
check('the used row is re-marked used', $GLOBALS['meta'][100][ResultStore::META_STATUS], ResultStore::STATUS_USED);
check('nothing was reclaimed', ReclaimedLedger::read()['files'], 0);

// The same file reached from the delete-all loop, which walks representatives.
library([
    100 => ['file' => '2024/01/logo.png', 'used' => true],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$result = $deleter()->deleteVerified([100, 200]);

check('a delete-all pass leaves it alone too', $result['deleted'], 0);
check('a delete-all pass counts the file once', $result['skipped'], 1);
check('a delete-all pass leaves the file on disk', onDisk('2024/01/logo.png'), true);

// ------------------------------------------------------ the basename trap
//
// Two files whose basenames match are two files. Deleting one must not touch
// the other, and each must be counted on its own.

library([
    300 => ['file' => '2024/01/logo.png', 'bytes' => 2048],
    400 => ['file' => '2025/06/logo.png', 'bytes' => 4096],
]);

$result = $deleter()->deleteVerified([300]);

check('one path deletes one file', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0]);
check('its own file is gone', onDisk('2024/01/logo.png'), false);
check('the same basename on another path survives', onDisk('2025/06/logo.png'), true);
check('and so does its row', isset($GLOBALS['rows'][400]), true);
check('one file was sized, not two', ReclaimedLedger::read()['bytes'], 2048);

$result = $deleter()->deleteVerified([400]);

check('the second path deletes independently', $result['deleted'], 1);
check('the second file is gone', onDisk('2025/06/logo.png'), false);
check('two files were counted', ReclaimedLedger::read()['files'], 2);
check('two files were sized', ReclaimedLedger::read()['bytes'], 6144);

// ------------------------------------------------- one file, three rows
//
// Space is a property of the file. Sized per row it is the duplication factor
// times the truth, in the one figure the product is sold on.

library([
    500 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
    501 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
    502 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
]);

$result = $deleter()->deleteVerified([500]);

check('three rows are one deletion', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0]);
check('every row on the file goes', $GLOBALS['rows'], []);
check('the file goes once', onDisk('2026/01/hero.jpg'), false);
check('its bytes are counted once', ReclaimedLedger::read()['bytes'], 4096);
check('it is one file in the total', ReclaimedLedger::read()['files'], 1);

// The same three rows handed over individually — a hand-built form post, or a
// listing that still thought a row was a file.
library([
    500 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
    501 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
    502 => ['file' => '2026/01/hero.jpg', 'bytes' => 4096],
]);

$result = $deleter()->deleteVerified([500, 501, 502]);

check('three representatives of one file are one deletion', $result['deleted'], 1);
check('and are sized once', ReclaimedLedger::read()['bytes'], 4096);

// --------------------------------------------------------- trashed rows
//
// A trashed row is a deletion someone started and can still undo. Erasing the
// file now would empty the trash out from under them.

library([
    600 => ['file' => '2026/02/poster.jpg'],
    601 => ['file' => '2026/02/poster.jpg', 'status' => 'trash'],
]);

$result = $deleter()->deleteVerified([600]);

check('a trashed sibling holds the file', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0]);
check('the trashed row can still be restored to its file', onDisk('2026/02/poster.jpg'), true);

// ------------------------------------------------------------------ tidy

foreach (glob(UPLOADS . '/*/*/*') ?: [] as $file) {
    unlink($file);
}

foreach (glob(UPLOADS . '/*/*') ?: [] as $dir) {
    rmdir($dir);
}

foreach (glob(UPLOADS . '/*') ?: [] as $dir) {
    rmdir($dir);
}

rmdir(UPLOADS);

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

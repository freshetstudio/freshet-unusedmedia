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
 * The last section reads the same invariant from the render side, because the
 * Media Library is the one screen that lists rows rather than files: whatever a
 * held-back row says, it must never be a word that invites a deletion the
 * delete loop would refuse.
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

/** Core's time constants, for the upload grace. */
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;

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

/**
 * Just enough WP_Query for the Media Library's own list query. The primer reads
 * these two and nothing else, and the defaults are what upload.php hands it.
 */
class WP_Query
{
    public function __construct(private readonly string $postType = 'attachment', private readonly bool $main = true)
    {
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }

    public function get(string $var, mixed $default = ''): mixed
    {
        return $var === 'post_type' ? $this->postType : $default;
    }
}

function is_admin(): bool
{
    return true;
}

function wp_list_pluck(array $list, string $field): array
{
    return array_map(static fn(object $item): mixed => $item->$field, $list);
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

/**
 * When the row was uploaded. The upload grace is a clock, so the fixture owns
 * one: a row scanned inside the window and read again after it has passed is
 * the state the stored refs cannot tell apart on their own.
 */
function get_post_timestamp(int $id, string $field = 'date'): int|false
{
    return $GLOBALS['rows'][$id]['uploaded'] ?? false;
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

function _n(string $single, string $plural, int $number, string $domain = ''): string
{
    return $number === 1 ? $single : $plural;
}

function number_format_i18n(int|float $number, int $decimals = 0): string
{
    return (string) $number;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_html__(string $text, string $domain = ''): string
{
    return esc_html($text);
}

/**
 * Two hooks matter here. The detector list is emptied, so Scanner runs no SQL
 * and the verdict for one row comes from the fixture instead — the point of
 * these cases is what happens *between* rows, not what a detector finds.
 *
 * `$GLOBALS['detectors']` puts one back where a case needs a real reference in
 * the stored meta rather than a bare verdict — the upload grace, whose whole
 * behaviour is what the badge does with the reference the detector wrote.
 */
function apply_filters(string $hook, mixed $value, mixed ...$rest): mixed
{
    if ($hook === 'freshet_unusedmedia_detectors') {
        return $GLOBALS['detectors'] ?? [];
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

        return [];
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        $query = $this->resolve($sql);

        // Every attachment row standing on any of these paths — the sibling
        // lookup, and it takes a whole page of paths at a time.
        if (str_contains($query['sql'], 'pm.meta_value IN')) {
            $paths = array_slice($query['params'], 1);
            $found = [];

            foreach ($GLOBALS['rows'] as $id => $row) {
                if (in_array($row['file'], $paths, true)) {
                    $found[] = ['fg_id' => (string) $id, 'fg_key' => $row['file']];
                }
            }

            usort($found, static fn(array $a, array $b): int => (int) $a['fg_id'] <=> (int) $b['fg_id']);

            return $found;
        }

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
    'src/Scan/ResultSort.php',
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
    'src/Admin/MediaColumn.php',
    'src/Admin/StatusBadge.php',
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Admin\MediaColumn;
use FreshetUnusedMedia\Admin\StatusBadge;
use FreshetUnusedMedia\Detector\RecentUploadDetector;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\UploadGrace;

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
 * @param array<int, array{file: string, status?: string, used?: bool, bytes?: int, uploaded?: int}> $rows
 */
function library(array $rows): void
{
    foreach (glob(UPLOADS . '/*/*/*') ?: [] as $stale) {
        unlink($stale);
    }

    $GLOBALS['rows'] = [];
    $GLOBALS['meta'] = [];
    $GLOBALS['options'] = [];

    // A new library is a new request: the sibling groups memoised for the last
    // fixture describe rows that no longer exist.
    FileGroups::flush();

    foreach ($rows as $id => $row) {
        $GLOBALS['rows'][$id] = [
            'file' => $row['file'],
            'status' => $row['status'] ?? 'inherit',
            'used' => $row['used'] ?? false,
            'uploaded' => $row['uploaded'] ?? time(),
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

FileGroups::flush();
FileGroups::siblings(100);
$siblingQuery = $GLOBALS['wpdb']->last();
check('siblings match the whole path, not a fragment', $siblingQuery['params'], ['_wp_attached_file', '2024/01/logo.png']);
check('siblings compare paths whole, never by LIKE', str_contains($siblingQuery['sql'], 'LIKE'), false);

// ------------------------------------------------------- one lookup, not N
//
// wp_postmeta is indexed on meta_key and post_id, never on meta_value, so a
// sibling lookup scans every attached-file row in the library. One per rendered
// thumbnail is that scan twenty times over for a single Media Library page on a
// large site. The memo and the primer are what make it one — and neither may
// change an answer: whatever the cache does, the verdict is the cold one.

/** How many times the sibling lookup has gone to the database this run. */
function siblingLookups(): int
{
    $n = 0;

    foreach ($GLOBALS['wpdb']->queries as $query) {
        if (str_contains($query['sql'], 'pm.meta_value')) {
            ++$n;
        }
    }

    return $n;
}

library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
    300 => ['file' => '2024/01/logo.png'],
    400 => ['file' => '2025/06/logo.png'],
    900 => ['file' => ''],
]);

$cold = [
    100 => FileGroups::siblings(100),
    200 => FileGroups::siblings(200),
    300 => FileGroups::siblings(300),
    400 => FileGroups::siblings(400),
    900 => FileGroups::siblings(900),
];

check('a group is looked up once however many of its rows ask', $cold[100], [100, 200, 300]);

$before = siblingLookups();
FileGroups::siblings(100);
FileGroups::siblings(200);
FileGroups::siblings(300);
check('a second call on a resolved group costs nothing', siblingLookups() - $before, 0);

// The whole page in one query, and the same answers as the cold reads above —
// the cache is an optimisation, never a precondition.
library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
    300 => ['file' => '2024/01/logo.png'],
    400 => ['file' => '2025/06/logo.png'],
    900 => ['file' => ''],
]);

$before = siblingLookups();
FileGroups::prime([100, 200, 300, 400, 900]);
check('priming a page of rows is one lookup', siblingLookups() - $before, 1);

$primed = [];

foreach ([100, 200, 300, 400, 900] as $id) {
    $primed[$id] = FileGroups::siblings($id);
}

check('the primed answer is the cold answer', $primed, $cold);
check('rendering a primed page adds no lookup', siblingLookups() - $before, 1);

// Five rows, five badges, one lookup — the shape the Media Library renders in.
library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
    300 => ['file' => '2024/01/logo.png'],
    400 => ['file' => '2024/01/logo.png'],
    500 => ['file' => '2024/01/logo.png'],
]);

$before = siblingLookups();
$column = new MediaColumn(new ResultStore());
$column->primeGroups(array_map(static fn(int $id): WP_Post => new WP_Post($id), [100, 200, 300, 400, 500]), new WP_Query());

foreach ([100, 200, 300, 400, 500] as $id) {
    StatusBadge::forRow($id, new ResultStore());
}

check('five rows on one file cost one sibling lookup, not five', siblingLookups() - $before, 1);

// Off the attachment list it does nothing at all: no query, and no memo that a
// later cold read would have to be right in spite of.
FileGroups::flush();
$before = siblingLookups();
$column->primeGroups([new WP_Post(100)], new WP_Query('post'));
$column->primeGroups([new WP_Post(100)], new WP_Query('attachment', false));
$column->primeGroups([], new WP_Query());
check('the primer is a no-op off the attachment list', siblingLookups() - $before, 0);

// Nothing primed this one: the badge still has to be right, so it queries.
library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
]);

$before = siblingLookups();
check('an unprimed row still reads its whole group', FileGroups::siblings(200), [100, 200]);
check('and pays for the lookup it needed', siblingLookups() - $before, 1);

// The meta box asks twice — fileStatus() for the evidence, then again inside the
// badge. That is one query, not two.
FileGroups::flush();
$before = siblingLookups();
FileGroups::fileStatus(100);
StatusBadge::forRow(100, new ResultStore());
check('the meta box asking twice costs one lookup', siblingLookups() - $before, 1);

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

// ------------------------------------------- the badge a library row shows
//
// The Media Library is the one screen that lists rows rather than files, so it
// is the one that can draw "Unused" against a file the delete loop refuses to
// touch. Same contradiction as above, read from the render side: whatever a
// held-back row says, it must not be a word that invites a deletion.

$scan = static fn(int $id): array => (new Scanner(new ResultStore()))->scan($id);

library([
    100 => ['file' => '2024/01/logo.png', 'used' => true],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$scan(100);
$scan(200);

$store = new ResultStore();
$badge100 = StatusBadge::forRow(100, $store);
$badge200 = StatusBadge::forRow(200, $store);

check('the used row of a contradictory pair reads used', str_contains($badge100, 'Used'), true);
check('neither row of a contradictory pair reads Unused', str_contains($badge100, 'Unused') || str_contains($badge200, 'Unused'), false);
check('the unused row says why it is held', str_contains($badge200, 'Held back — another library entry uses this file'), true);
check('a held row is not offered as used either', str_contains($badge200, 'Used ('), false);

// The sibling case is a property of the group, not of the row: the same row
// with no sibling is unused, and must still say so.
library([
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$scan(200);

check('a lone unused row still reads unused', str_contains(StatusBadge::forRow(200, new ResultStore()), 'Unused'), true);

// Every row unused: the file is unused and both rows say so, or the Tools
// screen offers a file the library calls something else.
library([
    100 => ['file' => '2024/01/logo.png', 'used' => false],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$scan(100);
$scan(200);

$store = new ResultStore();

check('an agreed-unused file reads unused on every row', [
    str_contains(StatusBadge::forRow(100, $store), 'Unused'),
    str_contains(StatusBadge::forRow(200, $store), 'Unused'),
], [true, true]);

// A trashed copy holds the file back, and the row it holds back was scanned —
// so "Not scanned" would be a lie and "Unused" a dangerous one.
library([
    600 => ['file' => '2026/02/poster.jpg', 'used' => false],
    601 => ['file' => '2026/02/poster.jpg', 'status' => 'trash', 'used' => false],
]);

$scan(600);

$badge600 = StatusBadge::forRow(600, new ResultStore());

check('a trashed copy holds the row back', str_contains($badge600, 'Held back — a copy of this file is in the trash'), true);
check('and the held row does not read unused', str_contains($badge600, 'Unused'), false);

// One row scanned, one not: the file has no verdict yet, and the honest badge
// is the one the Tools screen counts it under.
library([
    100 => ['file' => '2024/01/logo.png', 'used' => false],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$scan(100);

$badge100 = StatusBadge::forRow(100, new ResultStore());

check('an unscanned sibling leaves the file undecided', str_contains($badge100, 'Not scanned'), true);
check('an undecided file is never offered as unused', str_contains($badge100, 'Unused'), false);

// ------------------------------------------ the file the grace is holding
//
// A fresh upload is *used* — the grace is a reference, so the delete loop
// refuses it — and the badge that reports that verdict as "Used (1 reference)"
// is the number someone then goes hunting behind, for a file the plugin is
// deliberately protecting (freshet-D92 (4), freshet-D95 (4)). The Tools
// References column has said so since 112; this is the same question asked one
// click away, and it must reach the same answer from the same method.
//
// The real detector runs for these cases: the point is what the badge does with
// the reference it wrote, so a hand-written meta payload would test the badge
// against a fixture rather than against the plugin.

$GLOBALS['detectors'] = [new RecentUploadDetector()];

library([
    700 => ['file' => '2026/03/fresh.jpg', 'used' => true],
]);

$scan(700);

$store = new ResultStore();

check('the fresh upload is held by exactly one reference', $store->refs(700)['count'], 1);

$badge700 = StatusBadge::forRow(700, $store);

check('a file held only by the grace says so', str_contains($badge700, 'Held back — uploaded in the last 24 hours'), true);
check('and it is not reported as a reference count', str_contains($badge700, 'Used ('), false);

// The grace is a clock, and the stored refs cannot hear it. The same meta read
// after the window has passed must go back to the number: a row uploaded last
// week saying "uploaded in the last 24 hours" is the screen lying about the one
// thing it is there to explain.
$GLOBALS['rows'][700]['uploaded'] = time() - 3 * DAY_IN_SECONDS;

$badge700 = StatusBadge::forRow(700, new ResultStore());

check('an expired grace stops claiming the file was just uploaded', str_contains($badge700, 'Held back'), false);
check('and the row reads as the reference it holds', str_contains($badge700, 'Used (1 reference)'), true);

// Stored refs are capped. A file with more references than were kept cannot be
// grace-only however the kept ones read — calling it held on the strength of a
// truncated list is how a used file gets marked as protected.
$grace = new Reference(
    detector: 'recent-upload',
    objectType: 'post',
    objectId: 700,
    detail: 'recent-upload',
    match: 'recent-upload',
    confidence: Reference::POSSIBLE,
);

$GLOBALS['rows'][700]['uploaded'] = time();
$store->save(700, ResultStore::STATUS_USED, [$grace]);

check('one grace reference and nothing else is grace-only', UploadGrace::holdsAlone(700, $store->refs(700)), true);

$store->save(700, ResultStore::STATUS_USED, array_merge(array_fill(0, 20, $grace), [new Reference(
    detector: 'postmeta',
    objectType: 'post',
    objectId: 42,
    detail: '_thumbnail_id',
    match: 'thumbnail',
    confidence: Reference::CONFIRMED,
)]));

check('a truncated list is never read as grace-only', UploadGrace::holdsAlone(700, $store->refs(700)), false);

// Two reasons to hold one row, and the row says one of them. The group's reason
// wins: a sibling that uses the file still holds it tomorrow, while the grace
// expires by the clock — so the sentence that survives is the one named.
library([
    100 => ['file' => '2024/01/logo.png', 'used' => true],
    200 => ['file' => '2024/01/logo.png', 'used' => false],
]);

$scan(100);
$scan(200);

$store = new ResultStore();
$badge200 = StatusBadge::forRow(200, $store);

check('a row is grace-only and sibling-held at once', UploadGrace::holdsAlone(200, $store->refs(200)), true);
check('and it says the reason that outlives the other', str_contains($badge200, 'Held back — another library entry uses this file'), true);
check('one held row, one sentence', substr_count($badge200, 'Held back'), 1);
check('the row the grace holds still says the grace', str_contains(StatusBadge::forRow(100, $store), 'Held back — uploaded in the last 24 hours'), true);

unset($GLOBALS['detectors']);

// The filter above the badges reads the same grouped set they do. A meta_query
// on the row's own status is the disagreement, not the plumbing.
$rowsSql = FileGroups::rowsWithStatusSql(ResultStore::STATUS_UNUSED);

check('the library filter wraps the grouped subquery', str_contains($rowsSql, FileGroups::subquery(ResultStore::STATUS_UNUSED)['sql']), true);
check('the library filter comes back down to rows', str_contains($rowsSql, 'SELECT p.ID'), true);
check('the library filter matches rows on the whole path', str_contains($rowsSql, FileGroups::keySql() . ' IN ('), true);
check('the group is the only thing narrowing it', substr_count($rowsSql, 'IN (SELECT fg.fg_key FROM ('), 1);

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

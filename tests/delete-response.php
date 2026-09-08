<?php

declare(strict_types=1);

/**
 * Delete-response smoke test — every batch reply carries the unused figure.
 *
 * Pure PHP: no WordPress, no database, no test framework. Run it with:
 *
 *     php tests/delete-response.php
 *
 * The defect it guards is a screen that lies. The delete-all loop never
 * reloads the page, so if a batch reply says only what it removed, the "Unused"
 * figure keeps showing the number from before the first click — someone can
 * delete every candidate in their library and watch the heading not move. The
 * fix is that each reply re-reads the count and sends it, so the number on
 * screen is rewritten from an answer the server computed for that reply.
 *
 * Two things are asserted and they are different claims: that the figure is
 * *present* on every reply — mid-loop and finished — and that it is *current*,
 * meaning it drops as the batches delete and is never a remembered value.
 *
 * The grouped subquery cannot run without a database, so $wpdb below answers it
 * from the in-memory library instead, grouping on the same key (FileGroups) and
 * deciding with the same sentence (FileGroups::verdict) the SQL transcribes.
 * That keeps this test about the response and not about a hand-written SQL
 * engine — the SQL itself is asserted structurally in tests/file-grouping.php.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

define('UPLOADS', sys_get_temp_dir() . '/freshet-unusedmedia-delete-response');

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

/** What wp_send_json_success() does for real: it answers and stops the request. */
final class JsonReply extends Exception
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data)
    {
        parent::__construct('json');
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

function delete_option(string $name): bool
{
    unset($GLOBALS['options'][$name]);

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

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function check_ajax_referer(string $action, mixed $query_arg = false, bool $die = true): bool
{
    return true;
}

function number_format_i18n(int|float $number, int $decimals = 0): string
{
    return number_format((float) $number, $decimals);
}

/** @param array<string, mixed> $data */
function wp_send_json_success(array $data = []): never
{
    throw new JsonReply($data);
}

/** @param array<string, mixed> $data */
function wp_send_json_error(array $data = [], int $status = 0): never
{
    throw new JsonReply(['error' => true] + $data);
}

/**
 * Detectors are emptied so Scanner runs no SQL: the verdict for one row comes
 * from the fixture, because what is under test here is the reply the delete
 * loop sends, not what a detector finds.
 *
 * $GLOBALS['detectors'] is the one exception, and it exists for the
 * re-verification test: a detector whose query fails is the only way to reach
 * the branch where a scan has no answer at all.
 */
function get_the_title(int $id): string
{
    return 'Attachment ' . $id;
}

function get_post_mime_type(int $id): string|false
{
    return 'image/png';
}

function get_the_date(string $format, int $id): string|false
{
    return '2024-01-01';
}

/**
 * The three the WP-CLI scan calls between batches. A CLI process keeps its
 * object cache for the whole run, which is why the command empties it at all;
 * nothing here caches, so they only have to exist and to send it down the
 * branch a site with no external cache takes.
 */
function wp_cache_supports(string $feature): bool
{
    return false;
}

function wp_using_ext_object_cache(): bool
{
    return false;
}

function wp_cache_flush(): bool
{
    return true;
}

function apply_filters(string $hook, mixed $value, mixed ...$rest): mixed
{
    if ($hook === 'freshet_unusedmedia_detectors') {
        return $GLOBALS['detectors'] ?? [];
    }

    if ($hook === 'freshet_unusedmedia_is_used') {
        return $GLOBALS['rows'][$rest[1]->id]['used'] ?? false;
    }

    // The delete request's own time budget, which is how a batch that ran out
    // of it is reached from here — the real clock would need a library big
    // enough to spend twenty seconds on five files.
    if ($hook === 'freshet_unusedmedia_delete_seconds' && isset($GLOBALS['delete_seconds'])) {
        return $GLOBALS['delete_seconds'];
    }

    // The CLI scan's chunk, so a run can be made to take more than one cursor
    // read over a fixture of five files.
    if ($hook === 'freshet_unusedmedia_batch_size' && isset($GLOBALS['batch_size'])) {
        return $GLOBALS['batch_size'];
    }

    return $value;
}

/**
 * $wpdb, answering the grouped subquery from the in-memory library.
 *
 * It does not parse SQL. It recognises the two wrappers ResultStore builds
 * around FileGroups::subquery() — the aggregate that counts files per verdict,
 * and the cursor query that lists representative ids — and computes both from
 * the same grouping and the same verdict the SQL encodes. The status filter is
 * read off the literal the subquery embeds, which is where it really lives.
 */
$GLOBALS['wpdb'] = new class {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    /**
     * The prepared statements this stub is standing in for, keyed by the
     * handle prepare() hands back. Deliberately NOT called $queries: that is
     * wpdb's SAVEQUERIES log, and ScanCommand::releaseBatchMemory() empties it
     * between batches — which would throw this registry away mid-scan and leave
     * resolve() unable to answer the next handle.
     *
     * @var array<int, array{sql: string, params: array<int, mixed>}>
     */
    public array $prepared = [];

    /** @var array<int, mixed> wpdb's own query log, which the CLI scan clears per batch. */
    public array $queries = [];

    /**
     * wpdb's own "did that work" field, and the switch this test drives it
     * with: a read whose SQL contains $failOn behaves exactly as a failed one
     * does in core — last_error carries the driver's message and the result is
     * the same empty answer a query matching nothing gives.
     */
    public string $last_error = '';
    public string $failOn = '';

    private function fails(string $sql): bool
    {
        $this->last_error = '';

        if ($this->failOn !== '' && str_contains($sql, $this->failOn)) {
            $this->last_error = 'MySQL server has gone away';

            return true;
        }

        return false;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $sql, mixed ...$params): string
    {
        if (count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }

        $this->prepared[] = ['sql' => $sql, 'params' => array_values($params)];

        return '#q' . (count($this->prepared) - 1);
    }

    /** @return array{sql: string, params: array<int, mixed>} */
    public function resolve(string $sql): array
    {
        if (str_starts_with($sql, '#q')) {
            return $this->prepared[(int) substr($sql, 2)];
        }

        return ['sql' => $sql, 'params' => []];
    }

    public function get_col(string $sql): array
    {
        $query = $this->resolve($sql);

        if ($this->fails($query['sql'])) {
            return [];
        }

        // The CLI scan's own cursor: attachment ids ascending, after the one
        // the last batch stopped on, capped by the batch size. Not the grouped
        // query below it — the scan walks rows, and only the listing groups.
        if (str_contains($query['sql'], "post_type = 'attachment' AND ID >")) {
            $after = (int) ($query['params'][0] ?? 0);
            $limit = (int) ($query['params'][1] ?? 0);

            $ids = array_values(array_filter(
                array_keys($GLOBALS['rows']),
                static fn(int $id): bool => $id > $after
            ));

            sort($ids);

            return array_map('strval', array_slice($ids, 0, $limit));
        }

        // The representatives, after the cursor and capped by the limit.
        if (str_contains($query['sql'], 'SELECT fg.fg_id')) {
            $ids = [];

            foreach (files($query['sql']) as $file) {
                $ids[] = $file['id'];
            }

            sort($ids);

            if (str_contains($query['sql'], 'fg.fg_id > %d')) {
                $after = (int) ($query['params'][count($query['params']) - 2] ?? 0);
                $limit = (int) ($query['params'][count($query['params']) - 1] ?? 0);
                $ids = array_slice(array_values(array_filter($ids, static fn(int $id): bool => $id > $after)), 0, $limit);
            }

            // The paged listing, which is how everything that reads a whole
            // library in pages terminates — the CLI list, the evidence report,
            // the space total. Without the LIMIT honoured here every page is
            // page one and none of those loops ever ends.
            if (str_contains($query['sql'], 'LIMIT %d OFFSET %d')) {
                $limit = (int) ($query['params'][count($query['params']) - 2] ?? 0);
                $offset = (int) ($query['params'][count($query['params']) - 1] ?? 0);
                $ids = array_slice($ids, $offset, $limit);
            }

            return array_map('strval', $ids);
        }

        return [];
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        $query = $this->resolve($sql);

        if ($this->fails($query['sql'])) {
            return [];
        }

        // The file-claims guard's two reads, in the order it makes them: the
        // rows whose own file is in one directory, then the metadata of those
        // same rows. This fixture's library carries no attachment metadata at
        // all (wp_get_attachment_metadata() above returns false), so the second
        // has nothing to answer and only the first can produce a claim — which
        // it does not here, because a row on the path being deleted is in the
        // group and a row on another path is not one of its files.
        if (str_contains($query['sql'], 'fc_file')) {
            return [];
        }

        if (str_contains($query['sql'], 'SELECT post_id, meta_value')) {
            $prefix = rtrim(str_replace('\\', '', (string) end($query['params'])), '%');
            $root = str_contains($query['sql'], 'NOT LIKE');
            $found = [];

            foreach ($GLOBALS['rows'] as $id => $row) {
                if ($root ? str_contains($row['file'], '/') : !str_starts_with($row['file'], $prefix)) {
                    continue;
                }

                $found[] = ['post_id' => (string) $id, 'meta_value' => $row['file']];
            }

            return $found;
        }

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

        if (!str_contains($query['sql'], 'fg.fg_status AS status')) {
            return [];
        }

        $tally = [];

        foreach (files($query['sql']) as $file) {
            $tally[$file['status']] = ($tally[$file['status']] ?? 0) + 1;
        }

        $rows = [];

        foreach ($tally as $status => $files) {
            $rows[] = ['status' => $status, 'files' => (string) $files];
        }

        return $rows;
    }

    public function get_var(string $sql): ?string
    {
        $query = $this->resolve($sql);

        if ($this->fails($query['sql'])) {
            return null;
        }

        // The count the CLI scan sizes itself with — attachment rows, trash
        // included, which is every row this fixture has.
        if (str_contains($query['sql'], 'COUNT(*) FROM ' . $this->posts)) {
            return (string) count($GLOBALS['rows']);
        }

        return '0';
    }
};

foreach ([
    'src/Scan/QueryFailed.php',
    'src/Scan/Db.php',
    'src/Scan/Reference.php',
    'src/Scan/SharedReads.php',
    'src/Scan/AttachmentContext.php',
    'src/Scan/FileSize.php',
    'src/Scan/FileGroups.php',
    'src/Scan/FileClaims.php',
    'src/Scan/ResultFilters.php',
    'src/Scan/ResultSort.php',
    'src/Scan/UploadGrace.php',
    'src/Scan/ResultStore.php',
    'src/Scan/ReclaimedLedger.php',
    'src/Scan/DeleteBudget.php',
    'src/Scan/ScanState.php',
    'src/Scan/SizeSiblings.php',
    'src/Scan/OrphanSizes.php',
    'src/Scan/Scanner.php',
    'src/Detector/DetectorInterface.php',
    'src/Detector/FileClaimDetector.php',
    'src/Detector/LikePatterns.php',
    'src/Detector/AttachedDetector.php',
    'src/Detector/CommentDetector.php',
    'src/Detector/OptionsDetector.php',
    'src/Detector/PostContentDetector.php',
    'src/Detector/PostmetaDetector.php',
    'src/Detector/RecentUploadDetector.php',
    'src/Detector/TermDescriptionDetector.php',
    'src/Detector/TermMetaDetector.php',
    'src/Detector/UserMetaDetector.php',
    'src/Admin/DeleteController.php',
    'src/Admin/AttachmentMetaBox.php',
    'src/Admin/Ajax.php',
    'src/License/LicenseInterface.php',
] as $file) {
    require_once ABSPATH . $file;
}

// WP-CLI itself, recorded rather than printed. Required before ScanCommand,
// which type-hints neither but calls both.
require_once __DIR__ . '/wp-cli-stub.php';
require_once ABSPATH . 'src/Cli/ScanCommand.php';

use FreshetUnusedMedia\Admin\Ajax;
use FreshetUnusedMedia\Admin\AttachmentMetaBox;
use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Cli\ScanCommand;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\OrphanSizes;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;

// ------------------------------------------------------------ the grouping

/**
 * The library as files rather than rows: the grouped set the subquery returns.
 *
 * Grouped on FileGroups::keyFor() and decided by FileGroups::verdict(), so this
 * agrees with the SQL by construction rather than by a second transcription.
 * `HAVING live > 0` drops files whose every row is in the trash, and the status
 * literal the subquery carries narrows what is left.
 *
 * @return array<string, array{id: int, status: string}>
 */
function files(string $sql): array
{
    $groups = [];

    foreach ($GLOBALS['rows'] as $id => $row) {
        $key = FileGroups::keyFor($id);
        $groups[$key] ??= ['live' => 0, 'trash' => 0, 'used' => 0, 'unused' => 0, 'usedIds' => [], 'liveIds' => [], 'ids' => []];
        $groups[$key]['ids'][] = $id;

        if ($row['status'] === 'trash') {
            ++$groups[$key]['trash'];

            continue;
        }

        ++$groups[$key]['live'];
        $groups[$key]['liveIds'][] = $id;
        $status = (string) ($GLOBALS['meta'][$id][ResultStore::META_STATUS] ?? '');

        if ($status === ResultStore::STATUS_USED) {
            ++$groups[$key]['used'];
            $groups[$key]['usedIds'][] = $id;
        } elseif ($status === ResultStore::STATUS_UNUSED) {
            ++$groups[$key]['unused'];
        }
    }

    $files = [];

    foreach ($groups as $key => $group) {
        if ($group['live'] === 0) {
            continue;
        }

        $status = FileGroups::verdict($group['live'], $group['trash'], $group['used'], $group['unused']);

        // The status literal the subquery embeds, read back the way the
        // database would apply it.
        foreach ([ResultStore::STATUS_USED, ResultStore::STATUS_UNUSED, FileGroups::STATUS_UNSCANNED] as $wanted) {
            if (str_contains($sql, "fg_status = '{$wanted}'") && $status !== $wanted) {
                continue 2;
            }
        }

        // representativeSql(): the first used row, else the first live row.
        $ids = $group['usedIds'] !== [] ? $group['usedIds'] : ($group['liveIds'] !== [] ? $group['liveIds'] : $group['ids']);

        $files[$key] = ['id' => min($ids), 'status' => $status];
    }

    return $files;
}

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
 * Rebuild the fixture library and write its files to disk, then scan it, so
 * every row carries the status a real screen would have been rendered from.
 *
 * @param array<int, array{file: string, status?: string, used?: bool}> $rows
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
        ];

        $path = UPLOADS . '/' . $row['file'];

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, str_repeat('x', 1024));
    }

    $scanner = new Scanner(new ResultStore());

    foreach (array_keys($rows) as $id) {
        $scanner->scan($id);
    }
}

/**
 * One delete batch, and the reply it would have sent to the browser.
 *
 * @param array<string, string> $post What the screen posted with it — the
 *                                    filter it was showing, and the cursor the
 *                                    last reply handed back.
 */
function deleteBatch(array $post = []): array
{
    $store = new ResultStore();
    $ajax = new Ajax(
        new Scanner($store),
        $store,
        new ScanState(),
        new DeleteController(new Scanner($store), $store),
        new AttachmentMetaBox($store),
    );

    $_POST = $post;

    try {
        $ajax->deleteBatch();
    } catch (JsonReply $reply) {
        return $reply->data;
    }

    return [];
}

/**
 * A detector whose query fails. It is the shape every real detector has — one
 * read, then the rows are interpreted — with the read arranged to error, which
 * is the only way to reach Scanner's no-answer branch from here.
 */
final class FailingDetector implements \FreshetUnusedMedia\Detector\DetectorInterface
{
    public const NEEDLE = 'SELECT this_read_fails';

    public function id(): string
    {
        return 'failing';
    }

    public function find(\FreshetUnusedMedia\Scan\AttachmentContext $ctx): array
    {
        global $wpdb;

        return \FreshetUnusedMedia\Scan\Db::rows($this->id(), $wpdb->get_results(self::NEEDLE));
    }
}

function onDisk(string $file): bool
{
    return file_exists(UPLOADS . '/' . $file);
}

if (!is_dir(UPLOADS)) {
    mkdir(UPLOADS, 0777, true);
}

// --------------------------------------------------- the figure is there
//
// Eight unused files and one used one. The loop deletes five per batch, so the
// first reply is a mid-loop one and the figure it carries has to be the eight
// minus the five it just took — not the eight the page was rendered with.

$fixture = [];

foreach (range(1, 8) as $n) {
    $fixture[100 + $n] = ['file' => '2024/01/orphan-' . $n . '.png'];
}

$fixture[200] = ['file' => '2024/01/in-use.png', 'used' => true];

library($fixture);

$store = new ResultStore();

check('the library starts with eight unused files', $store->counts()['unused'], 8);

$first = deleteBatch();

check('the first batch is mid-loop', $first['finished'], false);
check('the first batch deletes a full batch', $first['deleted'], 5);
check('a mid-loop reply carries the unused figure', array_key_exists('unused', $first), true);
check('the figure is what is left, not what was there', $first['unused'], 3);
check('the figure agrees with a fresh read', $first['unused'], $store->counts()['unused']);
check('the figure travels formatted for the screen', $first['unused_display'], '3');

$second = deleteBatch();

check('the second batch takes the remainder', $second['deleted'], 3);
check('the figure keeps dropping as batches land', $second['unused'], 0);

$third = deleteBatch();

check('the loop finishes when nothing is left', $third['finished'], true);
check('a finished reply carries the figure too', array_key_exists('unused', $third), true);
check('the finished figure is the emptied one', $third['unused'], 0);
check('the finished reply is formatted too', $third['unused_display'], '0');

check('every orphan is off the disk', onDisk('2024/01/orphan-1.png') || onDisk('2024/01/orphan-8.png'), false);
check('the used file was never touched', onDisk('2024/01/in-use.png'), true);

// ------------------------------------------------- the figure is not kept
//
// It is a live read, so it has to answer for changes this loop did not make. A
// file that became used between two batches is one fewer to delete, and the
// reply must say so — a number remembered from the page render, or from the
// batch before, would still be reporting three.

library([
    301 => ['file' => '2024/02/one.png'],
    302 => ['file' => '2024/02/two.png'],
    303 => ['file' => '2024/02/three.png'],
]);

check('three unused files to start', (new ResultStore())->counts()['unused'], 3);

// Someone puts two of them back into a post, and a re-scan agrees.
foreach ([302, 303] as $id) {
    $GLOBALS['rows'][$id]['used'] = true;
    (new Scanner(new ResultStore()))->scan($id);
}

$reply = deleteBatch();

check('the batch deletes only what is still unused', $reply['deleted'], 1);
check('the figure reflects a change the loop did not make', $reply['unused'], 0);
check('the reclaimed file is gone', onDisk('2024/02/one.png'), false);
check('the re-used files stay', onDisk('2024/02/two.png') && onDisk('2024/02/three.png'), true);

// The mirror of the same rule, read off the source: nothing between the count
// and the reply is allowed to remember an answer. A memoised figure is the
// defect this test exists for, wearing a cache instead of a stale page.
$ajaxSource = (string) file_get_contents(ABSPATH . 'src/Admin/Ajax.php');
$storeSource = (string) file_get_contents(ABSPATH . 'src/Scan/ResultStore.php');

check(
    'nothing memoises the count on the way out',
    preg_match('/\b(static\s+\$|get_transient|set_transient|wp_cache_(get|set))\b/', $ajaxSource . $storeSource),
    0
);

// ------------------------------- a read that failed never confirms a deletion
//
// freshet-141. $wpdb answers a query that errored with the same empty array it
// answers one that matched nothing, so a re-verification that could not run at
// all used to agree that the file was unused — the guard and the guarded
// failing together, in the same direction, in the request most likely to be cut
// short. Both halves of the delete path are exercised: the sibling lookup that
// decides which rows a file has, and the re-scan that decides whether they use
// it.

// The genuinely-empty direction first, as the control: nothing here is proof of
// a fix unless a scan that honestly finds nothing still deletes.
library([
    401 => ['file' => '2024/03/control.png'],
]);

check('a scan that finds nothing still says unused', (new ResultStore())->status(401), ResultStore::STATUS_UNUSED);
check('and the file is deleted', deleteBatch()['deleted'], 1);
check('and it is off the disk', onDisk('2024/03/control.png'), false);

// The sibling lookup: two rows on one file, and the query that would reveal the
// second one fails. Deleting on that answer unlinks a file the row it could not
// see is standing on.
library([
    501 => ['file' => '2024/03/shared.png'],
    502 => ['file' => '2024/03/shared.png'],
]);

check('one file, two rows, unused', (new ResultStore())->counts()['unused'], 1);

$GLOBALS['wpdb']->failOn = 'pm.meta_value IN';
$siblingsFailed = deleteBatch();
$GLOBALS['wpdb']->failOn = '';

check('a failed sibling lookup deletes nothing', $siblingsFailed['deleted'], 0);
check('and is reported as a failure, not a skip', $siblingsFailed['failed'], 1);
check('and the file is still there', onDisk('2024/03/shared.png'), true);
check('and the file has left the unused pool, so the loop terminates', (new ResultStore())->counts()['unused'], 0);

// The re-verification: a detector whose query fails. The scan has not found the
// file unused — it has found nothing — and the deletion has to refuse.
library([
    601 => ['file' => '2024/03/verified.png'],
]);

check('the file starts out unused', (new ResultStore())->status(601), ResultStore::STATUS_UNUSED);

$GLOBALS['detectors'] = [new FailingDetector()];
$GLOBALS['wpdb']->failOn = FailingDetector::NEEDLE;

$scanned = (new Scanner(new ResultStore()))->scan(601);

check('a scan that cannot read the database has no verdict', $scanned['status'], Scanner::STATUS_ERROR);
check('and it is not one of the two stored verdicts', in_array($scanned['status'], [ResultStore::STATUS_USED, ResultStore::STATUS_UNUSED], true), false);
check('and the stale verdict is cleared rather than left standing', (new ResultStore())->status(601), null);

// Put it back in the pool: the assertion above emptied it, and what the delete
// loop has to refuse is a file its own listing is still offering.
$GLOBALS['detectors'] = [];
$GLOBALS['wpdb']->failOn = '';
(new Scanner(new ResultStore()))->scan(601);

check('the listing offers it again', (new ResultStore())->counts()['unused'], 1);

$GLOBALS['detectors'] = [new FailingDetector()];
$GLOBALS['wpdb']->failOn = FailingDetector::NEEDLE;

$verifyFailed = deleteBatch();

$GLOBALS['wpdb']->failOn = '';
$GLOBALS['detectors'] = [];

check('a failed re-verification deletes nothing', $verifyFailed['deleted'], 0);
check('and refuses rather than confirming', $verifyFailed['failed'], 1);
check('and the file is still on disk', onDisk('2024/03/verified.png'), true);

// --------------------------------------------- the batch that ran out of time
//
// freshet-150. Five files is a ceiling, not a promise: re-verifying one file is
// one scan per attachment row standing on it, so on a library where a file
// averages ten rows a five-file batch is minutes of queries inside one web
// request — past the point PHP gives up, with no reply, no progress bar and no
// account of what was removed. The budget stops the run between files instead.
//
// What has to hold across a short batch is three things at once: it is never
// `finished`, the figure it sends is still the live one, and the files it did
// not reach are offered again rather than stepped over.

$fixture = [];

foreach (range(1, 8) as $n) {
    $fixture[100 + $n] = ['file' => '2024/04/short-' . $n . '.png'];
}

library($fixture);

$GLOBALS['delete_seconds'] = 0.0;

$short = deleteBatch();

check('a spent budget still deletes one file', $short['deleted'], 1);
check('and does not call the pass finished', $short['finished'], false);
check('and says how many it did not reach', $short['remaining'], 4);
check('and the figure it sends is the live one', $short['unused'], 7);
check('the file it reached is gone', onDisk('2024/04/short-1.png'), false);
check('the next one is untouched', onDisk('2024/04/short-2.png'), true);
check('and still carries the verdict the screen listed it on', (new ResultStore())->status(102), ResultStore::STATUS_UNUSED);

// Resuming is the loop calling again, which is what the browser already does.
// Eight files, one per request, and it has to terminate — a loop that stops
// early and a loop that never stops are the same defect from two sides.
$deleted = $short['deleted'];
$requests = 1;

while ($requests < 40) {
    ++$requests;
    $reply = deleteBatch();
    $deleted += $reply['deleted'];

    if ($reply['finished']) {
        break;
    }
}

check('the loop finishes on a spent budget', $reply['finished'], true);
check('having deleted every file', $deleted, 8);
check('one file per request, plus the empty one that ends it', $requests, 9);
check('and the last file is off the disk', onDisk('2024/04/short-8.png'), false);

// The cursor. Without a size filter there is none — the files stay in the
// unused pool and the next batch's own query finds them — and inventing one
// would bind the next batch to a position nothing asked for.
library($fixture);

check('a short batch with no cursor in play reports none', deleteBatch()['cursor'], 0);

// With one, the cursor is the sharp edge: it is what the next batch starts
// after, so a batch that decided one of its five files and handed the cursor
// on where the *query* left it would step over the four it never looked at.
library($fixture);

$sized = ['filter_min_mb' => '0.0001'];

$short = deleteBatch($sized);

check('a size filter puts a cursor in play', $short['cursor'] > 0, true);
check('and it stops just before the first file left undecided', $short['cursor'], 101);
check('one file decided', $short['deleted'], 1);

// Walked to the end through the cursor the replies hand back, which is the
// browser's loop exactly. Every file has to be reached: this is the assertion
// that would fail if the cursor stepped over the undecided four.
$deleted = $short['deleted'];
$requests = 1;

while ($requests < 40) {
    ++$requests;
    $reply = deleteBatch($sized + ['after' => (string) $short['cursor']]);
    $deleted += $reply['deleted'];
    $short = $reply;

    if ($reply['finished']) {
        break;
    }
}

check('a size-filtered loop finishes too', $reply['finished'], true);
check('and reaches every file the filter named', $deleted, 8);
check('nothing was stepped over', (new ResultStore())->counts()['unused'], 0);

// And with room in the budget the batch is the batch: five files, nothing left
// over, which is the reply every existing assertion above was written against.
library($fixture);

$GLOBALS['delete_seconds'] = 600.0;

$full = deleteBatch();

check('a budget with room in it deletes the whole batch', $full['deleted'], 5);
check('and leaves nothing unreached', $full['remaining'], 0);

unset($GLOBALS['delete_seconds']);

// ------------------------- the CLI's own reads: a stopped run is not a finished one
//
// freshet-152. The browser scan's two reads — the library count that sizes the
// run, and the ID cursor that walks it — were guarded in freshet-141; their
// WP-CLI twins were not. The cursor is the one that matters: an empty batch is
// the only way the loop is ever told the library has been read to the end, so a
// cursor read that *failed* ended `wp media unused scan` on a success line
// claiming a completed scan of a library it had stopped reading.
//
// Every case below is asserted in the same two places, because either alone can
// be satisfied by a command that is simply broken: what the command *said*
// ($GLOBALS['cli']), and whether the run was recorded as finished
// (ScanState::lastScan(), written by the finish() call the exception jumps past).

/** A licensed site — the CLI is the paid tier's second surface and checks first. */
final class ProLicense implements LicenseInterface
{
    public function isPro(): bool
    {
        return true;
    }
}

/**
 * One `wp freshet-unusedmedia scan`, and what it said.
 *
 * @param array<string, mixed> $assocArgs
 * @return array{halted: bool, cli: array<string, array<int, mixed>>}
 */
function cliScan(array $assocArgs = []): array
{
    $GLOBALS['cli'] = [];

    $store = new ResultStore();
    $command = new ScanCommand(new Scanner($store), $store, new ScanState(), new ProLicense());

    $halted = false;

    try {
        $command->scan([], $assocArgs);
    } catch (CliHalt) {
        $halted = true;
    }

    return ['halted' => $halted, 'cli' => $GLOBALS['cli']];
}

/**
 * One `wp freshet-unusedmedia list`, and what it said.
 *
 * @param array<string, mixed> $assocArgs
 * @return array{halted: bool, cli: array<string, array<int, mixed>>}
 */
function cliList(array $assocArgs = []): array
{
    $GLOBALS['cli'] = [];

    $store = new ResultStore();
    $command = new ScanCommand(new Scanner($store), $store, new ScanState(), new ProLicense());

    $halted = false;

    try {
        $command->list_([], $assocArgs);
    } catch (CliHalt) {
        $halted = true;
    }

    return ['halted' => $halted, 'cli' => $GLOBALS['cli']];
}

$cliFixture = [];

foreach (range(1, 5) as $n) {
    $cliFixture[300 + $n] = ['file' => '2024/03/cli-' . $n . '.png'];
}

// A whole run, with nothing wrong. The pair to every refusal below and not a
// formality: a guard that had simply broken the loop would pass the error cases
// on its own, and this is the assertion it would fail.
library($cliFixture);
(new ScanState())->reset();
OrphanSizes::reset();

$clean = cliScan();

check('a whole run reports itself finished', $clean['halted'], false);
check('and says so once, in the success line', count($clean['cli']['success'] ?? []), 1);
check('having scanned every attachment', (new ScanState())->lastScan()['scanned'] ?? null, 5);
check('and printed its summary', count($clean['cli']['items'] ?? []), 1);

// The library count. It sizes the run, so a failed one starts a scan of nothing
// — and a scan of nothing ends immediately, on a success line.
library($cliFixture);
(new ScanState())->reset();

$GLOBALS['wpdb']->failOn = 'COUNT(*) FROM wp_posts';
$countFailed = cliScan();
$GLOBALS['wpdb']->failOn = '';

check('a failed library count ends the run', $countFailed['halted'], true);
check('with an error rather than a success line', [count($countFailed['cli']['errors'] ?? []), count($countFailed['cli']['success'] ?? [])], [1, 0]);
check('and nothing is printed as a summary either', count($countFailed['cli']['items'] ?? []), 0);
check('and no scan is recorded as having finished', (new ScanState())->lastScan(), null);

// The cursor, mid-run — the case the whole task is about. Two of the five
// attachments have been scanned and the cursor is written past them; the read
// that would have fetched the other three fails.
library($cliFixture);

$state = new ScanState();
$state->reset();
$state->start(5);
$state->advance(302, 2);

$GLOBALS['wpdb']->failOn = 'ORDER BY ID ASC';
$cursorFailed = cliScan(['resume' => true]);
$GLOBALS['wpdb']->failOn = '';

check('a failed cursor read ends the run', $cursorFailed['halted'], true);
check('rather than reading three unread attachments as the end of the library', count($cursorFailed['cli']['success'] ?? []), 0);
check('it says why, once', count($cursorFailed['cli']['errors'] ?? []), 1);
check('nothing is reported as a finished scan', $state->lastScan(), null);

// And the run is resumable, which is the reason for refusing rather than
// finishing: the cursor is still where the last completed batch left it, so
// --resume picks up the three that were never read.
check('the cursor is left where the last completed batch put it', $state->current()['cursor'] ?? null, 302);

$resumed = cliScan(['resume' => true]);

check('so a resumed run finishes what the failed one could not', $resumed['halted'], false);
check('and it accounts for the whole library', $state->lastScan()['scanned'] ?? null, 5);

// The closing tally is the third read in the same command, and a run that could
// not count what it found has not finished either.
library($cliFixture);
(new ScanState())->reset();

$GLOBALS['wpdb']->failOn = 'fg.fg_status AS status';
$tallyFailed = cliScan();
$GLOBALS['wpdb']->failOn = '';

check('a failed closing tally ends the run too', $tallyFailed['halted'], true);
check('without a success line quoting a figure nothing counted', count($tallyFailed['cli']['success'] ?? []), 0);
check('and without recording the scan as finished', (new ScanState())->lastScan(), null);

// `wp media unused list` is the same shape one command over: it pages until a
// page comes back empty, so a page that failed is a short list of files to
// delete wearing the look of a complete one.
library($cliFixture);

$listed = cliList();

check('a working list prints every unused file', count($listed['cli']['items'][0]['items'] ?? []), 5);

// --limit stops the walk mid-page, and the paging loop it breaks out of now
// sits inside a try. `break 2` counts loops and not try blocks, which is only
// worth asserting because getting it wrong would silently list everything.
$limited = cliList(['limit' => 2]);

check('and --limit still stops where it says', count($limited['cli']['items'][0]['items'] ?? []), 2);

$GLOBALS['wpdb']->failOn = 'SELECT fg.fg_id';
$listFailed = cliList();
$GLOBALS['wpdb']->failOn = '';

check('a failed listing read ends the command', $listFailed['halted'], true);
check('and prints no list at all rather than part of one', count($listFailed['cli']['items'] ?? []), 0);

// ------------------------------------------------------------------ result

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL  ' . $failure . PHP_EOL);
}

printf('%d passed, %d failed%s', $passed, count($failures), PHP_EOL);

exit($failures === [] ? 0 : 1);

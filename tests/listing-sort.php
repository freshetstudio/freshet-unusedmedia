<?php

declare(strict_types=1);

/**
 * Listing-sort smoke test — a sort orders files; it never chooses them.
 *
 * Pure PHP: no WordPress, no database, no test framework. Run it with:
 *
 *     php tests/listing-sort.php
 *
 * Fixture files are written to a real temporary directory with deliberately
 * different byte counts, so "sorted by size" is an actual stat rather than a
 * promise, and one fixture file is never written at all — a file whose size
 * cannot be read is the case the ordering has to answer for.
 *
 * Three claims are under test and they are different claims:
 *
 * 1. **Every sortable column orders both listings, both ways.** Path, upload
 *    date and size, ascending and descending, on Unused and on Used.
 * 2. **A sort orders files, not rows.** Two attachment rows on one
 *    `_wp_attached_file` are one file: it appears once in every ordering, and
 *    it sorts by the *earliest* upload of its rows, which is what the grouped
 *    query returns.
 * 3. **The delete path never learns about it.** Filtered and sorted, the count
 *    the button carries is the filtered count, the loop walks the filtered set
 *    ascending by id, and the files a sort put at the top are not the files a
 *    delete takes first. Sorting a screen must not change what a delete does.
 *
 * $wpdb below answers the grouped subquery from the in-memory library — the
 * same grouping (FileGroups::keyFor) and the same verdict (FileGroups::verdict)
 * the SQL transcribes — and honours the ORDER BY, the HAVING filters and the
 * LIMIT the store builds around it. The SQL itself is asserted structurally in
 * tests/file-grouping.php; what is exercised here is what the store asks for.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

define('UPLOADS', sys_get_temp_dir() . '/freshet-unusedmedia-listing-sort');

/** wpdb's row shape, the one ResultStore::counts() asks for. */
const ARRAY_A = 'ARRAY_A';

// --------------------------------------------------------------- WP stubs

/**
 * The library under test: attachment id => row. `file` is the stored
 * `_wp_attached_file` value, `date` the row's post_date, `bytes` how big the
 * fixture file is written (null writes no file at all), `used` what a fresh
 * scan will decide about that row.
 *
 * @var array<int, array{file: string, date: string, bytes: int|null, status: string, used: bool}>
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

/** Counted, because the sized walk priming it per chunk is what bounds its cost. */
function update_postmeta_cache(array $ids): bool
{
    $GLOBALS['primed'][] = count($ids);

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

function size_format(int|float $bytes, int $decimals = 0): string
{
    return (string) $bytes;
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
 * from the fixture, because what is under test here is the order a listing is
 * read in, not what a detector finds.
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
 * $wpdb, answering the grouped subquery from the in-memory library.
 *
 * It does not parse SQL in general. It recognises the wrappers ResultStore
 * builds around FileGroups::subquery() — the aggregate that counts files per
 * verdict, the cursor query the delete loop walks, and the listing query — and
 * applies to them the three things this test is about: the HAVING filters the
 * subquery carries, the ORDER BY the wrapper asks for, and the page window.
 */
$GLOBALS['wpdb'] = new class {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';

    /** @var array<int, array{sql: string, params: array<int, mixed>}> */
    public array $queries = [];

    /** Every statement actually executed, prepared or not — this is the read count. */
    public array $reads = [];

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

        return ['sql' => $sql, 'params' => []];
    }

    /** @return array{sql: string, params: array<int, mixed>} */
    public function last(): array
    {
        return $this->queries[count($this->queries) - 1];
    }

    public function get_col(string $sql): array
    {
        $query = $this->resolve($sql);
        $this->reads[] = $query['sql'];

        if (!str_contains($query['sql'], 'SELECT fg.fg_id')) {
            return [];
        }

        $files = order($query['sql'], files($query['sql'], $query['params']));
        $ids = array_column($files, 'id');

        // The delete cursor: everything after $after, capped by the limit.
        if (str_contains($query['sql'], 'fg.fg_id > %d')) {
            $after = (int) $query['params'][count($query['params']) - 2];
            $limit = (int) $query['params'][count($query['params']) - 1];
            $ids = array_slice(array_values(array_filter($ids, static fn(int $id): bool => $id > $after)), 0, $limit);
        } elseif (str_contains($query['sql'], 'OFFSET')) {
            $limit = (int) $query['params'][count($query['params']) - 2];
            $offset = (int) $query['params'][count($query['params']) - 1];
            $ids = array_slice($ids, $offset, $limit);
        }

        return array_map('strval', $ids);
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        $query = $this->resolve($sql);
        $this->reads[] = $query['sql'];

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

        foreach (files($query['sql'], $query['params']) as $file) {
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
        $this->reads[] = $query['sql'];

        return str_contains($query['sql'], 'COUNT(*)')
            ? (string) count(files($query['sql'], $query['params']))
            : '0';
    }
};

foreach ([
    'src/Scan/QueryFailed.php',
    'src/Scan/Db.php',
    'src/Scan/Reference.php',
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
    'src/Scan/Scanner.php',
    'src/Detector/DetectorInterface.php',
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
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\Ajax;
use FreshetUnusedMedia\Admin\AttachmentMetaBox;
use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultSort;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;

// ------------------------------------------------------------ the grouping

/**
 * The library as files rather than rows: the grouped set the subquery returns,
 * narrowed by the HAVING clauses it carries.
 *
 * Grouped on FileGroups::keyFor() and decided by FileGroups::verdict(), so this
 * agrees with the SQL by construction rather than by a second transcription.
 * `fg_date` is MIN(post_date) over the file's live rows, which is the one place
 * a file's date can differ from any single row's.
 *
 * @param array<int, mixed> $params
 * @return array<int, array{id: int, key: string, date: string, status: string}>
 */
function files(string $sql, array $params): array
{
    $groups = [];

    foreach ($GLOBALS['rows'] as $id => $row) {
        $key = FileGroups::keyFor($id);
        $groups[$key] ??= ['live' => 0, 'trash' => 0, 'used' => 0, 'unused' => 0, 'usedIds' => [], 'liveIds' => [], 'ids' => [], 'date' => null];
        $groups[$key]['ids'][] = $id;

        if ($row['status'] === 'trash') {
            ++$groups[$key]['trash'];

            continue;
        }

        ++$groups[$key]['live'];
        $groups[$key]['liveIds'][] = $id;
        $groups[$key]['date'] = $groups[$key]['date'] === null
            ? $row['date']
            : min($groups[$key]['date'], $row['date']);

        $status = (string) ($GLOBALS['meta'][$id][ResultStore::META_STATUS] ?? '');

        if ($status === ResultStore::STATUS_USED) {
            ++$groups[$key]['used'];
            $groups[$key]['usedIds'][] = $id;
        } elseif ($status === ResultStore::STATUS_UNUSED) {
            ++$groups[$key]['unused'];
        }
    }

    // The subquery's own placeholders lead the parameter list, in the order
    // FileGroups::subquery() appends its clauses.
    $bounds = [];
    $at = 0;

    foreach (['file' => 'fg_key LIKE %s', 'from' => 'fg_date >= %s', 'to' => 'fg_date <= %s'] as $name => $clause) {
        $bounds[$name] = str_contains($sql, $clause) ? (string) $params[$at++] : null;
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

        if ($bounds['file'] !== null && !str_contains($key, trim($bounds['file'], '%'))) {
            continue;
        }

        if ($bounds['from'] !== null && $group['date'] < $bounds['from']) {
            continue;
        }

        if ($bounds['to'] !== null && $group['date'] > $bounds['to']) {
            continue;
        }

        // representativeSql(): the first used row, else the first live row.
        $ids = $group['usedIds'] !== [] ? $group['usedIds'] : ($group['liveIds'] !== [] ? $group['liveIds'] : $group['ids']);

        $files[] = ['id' => min($ids), 'key' => $key, 'date' => (string) $group['date'], 'status' => $status];
    }

    return $files;
}

/**
 * The wrapper's ORDER BY, applied. The subquery carries none, so the last one in
 * the string is always the wrapper's.
 *
 * @param array<int, array{id: int, key: string, date: string, status: string}> $files
 * @return array<int, array{id: int, key: string, date: string, status: string}>
 */
function order(string $sql, array $files): array
{
    $at = strrpos($sql, 'ORDER BY');

    if ($at === false) {
        return $files;
    }

    $clause = substr($sql, $at + strlen('ORDER BY'));
    $clause = explode(' LIMIT', $clause)[0];

    $terms = [];

    foreach (explode(',', $clause) as $term) {
        $parts = preg_split('/\s+/', trim($term)) ?: [];
        $terms[] = [
            'field' => str_replace(['fg.fg_', 'fg_'], '', $parts[0] ?? ''),
            'descending' => strtoupper($parts[1] ?? 'ASC') === 'DESC',
        ];
    }

    usort($files, static function (array $a, array $b) use ($terms): int {
        foreach ($terms as $term) {
            $verdict = $a[$term['field']] <=> $b[$term['field']];

            if ($verdict !== 0) {
                return $term['descending'] ? -$verdict : $verdict;
            }
        }

        return 0;
    });

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
 * Rebuild the fixture library, write its files at the sizes it asks for, and
 * scan it, so every row carries the status a real screen would have been
 * rendered from. A row with `bytes` null gets no file at all — that is the
 * unmeasurable one.
 *
 * @param array<int, array{file: string, date: string, bytes?: int|null, status?: string, used?: bool}> $rows
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
    $GLOBALS['primed'] = [];

    foreach ($rows as $id => $row) {
        $GLOBALS['rows'][$id] = [
            'file' => $row['file'],
            'date' => $row['date'],
            // array_key_exists, not ??: null is the fixture asking for a file
            // that is never written, and ?? would hand it a default instead.
            'bytes' => array_key_exists('bytes', $row) ? $row['bytes'] : 1024,
            'status' => $row['status'] ?? 'inherit',
            'used' => $row['used'] ?? false,
        ];

        if ($GLOBALS['rows'][$id]['bytes'] === null) {
            continue;
        }

        $path = UPLOADS . '/' . $row['file'];

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, str_repeat('x', (int) $GLOBALS['rows'][$id]['bytes']));
    }

    $scanner = new Scanner(new ResultStore());

    foreach (array_keys($rows) as $id) {
        $scanner->scan($id);
    }
}

/** @return int[] The ids one listing shows, in the order it shows them. */
function listing(string $status, string $orderby = '', string $order = ''): array
{
    return (new ResultStore())->byStatus(
        $status,
        1,
        50,
        ResultFilters::none(),
        ResultSort::fromRequest([ResultSort::ARG_ORDERBY => $orderby, ResultSort::ARG_ORDER => $order])
    )['ids'];
}

function onDisk(string $file): bool
{
    return file_exists(UPLOADS . '/' . $file);
}

if (!is_dir(UPLOADS)) {
    mkdir(UPLOADS, 0777, true);
}

// ------------------------------------------------------------ the fixture
//
// Six unused files and two used ones, with the three orderings deliberately
// disagreeing: a set where path order happened to equal date order would pass
// a sort that ignored the column it was handed.
//
// 105 and 106 are two rows on one file — one file, sorting by the earliest of
// its rows (2022) rather than by the row that represents it (2024). 107 is
// never written to disk, so its size cannot be read at all.

library([
    101 => ['file' => '2024/03/apple.png', 'date' => '2025-01-05 09:00:00', 'bytes' => 3000],
    102 => ['file' => '2024/01/zebra.png', 'date' => '2023-05-10 09:00:00', 'bytes' => 1000],
    103 => ['file' => '2025/06/mango.png', 'date' => '2024-08-20 09:00:00', 'bytes' => 5000],
    104 => ['file' => '2023/12/berry.png', 'date' => '2026-02-01 09:00:00', 'bytes' => 2000],
    105 => ['file' => '2022/01/twin.png', 'date' => '2024-04-04 09:00:00', 'bytes' => 700],
    106 => ['file' => '2022/01/twin.png', 'date' => '2022-01-01 09:00:00', 'bytes' => 700],
    107 => ['file' => '2024/09/ghost.png', 'date' => '2024-09-09 09:00:00', 'bytes' => null],
    201 => ['file' => '2024/02/used-a.png', 'date' => '2024-02-02 09:00:00', 'bytes' => 4000, 'used' => true],
    202 => ['file' => '2024/07/used-b.png', 'date' => '2023-09-09 09:00:00', 'bytes' => 500, 'used' => true],
]);

check('the fixture is six unused files, not seven rows', (new ResultStore())->counts()['unused'], 6);
check('and two used ones', (new ResultStore())->counts()['used'], 2);

// --------------------------------------------------------- no sort at all
//
// The default is unchanged: representative id ascending, which is the order the
// delete cursor walks and the order every caller that asks for no sort gets.

check('unsorted is id ascending', listing(ResultStore::STATUS_UNUSED), [101, 102, 103, 104, 105, 107]);
check('an unknown column is not a sort', listing(ResultStore::STATUS_UNUSED, 'mime', 'asc'), [101, 102, 103, 104, 105, 107]);

// -------------------------------------------------------------- the path
//
// fg_key, the whole stored path — the same value the filename filter matches,
// so narrowing and ordering agree about what "file" means.

check('path ascending', listing(ResultStore::STATUS_UNUSED, 'file', 'asc'), [105, 104, 102, 101, 107, 103]);
check('path descending', listing(ResultStore::STATUS_UNUSED, 'file', 'desc'), [103, 107, 101, 102, 104, 105]);
check('path ascending on the used listing', listing(ResultStore::STATUS_USED, 'file', 'asc'), [201, 202]);
check('path descending on the used listing', listing(ResultStore::STATUS_USED, 'file', 'desc'), [202, 201]);

// -------------------------------------------------------- the upload date
//
// fg_date, the earliest upload of any of the file's live rows. 105 leads the
// ascending list on 106's date, which is the grouped answer rather than the
// representative row's own.

check('date ascending', listing(ResultStore::STATUS_UNUSED, 'date', 'asc'), [105, 102, 103, 107, 101, 104]);
check('date descending', listing(ResultStore::STATUS_UNUSED, 'date', 'desc'), [104, 101, 107, 103, 102, 105]);
check('date ascending on the used listing', listing(ResultStore::STATUS_USED, 'date', 'asc'), [202, 201]);
check('date descending on the used listing', listing(ResultStore::STATUS_USED, 'date', 'desc'), [201, 202]);

// -------------------------------------------------------------- the size
//
// Measured in PHP, because nothing in the schema records it. The file counted
// once for its bytes is the same file counted once in the list.

check('size ascending', listing(ResultStore::STATUS_UNUSED, 'size', 'asc'), [105, 102, 104, 101, 103, 107]);
check('size descending', listing(ResultStore::STATUS_UNUSED, 'size', 'desc'), [103, 101, 104, 102, 105, 107]);
check('size ascending on the used listing', listing(ResultStore::STATUS_USED, 'size', 'asc'), [202, 201]);
check('size descending on the used listing', listing(ResultStore::STATUS_USED, 'size', 'desc'), [201, 202]);

// An unknown is not a zero and not an infinity: it trails both directions
// rather than leading the ascending list as the smallest file in the library.
check('an unreadable size trails ascending', array_slice(listing(ResultStore::STATUS_UNUSED, 'size', 'asc'), -1), [107]);
check('an unreadable size trails descending too', array_slice(listing(ResultStore::STATUS_UNUSED, 'size', 'desc'), -1), [107]);
check('and it is never dropped from the list', count(listing(ResultStore::STATUS_UNUSED, 'size', 'asc')), 6);

// ------------------------------------------------- a sort orders files
//
// Two rows, one file, one row in every ordering — the thing a listing built on
// rows gets wrong twice: the file appears twice and its bytes count twice.

foreach ([['file', 'asc'], ['file', 'desc'], ['date', 'asc'], ['date', 'desc'], ['size', 'asc'], ['size', 'desc']] as [$by, $direction]) {
    $ids = listing(ResultStore::STATUS_UNUSED, $by, $direction);

    check(
        sprintf('the twinned file appears once, sorted by %s %s', $by, $direction),
        [count($ids), count(array_intersect($ids, [105, 106]))],
        [6, 1]
    );
}

// ------------------------------------------------------ the page window
//
// Sorting has to survive paging, so a page is a window on the sorted set and
// never a sorted window: page two of a descending size sort is the fifth and
// sixth largest files, not the last two ids re-ordered among themselves.

$store = new ResultStore();
$sortBySize = ResultSort::fromRequest([ResultSort::ARG_ORDERBY => 'size', ResultSort::ARG_ORDER => 'desc']);

$first = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 2, ResultFilters::none(), $sortBySize);
$second = $store->byStatus(ResultStore::STATUS_UNUSED, 2, 2, ResultFilters::none(), $sortBySize);
$third = $store->byStatus(ResultStore::STATUS_UNUSED, 3, 2, ResultFilters::none(), $sortBySize);

check('page one is the two biggest', $first['ids'], [103, 101]);
check('page two carries on down the same order', $second['ids'], [104, 102]);
check('page three is the smallest and the unmeasurable one', $third['ids'], [105, 107]);
check('every page reports the same total', [$first['total'], $second['total'], $third['total']], [6, 6, 6]);

$byDate = $store->byStatus(ResultStore::STATUS_UNUSED, 2, 2, ResultFilters::none(), ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'date',
    ResultSort::ARG_ORDER => 'asc',
]));

check('a database-side sort pages the same way', $byDate['ids'], [103, 107]);

// ------------------------------------------------- the sized walk is bounded
//
// The size sort costs what the size filter costs and nothing more: one query
// over the narrowed set, then a stat per file with the postmeta cache primed
// per SIZE_CHUNK batch. What it must never be is a query per file.

$GLOBALS['primed'] = [];
$GLOBALS['wpdb']->reads = [];

$store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, ResultFilters::none(), $sortBySize);

check('a size sort runs one query, not one per file', count($GLOBALS['wpdb']->reads), 1);
check('and primes the postmeta cache once per batch', count($GLOBALS['primed']), 1);
check('the whole narrowed set is sized, not the page', $GLOBALS['primed'], [6]);

// The database-side sorts do not pay any of that: the page comes off a LIMIT,
// and the only files sized are the ones a cell prints a size for.
$GLOBALS['primed'] = [];

$store->byStatus(ResultStore::STATUS_UNUSED, 1, 2, ResultFilters::none(), ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'date',
    ResultSort::ARG_ORDER => 'desc',
]));

check('a date sort sizes nothing at all', $GLOBALS['primed'], []);

// ------------------------------------------------------- filter, then sort
//
// The filter narrows the set and the sort orders what is left. Both survive
// together, and the total is the filtered one however it is ordered.

$filtered = ResultFilters::fromRequest([ResultFilters::ARG_FILE => '2024/']);

$sorted = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, $filtered, $sortBySize);
$unsorted = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, $filtered, ResultSort::none());

check('the filter narrows to its own set', $unsorted['ids'], [101, 102, 107]);
check('the sort orders that set, biggest first', $sorted['ids'], [101, 102, 107]);
check('the sort never changes the total', [$sorted['total'], $unsorted['total']], [3, 3]);

$sortedAscending = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, $filtered, ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'size',
    ResultSort::ARG_ORDER => 'asc',
]));

check('and the other direction is the same set', $sortedAscending['ids'], [102, 101, 107]);

$sizeBound = ResultFilters::fromRequest([ResultFilters::ARG_MIN => '0.0015']);
$bounded = $store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, $sizeBound, ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'date',
    ResultSort::ARG_ORDER => 'desc',
]));

// A size bound and a date sort at once: the bound drops the small files and the
// unmeasurable one, and what is left is in date order rather than in the id
// order the sized walk reads them in.
check('a size bound and a date sort compose', $bounded['ids'], [104, 101, 103]);

// ------------------------------------------------ the delete walks the filter
//
// The rule freshet-D93 states outright: sorting must not change what a delete
// walks. The screen is sorted biggest-first and filtered to 2024/, and the loop
// still walks the filtered set by ascending id — so the count in the button and
// the files it takes are the filter's answer, never the sort's.

$walk = $store->unusedIds(50, $filtered);

$ascending = $walk['ids'];
sort($ascending);

check('the delete loop walks the filtered set', $walk['ids'], [101, 102, 107]);
check('and walks it ascending by id, whatever the screen shows', $walk['ids'], $ascending);
check('which is not the order the screen was in', $walk['ids'] === $sorted['ids'] && $sorted['ids'] === $sortedAscending['ids'], false);
check('the button counts what the loop will walk', $sorted['total'], count($walk['ids']));

// Read off the source rather than inferred: there is no way to hand a sort to
// the delete path, because the parameter does not exist.
check(
    'the delete cursor takes no sort',
    (new ReflectionMethod(ResultStore::class, 'unusedIds'))->getNumberOfParameters(),
    3
);

$deleteParams = array_map(
    static fn(ReflectionParameter $p): string => (string) $p->getType(),
    (new ReflectionMethod(ResultStore::class, 'unusedIds'))->getParameters()
);

check('none of its parameters is a sort', in_array('?' . ResultSort::class, $deleteParams, true), false);

// And through the AJAX door the browser actually uses: the sort arguments ride
// along in the POST because the page carries them, and the batch ignores them.
$ajax = static function (): array {
    $store = new ResultStore();
    $ajax = new Ajax(
        new Scanner($store),
        $store,
        new ScanState(),
        new DeleteController(new Scanner($store), $store),
        new AttachmentMetaBox($store),
    );

    try {
        $ajax->deleteBatch();
    } catch (JsonReply $reply) {
        return $reply->data;
    }

    return [];
};

$_POST = [
    ResultFilters::ARG_FILE => '2024/',
    ResultSort::ARG_ORDERBY => 'size',
    ResultSort::ARG_ORDER => 'desc',
];

$reply = $ajax();

check('a sorted, filtered delete takes the filtered files', $reply['deleted'], 3);
check('the biggest file in the library was not in the filter, so it survives', onDisk('2025/06/mango.png'), true);
check('nor was the twinned one', onDisk('2022/01/twin.png'), true);
check('the filtered files are gone', onDisk('2024/03/apple.png') || onDisk('2024/01/zebra.png'), false);
check('the figure the reply carries is the whole unused pool', $reply['unused'], 3);

$_POST = [];

// ------------------------------------------------------ the URL round trip
//
// The sort is in the URL or it does not survive a page link. What goes out has
// to come back as the same sort, and nothing that is not a column may become
// one on the way.

$roundTrip = ResultSort::fromRequest(ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'size',
    ResultSort::ARG_ORDER => 'desc',
])->queryArgs());

check('a sort survives its own URL', [$roundTrip->column, $roundTrip->direction], ['size', 'desc']);
check('an unsorted listing carries no arguments', ResultSort::none()->queryArgs(), []);
check('an unknown column carries none either', ResultSort::fromRequest([ResultSort::ARG_ORDERBY => 'refs'])->queryArgs(), []);
check('a nonsense direction is ascending', ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'file',
    ResultSort::ARG_ORDER => 'sideways',
])->direction, ResultSort::ASC);

// The header link: the same column flips, a different one starts where it is
// useful — biggest files first, oldest files first, names from A.
$sortedByDate = ResultSort::fromRequest([ResultSort::ARG_ORDERBY => 'date', ResultSort::ARG_ORDER => 'asc']);

check('clicking the sorted column flips it', $sortedByDate->linkArgs('date')[ResultSort::ARG_ORDER], ResultSort::DESC);
check('clicking size first offers the biggest', $sortedByDate->linkArgs('size')[ResultSort::ARG_ORDER], ResultSort::DESC);
check('clicking date first offers the oldest', ResultSort::none()->linkArgs('date')[ResultSort::ARG_ORDER], ResultSort::ASC);
check('clicking a name first offers A to Z', ResultSort::none()->linkArgs('file')[ResultSort::ARG_ORDER], ResultSort::ASC);
check('a column that is not sortable has no link', ResultSort::none()->linkArgs('scanned'), []);

// ------------------------------------------------- nothing narrows on the way
//
// The mirror of FileGroups' own rule, read off the source: a sort that pushed a
// clause into the subquery's WHERE would hide a *used* row from its own group
// and hand back a file marked unused on half its evidence.

foreach (ResultSort::columns() as $column) {
    foreach ([ResultSort::ASC, ResultSort::DESC] as $direction) {
        $clause = ResultSort::fromRequest([
            ResultSort::ARG_ORDERBY => $column,
            ResultSort::ARG_ORDER => $direction,
        ])->orderBySql();

        check(
            sprintf('ordering by %s %s is an ORDER BY and nothing else', $column, $direction),
            preg_match('/\b(WHERE|GROUP BY|HAVING|SELECT|UNION|JOIN)\b/i', $clause),
            0
        );

        check(
            sprintf('ordering by %s %s names only grouped columns', $column, $direction),
            preg_match('/^fg\.fg_(key|date|id) (ASC|DESC)(, fg\.fg_id ASC)?$/', $clause),
            1
        );
    }
}

// The wrapper is where the ordering lives, so the subquery goes in whole and
// comes out unchanged — the moment a sort needed a clause of its own inside it,
// this is the assertion that would fail.
$GLOBALS['wpdb']->reads = [];

$store->byStatus(ResultStore::STATUS_UNUSED, 1, 50, ResultFilters::none(), ResultSort::fromRequest([
    ResultSort::ARG_ORDERBY => 'file',
    ResultSort::ARG_ORDER => 'desc',
]));

$listSql = $GLOBALS['wpdb']->reads[0];

check('the sorted listing wraps the grouped subquery whole', str_contains($listSql, FileGroups::subquery(ResultStore::STATUS_UNUSED)['sql']), true);
check('the ordering sits outside it, after the HAVING', strrpos($listSql, 'ORDER BY') > strrpos($listSql, 'HAVING'), true);
check('the grouped subquery still has exactly one WHERE', substr_count($listSql, 'WHERE'), 1);

$groupSource = (string) file_get_contents(ABSPATH . 'src/Scan/FileGroups.php');

check('and the sort never reached the grouping at all', str_contains($groupSource, 'ResultSort'), false);

// ------------------------------------------------------------------ result

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL  ' . $failure . PHP_EOL);
}

printf('%d passed, %d failed%s', $passed, count($failures), PHP_EOL);

exit($failures === [] ? 0 : 1);

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
 * It also pins the other edge of that unit of work, which has two halves. A
 * stale intermediate size — the file a size change left behind — is still one
 * of its original's names, read off the disk rather than off metadata that no
 * longer lists it, so a page pointing at it keeps the original alive. But it is
 * not an object of its own: no row, no group, no verdict, no bytes in the
 * saving. The one case where it does become one is where its original has gone
 * entirely, and that listing is asserted here too — including the two
 * near-misses that must stay out of it.
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

/** Enough of core's maybe_unserialize() for a metadata blob. */
function maybe_unserialize(string $value): mixed
{
    $data = @unserialize($value, ['allowed_classes' => false]);

    return $data === false && $value !== serialize(false) ? $value : $data;
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

/**
 * Only the part of the metadata this suite needs: the `sizes` array.
 *
 * It is core's record of which generated files it believes exist. A size
 * dropped out of it — a theme change, a re-registered size — leaves the file
 * it generated last time on disk with nothing naming it. That leftover is what
 * the tests below call a stale intermediate size.
 */
function wp_get_attachment_metadata(int $id): array|false
{
    $sizes = $GLOBALS['rows'][$id]['sizes'] ?? [];
    $original = $GLOBALS['rows'][$id]['original'] ?? '';

    if ($sizes === [] && $original === '') {
        return false;
    }

    $meta = ['sizes' => []];

    foreach ($sizes as $name => $file) {
        $meta['sizes'][$name] = ['file' => $file];
    }

    // The pre-scale upload core keeps beside a big image. It is a *different
    // file* from the one the row is grouped on, and the whole of freshet-142 is
    // that some other attachment can have been uploaded as exactly it.
    if ($original !== '') {
        $meta['original_image'] = $original;
    }

    return $meta;
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

    $row = $GLOBALS['rows'][$id];
    $files = [$row['file']];

    // Core removes the original and every size its *current* metadata names.
    // A generated file the metadata no longer lists is not touched, because
    // core has no record that it exists — which is exactly how a stale
    // intermediate size comes to outlive the attachment it was cut from.
    foreach ($row['sizes'] ?? [] as $size) {
        $files[] = dirname($row['file']) . '/' . $size;
    }

    // And the pre-scale original, unlinked **by name** in the row's own
    // directory with no check on who else might be standing on that name —
    // wp_delete_attachment_files() guards only the legacy $meta['thumb'].
    if (($row['original'] ?? '') !== '') {
        $files[] = dirname($row['file']) . '/' . $row['original'];
    }

    foreach ($files as $file) {
        if ($file === '') {
            continue;
        }

        $path = UPLOADS . '/' . $file;

        if (file_exists($path)) {
            unlink($path);
        }
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
        // The fixture's `used` flag stands in for the content references no
        // detector is here to find — and it stands in for the filter itself,
        // which a site really does use to overrule the detectors. So it wins
        // by default, and a case about what a DETECTOR decides opts out with
        // $GLOBALS['detectorsDecide'] rather than reading its own flag back.
        $flag = $GLOBALS['rows'][$rest[1]->id]['used'] ?? false;

        return ($GLOBALS['detectorsDecide'] ?? false) ? ($flag || $value) : $flag;
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

    /** What core leaves behind after a query that did not run. Db reads it. */
    public string $last_error = '';

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

        // The paths the attachment rows in one directory point at — the single
        // read OrphanSizes makes per directory, and the half of its two absence
        // tests that the disk cannot answer. Answered from the fixture library,
        // so a row whose file has been removed still counts as a row.
        // The sizes this directory's attachment metadata names — the other half
        // of the same test. Serialized, the way core stores it, and joined to
        // the attached file so one read covers the whole directory.
        if (str_contains($query['sql'], 'INNER JOIN')) {
            $prefix = rtrim(str_replace('\\', '', (string) ($query['params'][2] ?? '')), '%');
            $root = str_contains($query['sql'], 'NOT LIKE');
            $blobs = [];

            foreach ($GLOBALS['rows'] as $row) {
                if ($row['file'] === '' || ($row['sizes'] ?? []) === []) {
                    continue;
                }

                if ($root ? str_contains($row['file'], '/') : !str_starts_with($row['file'], $prefix)) {
                    continue;
                }

                $sizes = [];

                foreach ($row['sizes'] as $name => $file) {
                    $sizes[$name] = ['file' => $file];
                }

                $blobs[] = serialize(['sizes' => $sizes]);
            }

            return $blobs;
        }

        if (str_contains($query['sql'], 'meta_key = %s')) {
            $prefix = str_replace('\\', '', (string) ($query['params'][1] ?? ''));
            $prefix = rtrim($prefix, '%');
            $root = str_contains($query['sql'], 'NOT LIKE');
            $files = [];

            foreach ($GLOBALS['rows'] as $row) {
                if ($row['file'] === '') {
                    continue;
                }

                if ($root ? !str_contains($row['file'], '/') : str_starts_with($row['file'], $prefix)) {
                    $files[] = $row['file'];
                }
            }

            return $files;
        }

        return [];
    }

    public function get_results(string $sql, mixed $output = null): array
    {
        $query = $this->resolve($sql);

        // The rows in one directory and the path each stands on — the first half
        // of the file-claims guard, and the half that answers both collisions
        // the reviews measured (the file about to go is another row's own file).
        if (str_contains($query['sql'], 'SELECT post_id, meta_value')) {
            if ($this->refuse()) {
                return [];
            }

            return $this->inDirectory($query, static fn(int $id, array $row): array
                => [['post_id' => (string) $id, 'meta_value' => $row['file']]]);
        }

        // The metadata of those same rows, joined to the file it describes —
        // the mirror half: a neighbour that *names* the file being unlinked.
        //
        // Read a page at a time, so the cursor and the limit are modelled here
        // too: a directory is not bounded by anything, and the plugin must not
        // be allowed to pass a test by holding one in memory whole. The row id
        // stands in for meta_id, which is all the paging needs of it.
        if (str_contains($query['sql'], 'fc_file')) {
            if ($this->refuse()) {
                return [];
            }

            $params = $query['params'];
            $limit = (int) array_pop($params);
            $after = (int) array_pop($params);

            $rows = $this->inDirectory($query, static function (int $id, array $row): array {
                $meta = wp_get_attachment_metadata($id);

                return $meta === false ? [] : [[
                    'meta_id' => (string) $id,
                    'post_id' => (string) $id,
                    'meta_key' => '_wp_attachment_metadata',
                    'meta_value' => serialize($meta),
                    'fc_file' => $row['file'],
                ]];
            }, rtrim(str_replace('\\', '', (string) end($params)), '%'));

            $rows = array_values(array_filter($rows, static fn(array $r): bool => (int) $r['meta_id'] > $after));
            usort($rows, static fn(array $a, array $b): int => (int) $a['meta_id'] <=> (int) $b['meta_id']);

            return array_slice($rows, 0, $limit);
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

        return [];
    }

    public function get_var(string $sql): ?string
    {
        $this->resolve($sql);

        return '0';
    }

    /**
     * Fail the claims read the way the driver does: an empty array, exactly
     * what "no rows matched" looks like, and the message left on $wpdb. Db is
     * the only thing that can tell the two apart, and the guard has to refuse
     * on it rather than read the silence as "nobody else needs these files".
     *
     * Scoped to the claims reads on purpose — a flag the sibling lookup also
     * honoured would fail the deletion one step earlier and prove nothing
     * about this guard.
     */
    private function refuse(): bool
    {
        if (($GLOBALS['dbError'] ?? false) !== true) {
            return false;
        }

        $this->last_error = 'MySQL server has gone away';

        return true;
    }

    /**
     * The fixture rows whose file sits in the directory a `LIKE` names, run
     * through a shaper that builds the columns that read asked for.
     *
     * The directory is always the query's last parameter, and the root case is
     * the `NOT LIKE '%/%'` OrphanSizes established — a library with "organize
     * by date" switched off. The `LIKE` deliberately matches subdirectories
     * here, exactly as it does in MySQL, so the caller's own whole-directory
     * comparison is the thing under test rather than something the fixture
     * quietly did for it.
     *
     * @param array{sql: string, params: array<int, mixed>} $query
     * @param callable(int, array<string, mixed>): array<int, array<string, string>> $shape
     * @return array<int, array<string, string>>
     */
    private function inDirectory(array $query, callable $shape, ?string $prefix = null): array
    {
        $prefix ??= rtrim(str_replace('\\', '', (string) end($query['params'])), '%');
        $root = str_contains($query['sql'], 'NOT LIKE');
        $found = [];

        foreach ($GLOBALS['rows'] as $id => $row) {
            if ($row['file'] === '') {
                continue;
            }

            if ($root ? str_contains($row['file'], '/') : !str_starts_with($row['file'], $prefix)) {
                continue;
            }

            foreach ($shape((int) $id, $row) as $out) {
                $found[] = $out;
            }
        }

        return $found;
    }

    /** @return array{sql: string, params: array<int, mixed>} */
    public function last(): array
    {
        return $this->queries[count($this->queries) - 1];
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
    'src/Admin/MediaColumn.php',
    'src/Admin/StatusBadge.php',
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\DeleteController;
use FreshetUnusedMedia\Admin\MediaColumn;
use FreshetUnusedMedia\Admin\StatusBadge;
use FreshetUnusedMedia\Detector\FileClaimDetector;
use FreshetUnusedMedia\Detector\LikePatterns;
use FreshetUnusedMedia\Detector\RecentUploadDetector;
use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\DeleteBudget;
use FreshetUnusedMedia\Scan\FileClaims;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\OrphanSizes;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\SizeSiblings;
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
 * `sizes` is the intermediate sizes the attachment's metadata still names, as
 * size => basename; their files are written beside the original. A file with no
 * row at all — a stale size — goes on disk through orphanFile() instead.
 *
 * @param array<int, array{file: string, status?: string, used?: bool, bytes?: int, uploaded?: int, sizes?: array<string, string>}> $rows
 */
function library(array $rows): void
{
    foreach (glob(UPLOADS . '/*/*/*') ?: [] as $stale) {
        unlink($stale);
    }

    $GLOBALS['rows'] = [];
    $GLOBALS['meta'] = [];
    $GLOBALS['options'] = [];

    // A new library is a healthy database again, and an error left on $wpdb by
    // the last fixture would make every read after it refuse.
    $GLOBALS['dbError'] = false;
    $GLOBALS['wpdb']->last_error = '';

    // A new library is a new request: the sibling groups memoised for the last
    // fixture describe rows that no longer exist, and so do the directory
    // listing read for the last fixture's basenames and the claims index read
    // for the directory it was in.
    FileGroups::flush();
    SizeSiblings::flush();
    OrphanSizes::flush();
    FileClaims::flush();

    foreach ($rows as $id => $row) {
        $GLOBALS['rows'][$id] = [
            'file' => $row['file'],
            'status' => $row['status'] ?? 'inherit',
            'used' => $row['used'] ?? false,
            'uploaded' => $row['uploaded'] ?? time(),
            'sizes' => $row['sizes'] ?? [],
            'original' => $row['original'] ?? '',
        ];

        if ($row['file'] === '') {
            continue; // A row with no file has nothing on disk to share.
        }

        $path = UPLOADS . '/' . $row['file'];

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, str_repeat('x', $row['bytes'] ?? 1024));

        foreach ($row['sizes'] ?? [] as $size) {
            file_put_contents(dirname($path) . '/' . $size, str_repeat('x', 256));
        }

        // Written only if nothing else has: where the collision under test is
        // that another row *is* this original, that row's own file is this one
        // and there is a single file on disk, which is the whole point.
        if (($row['original'] ?? '') !== '' && !file_exists(dirname($path) . '/' . $row['original'])) {
            file_put_contents(dirname($path) . '/' . $row['original'], str_repeat('x', 2048));
        }
    }
}

/**
 * A file on disk that no attachment row names: the leftover of a size the
 * metadata used to list and does not any more.
 *
 * It is written directly rather than through library(), because having no row
 * is the whole of what makes it stale — and library() would give it one.
 */
function orphanFile(string $file, int $bytes = 256): void
{
    $path = UPLOADS . '/' . $file;

    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }

    file_put_contents($path, str_repeat('x', $bytes));

    // The directory has changed under whatever was remembered of it.
    SizeSiblings::flush();
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

check('a contradicted file is skipped, not deleted', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
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

check('one path deletes one file', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);
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

check('three rows are one deletion', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);
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

// -------------------------------------- one file, two groups (freshet-142)
//
// The grouping key is the row's own `_wp_attached_file`, and an attachment owns
// more files than that one. Two of them can be another attachment's own file,
// and then the two rows key on different paths, land in groups that never see
// each other, and can honestly reach opposite verdicts about one file on disk —
// while `wp_delete_attachment_files()` unlinks every size and every original
// **by name**, with no cross-attachment guard beyond the legacy `$meta['thumb']`
// lookup.
//
// The two ways in were found independently by two reviewers on three different
// production libraries, and both are asserted here. The key itself is left
// alone: it has to stay expressible in SQL as keySql(), and both relations live
// inside a serialized metadata blob. So the guard sits on the delete path, which
// is the only thing in this plugin that unlinks anything.

// --- the first way in: `original_image`. A big upload is scaled, so the row
// stands on `hero-scaled.jpg` and its metadata names `hero.jpg` — which is,
// separately, a whole other attachment's own file.

library([
    100 => ['file' => '2026/11/hero-scaled.jpg', 'original' => 'hero.jpg'],
    200 => ['file' => '2026/11/hero.jpg', 'used' => true],
]);

check('a scaled upload and its original are two keys', FileGroups::keyFor(100) === FileGroups::keyFor(200), false);
check('so neither is the other\'s sibling', FileGroups::siblings(100), [100]);
check('deleting the scaled row would unlink the original', isset(FileClaims::unlinks([100])['2026/11/hero.jpg']), true);
check('and that original is another row\'s own file', FileClaims::claimants([100]), ['2026/11/hero.jpg' => 200]);

$result = $deleter()->deleteVerified([100]);

check('so the deletion is refused', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('the file the other entry stands on survives', onDisk('2026/11/hero.jpg'), true);
check('and so does the row that was offered', isset($GLOBALS['rows'][100]), true);
check('a refused file reclaims nothing', ReclaimedLedger::read()['files'], 0);
check('and leaves the unused pool so a batch loop can end', isset($GLOBALS['meta'][100][ResultStore::META_STATUS]), false);

// The mirror of it, which is the same collision with the other row picked for
// deletion: removing 200 unlinks 200's own file, and 100's metadata names it.

library([
    100 => ['file' => '2026/11/hero-scaled.jpg', 'original' => 'hero.jpg', 'used' => true],
    200 => ['file' => '2026/11/hero.jpg'],
]);

check('a neighbour that merely names the file claims it too', FileClaims::claimants([200]), ['2026/11/hero.jpg' => 100]);

$result = $deleter()->deleteVerified([200]);

check('deleting the row that owns the shared file is refused too', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('the used entry keeps its original', onDisk('2026/11/hero.jpg'), true);
check('and its own scaled file is untouched', onDisk('2026/11/hero-scaled.jpg'), true);

// --- the second way in: a shared `sizes[].file`, on the real rows the review
// names. An upload whose own name already ends in dimensions has its sizes cut
// from the whole of that name — and that generated name is exactly what a
// second row was uploaded as.

library([
    1905 => ['file' => '2019/07/canon-300x225-300x225.jpg', 'used' => true],
    1926 => ['file' => '2019/07/canon-300x225.jpg', 'sizes' => ['medium' => 'canon-300x225-300x225.jpg']],
    1906 => ['file' => '2019/07/donner-225x300-225x300.jpg', 'used' => true],
    1927 => ['file' => '2019/07/donner-225x300.jpg', 'sizes' => ['medium' => 'donner-225x300-225x300.jpg']],
]);

check('a row and another row\'s size are two keys', FileGroups::keyFor(1926) === FileGroups::keyFor(1905), false);
check('the size a deletion would unlink is the other row\'s own file', FileClaims::claimants([1926]), ['2019/07/canon-300x225-300x225.jpg' => 1905]);
check('and the same holds for the second pair', FileClaims::claimants([1927]), ['2019/07/donner-225x300-225x300.jpg' => 1906]);

$result = $deleter()->deleteVerified([1926, 1927]);

check('both are refused rather than deleted', $result, ['deleted' => 0, 'skipped' => 2, 'failed' => 0, 'remaining' => []]);
check('the file the first used row stands on survives', onDisk('2019/07/canon-300x225-300x225.jpg'), true);
check('and so does the second', onDisk('2019/07/donner-225x300-225x300.jpg'), true);
check('neither used row was touched', isset($GLOBALS['rows'][1905], $GLOBALS['rows'][1906]), true);

// The mirror again: the row that owns the shared file is the unused one.

library([
    1905 => ['file' => '2019/07/canon-300x225-300x225.jpg'],
    1926 => ['file' => '2019/07/canon-300x225.jpg', 'sizes' => ['medium' => 'canon-300x225-300x225.jpg'], 'used' => true],
]);

$result = $deleter()->deleteVerified([1905]);

check('deleting the row a live size stands on is refused', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('the live entry keeps the size it renders', onDisk('2019/07/canon-300x225-300x225.jpg'), true);

// --- and the boundaries, because a guard that stops every deletion protects
// nothing: it has to be this collision it refuses, not the neighbourhood.

library([
    100 => ['file' => '2026/12/hero-scaled.jpg', 'original' => 'hero.jpg', 'sizes' => ['medium' => 'hero-300x200.jpg']],
    200 => ['file' => '2026/12/unrelated.jpg', 'original' => 'unrelated-big.jpg', 'used' => true],
    300 => ['file' => '2027/01/hero.jpg', 'used' => true],
]);

check('a neighbour with no file in common claims nothing', FileClaims::claimants([100]), []);

$result = $deleter()->deleteVerified([100]);

check('an unused file with a clear directory still deletes', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);
check('its own file goes', onDisk('2026/12/hero-scaled.jpg'), false);
check('its original goes with it', onDisk('2026/12/hero.jpg'), false);
check('its size goes with it', onDisk('2026/12/hero-300x200.jpg'), false);
check('the same basename in another directory is not a claim', onDisk('2027/01/hero.jpg'), true);
check('and the neighbour in its own directory is untouched', onDisk('2026/12/unrelated.jpg'), true);

// Two rows on one path are one group and one deletion — they must not read as
// claiming each other's file, or a duplicated upload would become undeletable.

library([
    100 => ['file' => '2027/02/logo.png'],
    200 => ['file' => '2027/02/logo.png'],
]);

check('a group does not claim its own file', FileClaims::claimants(FileGroups::siblings(100)), []);

$result = $deleter()->deleteVerified([100]);

check('so an ordinary duplicate group still deletes', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);
check('and the file goes', onDisk('2027/02/logo.png'), false);

// A read that did not answer is not an answer of "nobody else needs this file".
// Same reading as the sibling lookup and the re-scan: the file is not deleted,
// and it leaves the unused pool so the batch loop still terminates.

library([
    100 => ['file' => '2027/03/hero-scaled.jpg', 'original' => 'hero.jpg'],
    200 => ['file' => '2027/03/hero.jpg', 'used' => true],
]);

$GLOBALS['dbError'] = true;
$result = $deleter()->deleteVerified([100]);

check('a claims read that did not answer refuses the deletion', $result, ['deleted' => 0, 'skipped' => 0, 'failed' => 1, 'remaining' => []]);
check('and nothing on disk was touched', onDisk('2027/03/hero.jpg') && onDisk('2027/03/hero-scaled.jpg'), true);
check('and the file left the unused pool anyway', isset($GLOBALS['meta'][100][ResultStore::META_STATUS]), false);

$GLOBALS['dbError'] = false;

// --- the twinning the guard had to leave intact. Every count, every listing
// and the delete loop rest on keyFor() and keySql() being the same key, and the
// reason the fix is on the delete path rather than in the key is that this pair
// must go on agreeing. keySql() is read here rather than described: its shape is
// matched and then evaluated against the same fixture rows keyFor() is given.

/**
 * keySql(), executed. The expression is COALESCE(NULLIF(<value>, ''), CONCAT(
 * '<prefix>', <id>)) and nothing else, so it can be run in PHP against a row's
 * stored value — and if it ever stops being that expression this stops
 * pretending it can, rather than silently asserting something weaker.
 */
function evaluateKeySql(string $storedValue, int $id): string
{
    $sql = FileGroups::keySql();

    if (preg_match("/^COALESCE\(NULLIF\(fgf\.meta_value, ''\), CONCAT\('(.+)', p\.ID\)\)$/", $sql, $matches) !== 1) {
        return 'keySql() is no longer an expression this test can evaluate: ' . $sql;
    }

    return $storedValue !== '' ? $storedValue : $matches[1] . $id;
}

library([
    100 => ['file' => '2024/01/logo.png'],
    200 => ['file' => '2024/01/logo.png'],
    300 => ['file' => '2025/06/logo.png'],
    900 => ['file' => ''],
]);

$twins = [];

foreach (array_keys($GLOBALS['rows']) as $id) {
    $twins[$id] = FileGroups::keyFor($id) === evaluateKeySql($GLOBALS['rows'][$id]['file'], $id);
}

check('the PHP key and the SQL key agree on every fixture row', $twins, [100 => true, 200 => true, 300 => true, 900 => true]);
check('the SQL key still reads the attached file and nothing else', substr_count(FileGroups::keySql(), 'meta_value'), 1);
check('the guard added no column to the grouped subquery', str_contains(FileGroups::subquery()['sql'], 'fc_file'), false);
check('and no second join to it', substr_count(FileGroups::subquery()['sql'], 'LEFT JOIN'), 2);

// ------------------------------------------- the stale intermediate size
//
// A registered size changes, so WordPress regenerates the metadata and leaves
// the file it generated last time on disk. That leftover has no attachment row
// and no `_wp_attached_file` value, and every enumeration in this plugin is
// `post_type = 'attachment'` grouped on that key — so it is outside the unit of
// work altogether: not a group, not a count, not a listing row, not a badge and
// not a byte of the saving figure.
//
// That is a boundary rather than a defect, and it is pinned here under an
// honest label so the next reader meeting it cold does not file it as one
// (freshet-125). What it is NOT is "reported as unused": the scanner never sees
// the file to have an opinion about it.

library([
    800 => [
        'file' => '2026/03/banner.jpg',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['medium' => 'banner-300x200.jpg'],
    ],
]);

orphanFile('2026/03/banner-640x480.jpg');

check('the key is the original upload, never a generated size', FileGroups::keyFor(800), '2026/03/banner.jpg');
check('a stale size joins no group', FileGroups::siblings(800), [800]);
check('and is not a row the file verdict is counted from', FileGroups::fileStatus(800)['siblings'], [800]);
check('a stale size is not sized into the saving', FileSize::bytes(800), 8192);

// Three decoys beside the leftover, because the fix for it reads the directory
// and a directory holds other people's files. Two of these are size-shaped and
// belong to other stems, and one shares the stem but is not a generated size at
// all — admitting any of them would hand this attachment a name for a file it
// does not own, and a reference to that file would then keep the wrong original
// alive.
orphanFile('2026/03/banner-1-300x200.jpg');
orphanFile('2026/03/logo-300x200.jpg');
orphanFile('2026/03/banner-notasize.jpg');

$context = AttachmentContext::forAttachment(800);

// The registered/stale pair, and it is one file in both rows of it: the same
// `banner-640x480.jpg`, on disk either way, named by the metadata or not. A
// page carrying that URL is the same page either way too — so the answer must
// not depend on what the last metadata rewrite happened to list, which is what
// it did before (freshet-125 measured `match=false` here, and the original then
// scanned unused while a live page displayed it).
$markup = '<img src="https://example.test/wp-content/uploads/2026/03/banner-640x480.jpg" alt="">';

[$conditions, $params] = LikePatterns::basenameConditions('post_content', $context->basenames);

check('a size the metadata still names is matchable', in_array('banner-300x200.jpg', $context->basenames, true), true);
check('a size it no longer names is matchable too, because the file is there', in_array('banner-640x480.jpg', $context->basenames, true), true);

// Both engines, so a later change cannot half-drop it: the query has to fetch
// the row and the verifier has to accept it, and either one alone is a file
// that reads unused.
check('the query fetches a row carrying the stale size', in_array('%banner-640x480.jpg%', $params, true), true);
check('and the verifier resolves that markup to this attachment', LikePatterns::containsBasename($markup, $context->basenames), true);

// The boundary. Same directory, same first six letters, three different files.
check('another upload\'s own size is not this attachment\'s name', in_array('banner-1-300x200.jpg', $context->basenames, true), false);
check('nor is a size cut from a different stem', in_array('logo-300x200.jpg', $context->basenames, true), false);
check('nor is a sibling that is not size-shaped at all', in_array('banner-notasize.jpg', $context->basenames, true), false);
check('and neither reaches the query', in_array('%banner-1-300x200.jpg%', $params, true), false);

// Case one: the original is used, so nothing here is deletable at all.
$result = $deleter()->deleteVerified([800]);

check('a used original is not deleted', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('its live size stays with it', onDisk('2026/03/banner-300x200.jpg'), true);
check('and so does the stale one', onDisk('2026/03/banner-640x480.jpg'), true);
check('a skipped file reclaims nothing', ReclaimedLedger::read()['files'], 0);

// Case two: the original is itself orphaned, so the group is deletable as a
// whole. Core takes the original and the size its metadata names; the stale one
// it has no record of survives — unreported before the delete and unreclaimed
// after it. The saving figure understates by exactly that file, which is the
// direction to be wrong in.
library([
    801 => [
        'file' => '2026/04/flyer.jpg',
        'bytes' => 8192,
        'used' => false,
        'sizes' => ['medium' => 'flyer-300x200.jpg'],
    ],
]);

orphanFile('2026/04/flyer-640x480.jpg');

$result = $deleter()->deleteVerified([801]);

check('an orphaned original deletes as one whole file', $result, ['deleted' => 1, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);
check('the original is gone', onDisk('2026/04/flyer.jpg'), false);
check('the size its metadata named goes with it', onDisk('2026/04/flyer-300x200.jpg'), false);
check('the stale size is left behind, unreported and unreclaimed', onDisk('2026/04/flyer-640x480.jpg'), true);
check('the saving figure never claimed the stale size', ReclaimedLedger::read()['bytes'], 8192);
check('and counts the deletion as one file', ReclaimedLedger::read()['files'], 1);

// --------------------------------------- the size whose original is gone
//
// The other side of the boundary above. A generated size belongs to its
// original and is judged with it — but when the original is not there at all,
// the size belongs to nothing: no row names it, nothing sits at the stem's own
// path, and no page can be displaying a file the library has no record of.
//
// That case is reported on its own and is never folded into the unused figures.
// It has no attachment row, so it has no group, no verdict, no badge and no
// bytes in the saving — and it has nothing to corroborate the finding against
// either, which is why it is a listing and not a delete button.
//
// Both absences are required, and the two near-misses below are what says so:
// an original that is a row whose file has gone, and an original that is a file
// with no row. Either one present means the size is a derivative of something
// the site still knows about, and derivatives are not listable.

library([
    900 => [
        'file' => '2026/05/poster.jpg',
        'bytes' => 8192,
        'used' => false,
        'sizes' => ['medium' => 'poster-300x200.jpg'],
    ],
    901 => [
        'file' => '2026/05/gone.jpg',
        'bytes' => 8192,
        'used' => false,
    ],
]);

// The row for gone.jpg stays; its file does not. A library entry that points at
// a missing file still knows about the original.
unlink(UPLOADS . '/2026/05/gone.jpg');

orphanFile('2026/05/gone-640x480.jpg');   // near-miss: the row is still there
orphanFile('2026/05/flyer.jpg');          // near-miss: the file is still there
orphanFile('2026/05/flyer-640x480.jpg');
orphanFile('2026/05/lost-640x480.jpg');   // neither a row nor a file behind it

OrphanSizes::reset();
OrphanSizes::observeAttachment(900);

$orphans = OrphanSizes::read();

check('a size with no row and no original file is listed', in_array('2026/05/lost-640x480.jpg', $orphans['files'], true), true);
check('and it is the only one', $orphans['count'], 1);
check('a size whose original is a row with no file is not listed', in_array('2026/05/gone-640x480.jpg', $orphans['files'], true), false);
check('a size whose original is a file with no row is not listed', in_array('2026/05/flyer-640x480.jpg', $orphans['files'], true), false);
check('a size its own attachment still names is not listed', in_array('2026/05/poster-300x200.jpg', $orphans['files'], true), false);

// The directory is examined once per request however many attachments in it are
// scanned, and a second pass over the same state records the same answer rather
// than a second copy of it.
OrphanSizes::observeAttachment(901);

check('one directory is read once per request', OrphanSizes::read()['count'], 1);

OrphanSizes::flush();
OrphanSizes::observeAttachment(901);

check('and re-examining it replaces the finding rather than doubling it', OrphanSizes::read()['count'], 1);

// A document's previews are cut from a rendered image rather than from the
// document — `doc.pdf` becomes `doc-pdf.jpg` and the sizes come off that — so a
// preview whose `doc-pdf.jpg` has gone still has an original: the PDF itself,
// sitting right there. Listing it would be telling somebody a live document's
// thumbnail is orphaned.
library([
    910 => [
        'file' => '2026/06/report.pdf',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['medium' => 'report-pdf-300x169.jpg'],
    ],
]);

OrphanSizes::reset();
OrphanSizes::observeAttachment(910);

check('a live document\'s preview size is not orphaned', OrphanSizes::read()['count'], 0);

// And the same directory with the document itself gone: nothing names the
// preview any more, on disk or in the library, so now it is listed.
library([]);
orphanFile('2026/06/report-pdf-300x169.jpg');

OrphanSizes::reset();
$GLOBALS['rows'][911] = ['file' => '2026/06/other.jpg', 'status' => 'inherit', 'used' => false, 'uploaded' => time(), 'sizes' => []];
orphanFile('2026/06/other.jpg');
OrphanSizes::observeAttachment(911);

check('the same preview with no document behind it is listed', OrphanSizes::read()['files'], ['2026/06/report-pdf-300x169.jpg']);

// An attachment's sizes are named in its metadata, and its *attached file* can
// carry a different stem from them. Editing an image in wp-admin is the common
// way there: the row's file becomes `hero-e1673970542774.png` while its
// metadata still names sizes cut from `hero`. Reading only `_wp_attached_file`
// found no row for that stem and listed the sizes as orphans — while
// AttachmentContext was already protecting the same files as basenames of the
// same live row. This is the regression boundary: both reads, or the two halves
// of the plugin disagree about one file again.

/** How many times the metadata read has gone to the database this run. */
function namedStemLookups(): int
{
    $n = 0;

    foreach ($GLOBALS['wpdb']->queries as $query) {
        if (str_contains($query['sql'], 'INNER JOIN')) {
            ++$n;
        }
    }

    return $n;
}

library([
    920 => [
        'file' => '2026/08/hero-e1673970542774.png',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['wide' => 'hero-1280x640.png'],
    ],
    921 => [
        'file' => '2026/08/banner.jpg',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['medium' => 'banner-300x200.jpg'],
    ],
]);

orphanFile('2026/08/hero-320x160.png'); // the same edit's leftover, unnamed now
orphanFile('2026/08/lost-320x160.png'); // nothing on disk or in the library

OrphanSizes::reset();
$before = namedStemLookups();
OrphanSizes::observeAttachment(920);

$edited = OrphanSizes::read();

check('a size the metadata names is not orphaned by its row being edited', in_array('2026/08/hero-1280x640.png', $edited['files'], true), false);
check('nor is a leftover of that edit, on the same stem', in_array('2026/08/hero-320x160.png', $edited['files'], true), false);
check('and a genuine orphan in that directory is still listed', $edited['files'], ['2026/08/lost-320x160.png']);
check('the metadata read is one query for the whole directory', namedStemLookups() - $before, 1);

// And it is not asked for at all where the cheap half of the test has already
// settled every candidate: a directory whose sizes all have their originals
// beside them costs exactly what it did before.
library([
    930 => [
        'file' => '2026/09/whole.jpg',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['medium' => 'whole-300x200.jpg'],
    ],
]);

OrphanSizes::reset();
$before = namedStemLookups();
OrphanSizes::observeAttachment(930);

check('a directory with nothing to answer for is not read twice', namedStemLookups() - $before, 0);
check('and nothing is listed for it', OrphanSizes::read()['count'], 0);

// A size-shaped filename is not proof of a generated size. Somebody exports
// `Logo-Bikepal-300x200.png` at that size and uploads it under that name, and
// then the file *is* an attachment's own `_wp_attached_file` — while its stem
// reads as `Logo-Bikepal-300x200`, which no row and no file on disk answers
// for. Both absence tests therefore passed on a file the library owns, and the
// list said it belonged to nothing. The row's own path is the record that
// settles it, and it comes from the query that was already being made.
library([
    940 => [
        'file' => '2026/10/Logo-Bikepal-300x200.png',
        'bytes' => 8192,
        'used' => true,
        'sizes' => ['thumbnail' => 'Logo-Bikepal-300x200-150x150.png'],
    ],
]);

orphanFile('2026/10/lost-640x480.jpg'); // no row, no original: the honest case

OrphanSizes::reset();
$before = count($GLOBALS['wpdb']->queries);
OrphanSizes::observeAttachment(940);

$selfNamed = OrphanSizes::read();

check('a size-shaped upload that is its own row\'s file is not orphaned', in_array('2026/10/Logo-Bikepal-300x200.png', $selfNamed['files'], true), false);
check('and the genuine orphan beside it is still the whole list', $selfNamed['files'], ['2026/10/lost-640x480.jpg']);
check('reading the rows\' own paths costs no extra query', count($GLOBALS['wpdb']->queries) - $before, 2);

// Nothing on this list is in the unused figures, and the way that is held is
// structural: the classes that produce the count, the listings, the badge, the
// saving figure and the deletions cannot see it at all.
foreach ([
    'src/Scan/ResultStore.php',
    'src/Scan/FileGroups.php',
    'src/Scan/FileSize.php',
    'src/Scan/ReclaimedLedger.php',
    'src/Scan/DeleteBudget.php',
    'src/Admin/StatusBadge.php',
    'src/Admin/MediaColumn.php',
    'src/Admin/DeleteController.php',
] as $file) {
    check(
        $file . ' cannot reach the listing',
        str_contains((string) file_get_contents(ABSPATH . $file), 'OrphanSizes'),
        false
    );
}

$orphanSource = (string) file_get_contents(ABSPATH . 'src/Scan/OrphanSizes.php');

check('the listing is free on every build', str_contains($orphanSource, 'isPro'), false);
check('and deletes nothing', str_contains($orphanSource, 'unlink') || str_contains($orphanSource, 'wp_delete_attachment'), false);

// It is rendered as its own section of the scan tab, beside the scan that
// produced it, and not inside either listing.
$toolsSource = (string) file_get_contents(ABSPATH . 'src/Admin/ToolsPage.php');

check('the screen renders it as its own section', str_contains($toolsSource, '$this->renderOrphanSizes();'), true);

// --------------------------------------------------------- trashed rows
//
// A trashed row is a deletion someone started and can still undo. Erasing the
// file now would empty the trash out from under them.

library([
    600 => ['file' => '2026/02/poster.jpg'],
    601 => ['file' => '2026/02/poster.jpg', 'status' => 'trash'],
]);

$result = $deleter()->deleteVerified([600]);

check('a trashed sibling holds the file', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('the trashed row can still be restored to its file', onDisk('2026/02/poster.jpg'), true);

// ------------------------------------------------- the request that ran out
//
// Re-verifying one file is one scan per attachment row standing on it, so on a
// large library a five-file batch is minutes of queries inside a single web
// request. The bound is a budget the loop reads between files (freshet-150);
// what is asserted here is that stopping is a *shorter* run and never a
// partial one. A zero budget is the sharpest version of it: one file is always
// started — a request that started none would hand the same work on for ever —
// and the rest are handed back untouched.

library([
    100 => ['file' => '2024/03/one.png'],
    200 => ['file' => '2024/03/two.png'],
    300 => ['file' => '2024/03/three.png'],
]);

// Scanned first, because that is what a screen listing them means, and because
// the verdict below has to be one the run left alone rather than one it never
// wrote.
foreach ([100, 200, 300] as $id) {
    (new Scanner(new ResultStore()))->scan($id);
}

$result = $deleter()->deleteVerified([100, 200, 300], new DeleteBudget(0.0));

check('a spent budget still decides one file', $result['deleted'], 1);
check('and hands the rest back', $result['remaining'], [200, 300]);
check('nothing is counted twice', $result['skipped'] + $result['failed'], 0);
check('the file it reached is gone', onDisk('2024/03/one.png'), false);
check('the ones it did not are untouched', onDisk('2024/03/two.png') && onDisk('2024/03/three.png'), true);

// Untouched means their stored verdict too: the screen that listed them was
// rendered from this meta, and a file dropped out of the unused pool by a run
// that never looked at it is a file the next batch is not offered.
check('an unreached file keeps its verdict', $GLOBALS['meta'][200][ResultStore::META_STATUS], ResultStore::STATUS_UNUSED);
check('and so does the one after it', $GLOBALS['meta'][300][ResultStore::META_STATUS], ResultStore::STATUS_UNUSED);

// The bookkeeping is written by the short run as well as the finished one.
check('a short run still ledgers what it freed', ReclaimedLedger::read()['files'], 1);

// Resuming is the caller handing back what it was given: no state is kept
// anywhere, so the second request is the first one with a shorter list.
$result = $deleter()->deleteVerified($result['remaining'], new DeleteBudget(0.0));

check('the resumed run picks up where it stopped', $result['deleted'], 1);
check('and hands on what is still left', $result['remaining'], [300]);
check('the second file is now gone', onDisk('2024/03/two.png'), false);
check('the third is still waiting', onDisk('2024/03/three.png'), true);

// A budget with room in it changes nothing: the loop is the loop.
library([
    100 => ['file' => '2024/03/one.png'],
    200 => ['file' => '2024/03/two.png'],
    300 => ['file' => '2024/03/three.png'],
]);

$result = $deleter()->deleteVerified([100, 200, 300], new DeleteBudget(600.0));

check('a budget with room in it reaches every file', $result, ['deleted' => 3, 'skipped' => 0, 'failed' => 0, 'remaining' => []]);

// And a file the budget stopped at is one *decision* short, never one row
// short: the group is expanded, claimed, re-scanned and deleted inside one
// iteration, so there is no point at which some of a file's rows are gone.
$deleteSource = (string) file_get_contents(ABSPATH . 'src/Admin/DeleteController.php');

check(
    'the budget is read between files, not inside one',
    (int) strpos($deleteSource, 'hasRoom()') < (int) strpos($deleteSource, 'FileGroups::siblings('),
    true
);

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

// ------------------- the same file, decided at the scan (freshet-153)
//
// The guard above keeps the file, and that is where this stopped: the listing
// went on offering 1926, the user selected it, and the refusal reached the
// screen as a "skipped … found in use on re-check" tally with no reason on the
// row. One file on disk, and the scan disagreeing with itself out loud about
// it — 1905 used, 1926 unused, because the two key on different paths.
//
// FileClaimDetector asks the delete path's question at scan time, of the same
// class, so a claimed file never gets on the list. Every case below runs the
// real detector through the real Scanner: what is under test is the verdict a
// scan reaches, not a hand-written meta payload.

$GLOBALS['detectors'] = [new FileClaimDetector()];
$GLOBALS['detectorsDecide'] = true;

library([
    1905 => ['file' => '2019/07/canon-300x225-300x225.jpg', 'used' => true],
    1926 => ['file' => '2019/07/canon-300x225.jpg', 'sizes' => ['medium' => 'canon-300x225-300x225.jpg']],
    1906 => ['file' => '2019/07/donner-225x300-225x300.jpg', 'used' => true],
    1927 => ['file' => '2019/07/donner-225x300.jpg', 'sizes' => ['medium' => 'donner-225x300-225x300.jpg']],
]);

$verdict = static fn(int $id): string => $scan($id)['status'];

check('the pair no longer disagrees about one file', [$verdict(1905), $verdict(1926)], [ResultStore::STATUS_USED, ResultStore::STATUS_USED]);
check('and neither does the second pair', [$verdict(1906), $verdict(1927)], [ResultStore::STATUS_USED, ResultStore::STATUS_USED]);

$refs = $scan(1926)['refs'];

check('the held row carries exactly one piece of evidence', count($refs), 1);
check('it is a claim rather than a reference to this row', $refs[0]->match, FileClaims::MATCH);
check('it names the entry that would lose a file', $refs[0]->objectId, 1905);
check('and the file it would lose', $refs[0]->detail, '2019/07/canon-300x225-300x225.jpg');
check('a claim counts as used, or the listing would go on offering it', $refs[0]->countsAsUsed(), true);

$store = new ResultStore();
$badge1926 = StatusBadge::forRow(1926, $store);

check('a claim is the only thing holding the file', FileClaims::holdsAlone($store->refs(1926)), true);
check('so the row says why', str_contains($badge1926, 'Held back — deleting it would remove a file another library entry uses'), true);
check('rather than a count of places it is used', str_contains($badge1926, 'Used ('), false);
check('one held row, one sentence', substr_count($badge1926, 'Held back'), 1);

// The delete path still refuses it. This narrows what reaches that guard; it
// does not replace it — the library can change between the scan and the click.
$result = $deleter()->deleteVerified([1926]);

check('the guard from freshet-142 is untouched', $result, ['deleted' => 0, 'skipped' => 1, 'failed' => 0, 'remaining' => []]);
check('and the file the other entry stands on survives', onDisk('2019/07/canon-300x225-300x225.jpg'), true);

// Stored refs are capped, and a truncated list cannot say what the whole list
// would have said. Same reading as the grace: believing one is how a used file
// gets called held.
check('a truncated list is not claim-only', FileClaims::holdsAlone(['count' => 4, 'refs' => $refs]), false);
check('and neither is a list with a real reference in it', FileClaims::holdsAlone(['count' => 2, 'refs' => [$refs[0], new Reference(
    detector: 'post-content',
    objectType: 'post',
    objectId: 12,
    detail: 'post_content',
    match: 'url',
    confidence: Reference::CONFIRMED,
)]]), false);

// --- and the boundaries. A detector that holds every file back protects
// nothing: what it must refuse is this collision, not the neighbourhood.

library([
    100 => ['file' => '2027/04/hero-scaled.jpg', 'original' => 'hero.jpg', 'sizes' => ['medium' => 'hero-300x200.jpg']],
    200 => ['file' => '2027/04/unrelated.jpg', 'used' => true],
]);

check('a file nothing else claims still scans unused', $verdict(100), ResultStore::STATUS_UNUSED);
check('and holds nothing back on its row', StatusBadge::forRow(100, new ResultStore()), StatusBadge::render(ResultStore::STATUS_UNUSED));

// The claim is asked of the whole group, never of one row. claimants() excludes
// the rows it is asked about, so a lone row would report its own siblings —
// every duplicated upload claiming its twin, and a library of files nothing
// could ever delete.

library([
    100 => ['file' => '2027/05/logo.png'],
    200 => ['file' => '2027/05/logo.png'],
]);

check('a duplicate group does not claim itself into use', $verdict(100), ResultStore::STATUS_UNUSED);
check('from either of its rows', $verdict(200), ResultStore::STATUS_UNUSED);

// A read that did not answer is not an answer of "nobody else needs this file".
// The scan reaches no verdict at all rather than reaching "unused" (freshet-141).

library([
    1905 => ['file' => '2019/07/canon-300x225-300x225.jpg', 'used' => true],
    1926 => ['file' => '2019/07/canon-300x225.jpg', 'sizes' => ['medium' => 'canon-300x225-300x225.jpg']],
]);

$GLOBALS['dbError'] = true;

check('a scan whose claims read failed records no verdict', $scan(1926)['status'], Scanner::STATUS_ERROR);
check('and stores none', isset($GLOBALS['meta'][1926][ResultStore::META_STATUS]), false);

$GLOBALS['dbError'] = false;

// --- what makes asking this on every scanned row affordable: the two reads are
// per directory, and a directory is read once. A month of uploads shares one,
// so the cost of the detector across a batch is two queries, not two per file.

library([
    1905 => ['file' => '2019/07/canon-300x225-300x225.jpg', 'used' => true],
    1926 => ['file' => '2019/07/canon-300x225.jpg', 'sizes' => ['medium' => 'canon-300x225-300x225.jpg']],
    1906 => ['file' => '2019/07/donner-225x300-225x300.jpg', 'used' => true],
    1927 => ['file' => '2019/07/donner-225x300.jpg', 'sizes' => ['medium' => 'donner-225x300-225x300.jpg']],
]);

$before = count($GLOBALS['wpdb']->queries);
FileClaims::claimants([1926]);
$first = count($GLOBALS['wpdb']->queries) - $before;

FileClaims::claimants([1927]);
$second = count($GLOBALS['wpdb']->queries) - $before - $first;

check('the first claim in a directory reads it twice', $first, 2);
check('every claim after that in the same directory reads nothing', $second, 0);

FileClaims::flush();
$before = count($GLOBALS['wpdb']->queries);
FileClaims::claimants([1926]);

check('and a flush makes the next one read again', count($GLOBALS['wpdb']->queries) - $before, 2);
check('a memo that was not flushed is still the right answer', FileClaims::claimants([1926]), ['2019/07/canon-300x225-300x225.jpg' => 1905]);

unset($GLOBALS['detectors'], $GLOBALS['detectorsDecide']);

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

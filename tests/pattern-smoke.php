<?php

declare(strict_types=1);

/**
 * Pattern smoke test — boundary checks for the detection primitives.
 *
 * Pure PHP: no WordPress, no database, no test framework. The handful of core
 * functions the tested classes touch are stubbed below against fixture data,
 * so this runs anywhere PHP 8.2+ does:
 *
 *     php tests/pattern-smoke.php
 *
 * It guards the one invariant the whole plugin rests on: SQL matches broadly,
 * PHP verifies precisely — attachment 123 must never be satisfied by 1234.
 * Every LikePatterns helper and every PostContentDetector content regex has at
 * least one positive and one boundary-negative case here. Add to it whenever
 * you add a pattern.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
// The usual `defined('ABSPATH') || exit` idiom is unavailable here: the file
// defines ABSPATH itself, on the next line. Guard on the SAPI instead.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

/** Where the fixture's files live when a case needs them on disk. */
define('UPLOADS', sys_get_temp_dir() . '/freshet-unusedmedia-pattern-tests');

// --------------------------------------------------------------- WP stubs

/**
 * Fixture attachments: id 123, hero.jpg with a -scaled original, two sizes and
 * a WebP source; id 124, a non-ASCII filename with no sizes; id 125, an image
 * edited in wp-admin, whose superseded generation core records under
 * `_wp_attachment_backup_sizes`; id 126, the shape a production library carries
 * on one row — that key present with an empty value rather than an array.
 */
const FIXTURE_ID = 123;
const FIXTURE_UNICODE_ID = 124;
const FIXTURE_UNICODE_NAME = '写真.jpg';
const FIXTURE_UNICODE_STEM = '写真';
const FIXTURE_EDITED_ID = 125;
const FIXTURE_EDITED_FILE = 'sunset-e1673970542774.jpg';
const FIXTURE_EDITED_SIZE = 'sunset-e1673970542774-300x200.jpg';
const FIXTURE_PRE_EDIT_FILE = 'sunset.jpg';
const FIXTURE_PRE_EDIT_SIZE = 'sunset-300x200.jpg';
const FIXTURE_EMPTY_BACKUP_ID = 126;

/**
 * A second attachment row standing on FIXTURE_ID's own `_wp_attached_file` — a
 * translation copy, a duplicated post, the same image uploaded twice. It is one
 * file with two rows, which is what a sibling group is, and it carries a size
 * the first row's metadata does not name so the union of the group's basenames
 * is provably a union rather than the representative's list.
 */
const FIXTURE_SIBLING_ID = 127;
const FIXTURE_SIBLING_SIZE = 'hero-1024x768.jpg';

function wp_basename(string $path): string
{
    return basename(str_replace('\\', '/', $path));
}

function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    // What wp-admin's image editor leaves behind: an entry per superseded file,
    // keyed `<size>-orig` (or `<size>-<timestamp>` after a second edit). The
    // third entry here is deliberately not an array — a value core never wrote
    // is still a value the scan can be handed, and it must contribute nothing
    // rather than warn.
    //
    // The empty row and the absent key are one branch on purpose: a stored
    // empty string and a key that was never written both read as '' through
    // `get_post_meta($id, $key, true)`, so the fixture cannot make them differ
    // and neither can the code under test. Both are asserted below all the same,
    // because they are two different things on the library that produced them.
    if ($key === '_wp_attachment_backup_sizes') {
        return match ($id) {
            FIXTURE_EDITED_ID => [
                'full-orig' => ['width' => 1600, 'height' => 1200, 'file' => FIXTURE_PRE_EDIT_FILE],
                'medium-orig' => ['width' => 300, 'height' => 200, 'file' => FIXTURE_PRE_EDIT_SIZE],
                'thumbnail-orig' => '',
            ],
            FIXTURE_EMPTY_BACKUP_ID => '',
            default => '',
        };
    }

    if ($key !== '_wp_attached_file') {
        return '';
    }

    return match ($id) {
        FIXTURE_ID => '2026/07/hero-scaled.jpg',
        FIXTURE_UNICODE_ID => '2026/07/' . FIXTURE_UNICODE_NAME,
        FIXTURE_EDITED_ID => '2026/07/' . FIXTURE_EDITED_FILE,
        FIXTURE_EMPTY_BACKUP_ID => '2026/07/beach.jpg',
        FIXTURE_SIBLING_ID => '2026/07/hero-scaled.jpg',
        default => '',
    };
}

/**
 * Core primes a page of rows' postmeta in one read. SharedReads does it before
 * it takes a group's basenames, so the union costs one round trip rather than
 * one per row; nothing here caches, so it only has to exist.
 */
function update_postmeta_cache(array $ids): bool
{
    return true;
}

/**
 * Where the fixture's file would be. AttachmentContext reads the directory this
 * names, looking for size files the metadata has stopped listing, so the tests
 * that care about those (the stale-size section below) create it and the rest
 * run against a directory that is not there — which is also the offloaded-media
 * case, and must be silent rather than fatal.
 */
function get_attached_file(int $id, bool $unfiltered = false): string|false
{
    $file = get_post_meta($id, '_wp_attached_file', true);

    return $file === '' ? false : UPLOADS . '/' . $file;
}

/**
 * The ACF sibling lookup every meta detector makes ('_<key>' = field_…). No
 * fixture carries one, so a match here is the detector's own verdict rather
 * than an ACF upgrade of it.
 */
function get_term_meta(int $id, string $key, bool $single = false): string
{
    return '';
}

function get_user_meta(int $id, string $key, bool $single = false): string
{
    return '';
}

function get_comment_meta(int $id, string $key, bool $single = false): string
{
    return '';
}

/** Enough of core's maybe_unserialize() for the theme-mod and widget branches. */
function maybe_unserialize(string $value): mixed
{
    if (!is_serialized($value)) {
        return $value;
    }

    $data = @unserialize(trim($value), ['allowed_classes' => false]);

    return $data === false ? $value : $data;
}

function wp_get_attachment_metadata(int $id): array|false
{
    return match ($id) {
        FIXTURE_ID => [
            'original_image' => 'hero.jpg',
            'sizes' => [
                'medium' => ['file' => 'hero-300x200.jpg', 'sources' => ['image/webp' => ['file' => 'hero-300x200.webp']]],
                'thumbnail' => ['file' => 'hero-150x150.jpg'],
            ],
        ],
        // An edit rewrites the metadata wholesale, so it describes the edited
        // generation and nothing else — which is the whole reason the superseded
        // one has to be read from somewhere.
        FIXTURE_EDITED_ID => ['sizes' => ['medium' => ['file' => FIXTURE_EDITED_SIZE]]],
        // The same upload, regenerated with one more registered size. Two rows
        // on one path do not have to agree about their metadata, and this is the
        // disagreement that decides whether the shared needles are a union.
        FIXTURE_SIBLING_ID => [
            'original_image' => 'hero.jpg',
            'sizes' => ['large' => ['file' => FIXTURE_SIBLING_SIZE]],
        ],
        default => false,
    };
}

function get_post(int $id): ?object
{
    return $id === FIXTURE_ID ? (object) ['post_parent' => 7] : null;
}

/** Enough of core's is_serialized() for the fixtures: the type prefix. */
function is_serialized(string $data): bool
{
    return (bool) preg_match('/^(?:[aOsib]:|N;)/', trim($data));
}

// --------------------------------------------------- options, transients, HTTP
//
// Everything below serves the license-state checks at the end of this file.
// RemoteLicense is pure decision logic over four things — a stored key, a
// cached verdict, a recorded last success and one HTTP reply — so all four are
// stubbed here and the real class is exercised, not a paraphrase of it.

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;

$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['http'] = null;      // canned wp_remote_post reply; null means unreachable
$GLOBALS['httpCalls'] = 0;
$GLOBALS['filters'] = [];   // hook => forced value, for the filtered upload grace

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['options'][$name] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['options'][$name] = $value;

    return true;
}

function get_transient(string $name): mixed
{
    return $GLOBALS['transients'][$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    $GLOBALS['transients'][$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset($GLOBALS['transients'][$name]);

    return true;
}

function home_url(): string
{
    return 'https://example.test';
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/\\');
}

function apply_filters(string $hook, mixed $value, mixed ...$rest): mixed
{
    return $GLOBALS['filters'][$hook] ?? $value;
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

function __(string $text, string $domain = ''): string
{
    return $text;
}

function _n(string $single, string $plural, int $number, string $domain = ''): string
{
    return $number === 1 ? $single : $plural;
}

function number_format_i18n(int|float $number): string
{
    return (string) $number;
}

/** Enough of WP_Error for the "license server unreachable" branch. */
class WP_Error
{
    public function __construct(private readonly string $message = 'Connection refused')
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    ++$GLOBALS['httpCalls'];

    return $GLOBALS['http'] ?? new WP_Error();
}

function wp_remote_retrieve_body(mixed $response): string
{
    return is_array($response) ? (string) ($response['body'] ?? '') : '';
}

function wp_remote_retrieve_response_code(mixed $response): int
{
    return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
}

/**
 * Minimal $wpdb: esc_like for the query builders, and a recording prepare()
 * so a detector's SQL placeholders can be checked against its params.
 */
$GLOBALS['wpdb'] = new class {
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $termmeta = 'wp_termmeta';
    public string $usermeta = 'wp_usermeta';
    public string $options = 'wp_options';
    public string $comments = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $lastSql = '';
    public array $lastParams = [];

    /**
     * wpdb's own two fields for "did that work", and the switch this test
     * drives them with. Set $failWith and the next read behaves exactly as a
     * failed one does in core: last_error carries the driver's message and the
     * result is the same empty array a query matching nothing returns.
     */
    public string $last_error = '';
    public string $failWith = '';

    /** Which read fails: 0 or 1 is all of them, N is the Nth onward. */
    public int $failFrom = 0;
    public int $calls = 0;

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $sql, mixed ...$params): string
    {
        $this->lastSql = $sql;
        $this->lastParams = $params;

        return $sql;
    }

    /** Canned rows for the next get_results(); a detector's find() reads these. */
    public array $rows = [];

    public function get_results(string $sql): array
    {
        ++$this->calls;

        $fails = $this->failWith !== '' && $this->calls >= max(1, $this->failFrom);
        $this->last_error = $fails ? $this->failWith : '';

        return $fails ? [] : $this->rows;
    }
};

require_once ABSPATH . 'src/Scan/QueryFailed.php';
require_once ABSPATH . 'src/Scan/Db.php';
require_once ABSPATH . 'src/Scan/SizeSiblings.php';
require_once ABSPATH . 'src/Scan/SharedReads.php';
require_once ABSPATH . 'src/Scan/AttachmentContext.php';
require_once ABSPATH . 'src/Scan/Reference.php';
require_once ABSPATH . 'src/Detector/DetectorInterface.php';
require_once ABSPATH . 'src/Detector/LikePatterns.php';
require_once ABSPATH . 'src/Detector/PostContentDetector.php';
require_once ABSPATH . 'src/Detector/TermDescriptionDetector.php';
require_once ABSPATH . 'src/Detector/TermMetaDetector.php';
require_once ABSPATH . 'src/Detector/UserMetaDetector.php';
require_once ABSPATH . 'src/Detector/CommentDetector.php';
require_once ABSPATH . 'src/Detector/PostmetaDetector.php';
require_once ABSPATH . 'src/Detector/OptionsDetector.php';
require_once ABSPATH . 'src/License/LicenseInterface.php';
require_once ABSPATH . 'src/License/LicenseClient.php';
require_once ABSPATH . 'src/License/RemoteLicense.php';
require_once ABSPATH . 'src/License/NoLicense.php';
require_once ABSPATH . 'src/Scan/UploadGrace.php';

use FreshetUnusedMedia\Detector\LikePatterns;
use FreshetUnusedMedia\Detector\PostContentDetector;
use FreshetUnusedMedia\Detector\CommentDetector;
use FreshetUnusedMedia\Detector\OptionsDetector;
use FreshetUnusedMedia\Detector\PostmetaDetector;
use FreshetUnusedMedia\Detector\TermDescriptionDetector;
use FreshetUnusedMedia\Detector\TermMetaDetector;
use FreshetUnusedMedia\Detector\UserMetaDetector;
use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\NoLicense;
use FreshetUnusedMedia\License\RemoteLicense;
use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\SharedReads;
use FreshetUnusedMedia\Scan\SizeSiblings;
use FreshetUnusedMedia\Scan\Reference;
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

$id = FIXTURE_ID;
$ctx = AttachmentContext::forAttachment($id);
$names = $ctx->basenames;

// -------------------------------------------------- AttachmentContext

check('context id', $ctx->id, 123);
check('context parent', $ctx->parentId, 7);
check('attached basename collected', in_array('hero-scaled.jpg', $names, true), true);
check('pre-scaled original collected', in_array('hero.jpg', $names, true), true);
check('size variants collected', in_array('hero-300x200.jpg', $names, true) && in_array('hero-150x150.jpg', $names, true), true);
check('alternate-mime source collected', in_array('hero-300x200.webp', $names, true), true);
check('basenames deduplicated', count($names), count(array_unique($names)));
check('ascii names gain no encoded variants', count($names), 5);

$unicode = AttachmentContext::forAttachment(FIXTURE_UNICODE_ID)->basenames;
check('unicode basename collected', in_array(FIXTURE_UNICODE_NAME, $unicode, true), true);
check('percent-encoded variant collected', in_array('%E5%86%99%E7%9C%9F.jpg', $unicode, true), true);
check('json-escaped variant collected', in_array('\\u5199\\u771f.jpg', $unicode, true), true);
check('unicode variants are exactly three', count($unicode), 3);

// -------------------------------------------------- containsBasename

check('basename in raw URL', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/hero-300x200.jpg">', $names), true);
check('unrelated filename', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/other.jpg">', $names), false);
check('empty basename never matches', LikePatterns::containsBasename('anything at all', ['']), false);
check('no basenames at all', LikePatterns::containsBasename('anything at all', []), false);
check('escaped slashes leave the basename intact', LikePatterns::containsBasename('{"url":"https:\/\/example.test\/wp-content\/uploads\/2026\/07\/hero-300x200.jpg"}', $names), true);
check('unicode basename, browser-encoded URL', LikePatterns::containsBasename('<a href="/wp-content/uploads/2026/07/%E5%86%99%E7%9C%9F.jpg">', $unicode), true);
check('unicode basename, json_encode default', LikePatterns::containsBasename(json_encode(['url' => 'https://example.test/uploads/' . FIXTURE_UNICODE_NAME]), $unicode), true);
check('unicode basename, unrelated file', LikePatterns::containsBasename('/uploads/%E5%86%99%E7%9C%9F-2.jpg', $unicode), false);

// Case. MySQL's LIKE folds, so basenameConditions() fetches a row spelling the
// file in any case; a case-sensitive verifier here then dropped it and the
// attachment could scan unused. Both directions matter: the fold must admit the
// same file written differently, and must not start admitting a different file.
check('uppercase URL resolves to the file', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/HERO-300X200.JPG">', $names), true);
check('mixed-case URL resolves to the file', LikePatterns::containsBasename('<a href="/uploads/2026/07/Hero-300x200.JPG">x</a>', $names), true);
check('uppercase extension alone resolves to the file', LikePatterns::containsBasename('/uploads/2026/07/hero-300x200.JPG', $names), true);
check('uppercase unrelated filename still does not match', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/OTHER.JPG">', $names), false);
check('folding does not blur a digit boundary', LikePatterns::containsBasename('/uploads/2026/07/HERO-300X201.JPG', $names), false);
check('folding does not make a longer stem match', LikePatterns::containsBasename('/uploads/2026/07/HERO-2-300X200.JPG', $names), false);
check('uppercase unicode variant, unrelated file', LikePatterns::containsBasename('/uploads/%E5%86%99%E7%9C%9F-2.JPG', $unicode), false);

// Accents (freshet-156). strtolower() is byte-wise, so it folded HERO onto
// hero and left HÉRO alone — while basenameConditions() fetched that row under
// every collation WordPress ships. The case of an accented letter is folded,
// because a volume that folds case folds É onto é and the URL resolves. The
// collation's accent-insensitivity is deliberately NOT followed: no filesystem
// serves hero.jpg for a stored héro.jpg, so that row is over-fetch. Both halves
// are asserted here, and so are the two invariants the fold must not blur.
$accented = ['héro.jpg', 'héro-300x200.jpg'];

check('uppercase accented URL resolves to the file', LikePatterns::containsBasename('<img src="/uploads/2026/07/HÉRO.JPG">', $accented), true);
check('mixed-case accented URL resolves to the file', LikePatterns::containsBasename('<a href="/uploads/2026/07/HÉro-300X200.JPG">x</a>', $accented), true);
check('accented fold does not blur a digit boundary', LikePatterns::containsBasename('/uploads/2026/07/HÉRO-300X201.JPG', $accented), false);
check('accented fold does not make a longer stem match', LikePatterns::containsBasename('/uploads/2026/07/HÉRO-2-300X200.JPG', ['héro-300x200.jpg']), false);
check('a different accented filename still does not match', LikePatterns::containsBasename('<img src="/uploads/2026/07/HÉROS.JPG">', ['héro.jpg']), false);
check('an accent is not folded away: unaccented spelling stays unmatched', LikePatterns::containsBasename('<img src="/uploads/2026/07/hero.jpg">', ['héro.jpg']), false);
check('an accent is not folded away: accented spelling of an ascii file stays unmatched', LikePatterns::containsBasename('<img src="/uploads/2026/07/héro.jpg">', ['hero.jpg']), false);
check('an ascii basename is unaffected by an accented value', LikePatterns::containsBasename('<p>café</p><img src="/uploads/2026/07/HERO-300X200.JPG">', $names), true);

// -------------------------------------------------- isExactId

check('exact id', LikePatterns::isExactId('123', $id), true);
check('exact id, padded', LikePatterns::isExactId("  123\n", $id), true);
check('longer id is not exact', LikePatterns::isExactId('1234', $id), false);
check('prefixed id is not exact', LikePatterns::isExactId('9123', $id), false);
check('list is not exact', LikePatterns::isExactId('123,4', $id), false);
check('empty is not exact', LikePatterns::isExactId('', $id), false);

// -------------------------------------------------- inCommaList

check('id mid-list', LikePatterns::inCommaList('4,123,9', $id), true);
check('id at list start', LikePatterns::inCommaList('123,9', $id), true);
check('id at list end', LikePatterns::inCommaList('4,123', $id), true);
check('id as whole list', LikePatterns::inCommaList('123', $id), true);
check('spaced list members trimmed', LikePatterns::inCommaList('4, 123 ,9', $id), true);
check('longer id in list', LikePatterns::inCommaList('4,1234,9', $id), false);
check('id as substring of member', LikePatterns::inCommaList('4,91230,9', $id), false);

// -------------------------------------------------- hasSerializedInt

check('serialized int', LikePatterns::hasSerializedInt('a:1:{i:0;i:123;}', $id), true);
check('serialized longer int', LikePatterns::hasSerializedInt('a:1:{i:0;i:1234;}', $id), false);
check('serialized int without terminator', LikePatterns::hasSerializedInt('i:123', $id), false);
check('serialized prefixed int', LikePatterns::hasSerializedInt('i:9123;', $id), false);
// Deliberately NOT folded: the fetching LIKE admits I:123; but serialize()
// never writes it and unserialize() never reads it, so rejecting it drops no
// reference. Asserted so a later pass folds it on purpose or not at all.
check('uppercase serialized token is not a reference', LikePatterns::hasSerializedInt('a:1:{i:0;I:123;}', $id), false);

// -------------------------------------------------- hasSerializedString

check('serialized string', LikePatterns::hasSerializedString('a:1:{s:5:"image";s:3:"123";}', $id), true);
check('serialized longer string', LikePatterns::hasSerializedString('s:4:"1234"', $id), false);
check('serialized prefixed string', LikePatterns::hasSerializedString('s:4:"9123"', $id), false);
check('length prefix must agree', LikePatterns::hasSerializedString('s:6:"123456"', $id), false);
check('uppercase serialized string token is not a reference', LikePatterns::hasSerializedString('S:3:"123"', $id), false);

// -------------------------------------------------- hasJsonId

check('json id', LikePatterns::hasJsonId('{"id":123}', $id), true);
check('json id, spaced', LikePatterns::hasJsonId('{"id": 123}', $id), true);
check('json id as string', LikePatterns::hasJsonId('{"id":"123"}', $id), true);
check('json longer id', LikePatterns::hasJsonId('{"id":1234}', $id), false);
check('json longer id as string', LikePatterns::hasJsonId('{"id":"1234"}', $id), false);
check('json suffixed key is not id', LikePatterns::hasJsonId('{"media_id":123}', $id), false);
check('json ids array is not a bare id', LikePatterns::hasJsonId('{"ids":[123]}', $id), false);
check('uppercase json key', LikePatterns::hasJsonId('{"ID":123}', $id), true);
check('mixed-case json key', LikePatterns::hasJsonId('{"Id": "123"}', $id), true);
check('uppercase suffixed key is still not id', LikePatterns::hasJsonId('{"MEDIA_ID":123}', $id), false);
check('uppercase key, longer id', LikePatterns::hasJsonId('{"ID":1234}', $id), false);

// -------------------------------------------------- hasQuotedId

check('quoted id in a shortcode attribute', LikePatterns::hasQuotedId('[gallery ids="123"]', $id), true);
check('quoted id as a json string leaf', LikePatterns::hasQuotedId('{"image":"123"}', $id), true);
check('quoted longer id', LikePatterns::hasQuotedId('[gallery ids="1234"]', $id), false);
check('quoted prefixed id', LikePatterns::hasQuotedId('[gallery ids="9123"]', $id), false);
check('quoted id inside a list is not a whole value', LikePatterns::hasQuotedId('[gallery ids="4,123,9"]', $id), false);
check('unquoted id', LikePatterns::hasQuotedId('[gallery ids=123]', $id), false);
check('single-quoted id is not reached by the broad pass', LikePatterns::hasQuotedId("[gallery ids='123']", $id), false);

// ------------------------------- attachment-page links (hasAttachmentIdQuery,
//                                 hasAttachmentLinkId, hasDataId)
//
// A document is linked to, not embedded, so these three forms are the whole
// record of the reference: a PDF whose only use is a text link to its own
// attachment page used to scan unused.

check('attachment_id query arg', LikePatterns::hasAttachmentIdQuery('<a href="https://example.test/?attachment_id=123">PDF</a>', $id), true);
check('attachment_id after another arg', LikePatterns::hasAttachmentIdQuery('/?p=9&attachment_id=123', $id), true);
check('attachment_id with an encoded ampersand', LikePatterns::hasAttachmentIdQuery('/?p=9&#038;attachment_id=123', $id), true);
check('attachment_id followed by another arg', LikePatterns::hasAttachmentIdQuery('/?attachment_id=123&preview=true', $id), true);
check('attachment_id, longer id', LikePatterns::hasAttachmentIdQuery('/?attachment_id=1234', $id), false);
check('attachment_id, prefixed id', LikePatterns::hasAttachmentIdQuery('/?attachment_id=9123', $id), false);
check('a plain post id is not an attachment_id', LikePatterns::hasAttachmentIdQuery('/?p=123', $id), false);
check('uppercase attachment_id query arg', LikePatterns::hasAttachmentIdQuery('<a href="/?ATTACHMENT_ID=123">PDF</a>', $id), true);
check('uppercase attachment_id, longer id', LikePatterns::hasAttachmentIdQuery('/?ATTACHMENT_ID=1234', $id), false);

check('wp-att in a rel attribute', LikePatterns::hasAttachmentLinkId('<a href="/hero" rel="attachment wp-att-123">hero</a>', $id), true);
check('wp-att in a class attribute', LikePatterns::hasAttachmentLinkId('<a class="link wp-att-123" href="/hero">hero</a>', $id), true);
check('wp-att, longer id', LikePatterns::hasAttachmentLinkId('<a rel="attachment wp-att-1234">x</a>', $id), false);
check('wp-att, prefixed id', LikePatterns::hasAttachmentLinkId('<a rel="attachment wp-att-9123">x</a>', $id), false);
check('wp-image is not wp-att', LikePatterns::hasAttachmentLinkId('<img class="wp-image-123">', $id), false);
check('uppercase wp-att marker', LikePatterns::hasAttachmentLinkId('<a rel="attachment WP-ATT-123">hero</a>', $id), true);
check('uppercase wp-att, longer id', LikePatterns::hasAttachmentLinkId('<a rel="attachment WP-ATT-1234">x</a>', $id), false);

// The editor's image class, the one verifier post_content and comment_content
// share. The needle behind it is left-delimited only, so the longer id IS
// fetched and these boundaries are the whole of what rejects it.
check('wp-image class', LikePatterns::hasImageClass('<img class="wp-image-123" src="/x.jpg">', $id), true);
check('wp-image class among others', LikePatterns::hasImageClass('<img class="alignnone size-large wp-image-123">', $id), true);
check('wp-image class, longer id', LikePatterns::hasImageClass('<img class="wp-image-1234">', $id), false);
check('wp-image class, prefixed id', LikePatterns::hasImageClass('<img class="wp-image-9123">', $id), false);
check('wp-att is not wp-image', LikePatterns::hasImageClass('<a rel="attachment wp-att-123">x</a>', $id), false);
check('a bare number in prose is not an image class', LikePatterns::hasImageClass('<p>We ordered 123 of them.</p>', $id), false);
check('uppercase wp-image class', LikePatterns::hasImageClass('<img class="WP-IMAGE-123">', $id), true);
check('uppercase wp-image class, longer id', LikePatterns::hasImageClass('<img class="WP-IMAGE-1234">', $id), false);

check('data-id attribute', LikePatterns::hasDataId('<figure data-id="123"></figure>', $id), true);
check('data-id, single quoted', LikePatterns::hasDataId("<figure data-id='123'></figure>", $id), true);
check('data-id, unquoted', LikePatterns::hasDataId('<figure data-id=123></figure>', $id), true);
check('data-id, longer id', LikePatterns::hasDataId('<figure data-id="1234"></figure>', $id), false);
check('data-id, prefixed id', LikePatterns::hasDataId('<figure data-id="9123"></figure>', $id), false);
check('data-id, unquoted longer id', LikePatterns::hasDataId('<figure data-id=1234></figure>', $id), false);
check('data-id, id inside a list', LikePatterns::hasDataId('<figure data-id="4,123,9"></figure>', $id), false);
check('another data attribute is not data-id', LikePatterns::hasDataId('<figure data-slide-id="123"></figure>', $id), false);
// An HTML attribute name is case-insensitive to the browser, so DATA-ID renders
// the file exactly as data-id does.
check('uppercase data-id attribute', LikePatterns::hasDataId('<figure DATA-ID="123"></figure>', $id), true);
check('uppercase data-id, longer id', LikePatterns::hasDataId('<figure DATA-ID="1234"></figure>', $id), false);
check('uppercase other data attribute is still not data-id', LikePatterns::hasDataId('<figure DATA-SLIDE-ID="123"></figure>', $id), false);

// -------------------------------------------------- structureContains

check('int in nested array', LikePatterns::structureContains(['a' => ['b' => [4, 123]]], $id, $names), true);
check('numeric string in array', LikePatterns::structureContains(['image' => '123'], $id, $names), true);
check('basename in nested string', LikePatterns::structureContains(['url' => 'https://example.test/hero-300x200.jpg'], $id, $names), true);
check('object property', LikePatterns::structureContains((object) ['image' => 123], $id, $names), true);
check('array key is not a value', LikePatterns::structureContains([123 => 'anything'], $id, $names), false);
check('longer int nearby', LikePatterns::structureContains(['ids' => [1234, 91230]], $id, $names), false);
check('id inside a longer string', LikePatterns::structureContains(['note' => 'order 1234 shipped'], $id, $names), false);
check('float is not the id', LikePatterns::structureContains([123.0], $id, $names), false);
check('empty structure', LikePatterns::structureContains([], $id, $names), false);

// JSON at a string leaf — settings blobs under arbitrary keys, JSON nested in serialized data.
check('json string leaf, number value', LikePatterns::structureContains('{"logo":123}', $id, $names), true);
check('json string leaf, string value', LikePatterns::structureContains('{"logo":"123"}', $id, $names), true);
check('json string leaf, nested array', LikePatterns::structureContains(['settings' => '{"items":[{"img":123}]}'], $id, $names), true);
check('json string leaf, longer id', LikePatterns::structureContains('{"logo":1234}', $id, $names), false);
check('json string leaf, id as key only', LikePatterns::structureContains('{"123":"x"}', $id, $names), false);
check('json string leaf, basename', LikePatterns::structureContains('{"bg":"https:\/\/example.test\/hero.jpg"}', $id, $names), true);
check('non-json string containing digits', LikePatterns::structureContains('ticket 123 closed', $id, $names), false);
check('invalid json is not decoded', LikePatterns::structureContains('{"logo":123', $id, $names), false);

// Cyclic structures — serialized r:/R: reference tokens unserialize into a graph
// that points back at itself. Every check here is also a termination check: the
// pre-guard walk did not return from any of them.

$selfArray = [];
$selfArray['self'] = &$selfArray;
check('array reference cycle terminates on a miss', LikePatterns::structureContains($selfArray, $id, $names), false);

$selfArrayHit = [];
$selfArrayHit['self'] = &$selfArrayHit;
$selfArrayHit['img'] = 123;
check('array reference cycle still finds the id', LikePatterns::structureContains($selfArrayHit, $id, $names), true);

$parent = new stdClass();
$child = new stdClass();
$parent->child = $child;
$child->parent = $parent;
check('object cycle terminates on a miss', LikePatterns::structureContains($parent, $id, $names), false);

$child->image = 123;
check('object cycle still finds the id past the loop', LikePatterns::structureContains($parent, $id, $names), true);

// Serialized round trip: the shape the detectors actually receive.
check('serialized R: cycle terminates on a miss', LikePatterns::structureContains(LikePatterns::decodeStored('a:1:{s:4:"self";R:1;}'), $id, $names), false);
check('serialized r: cycle terminates on a miss', LikePatterns::structureContains(LikePatterns::decodeStored('O:8:"stdClass":1:{s:4:"self";r:1;}'), $id, $names), false);
check('serialized r: cycle still finds the id', LikePatterns::structureContains(LikePatterns::decodeStored('O:8:"stdClass":2:{s:4:"self";r:1;s:3:"img";i:123;}'), $id, $names), true);

// Deep but acyclic data is walked exactly, either side of the marker threshold.
$deepHit = 123;
$deepMiss = 1234;
for ($level = 0; $level < 60; ++$level) {
    $deepHit = ['level' => $deepHit];
    $deepMiss = ['level' => $deepMiss];
}
check('deep acyclic structure finds the id', LikePatterns::structureContains($deepHit, $id, $names), true);
check('deep acyclic structure misses cleanly', LikePatterns::structureContains($deepMiss, $id, $names), false);

// Past the ceiling the walk gives up, and giving up means "used" — over-keeping
// a file is recoverable, deleting a used one is not.
$overDeep = 1234;
for ($level = 0; $level < 400; ++$level) {
    $overDeep = ['level' => $overDeep];
}
check('a structure too deep to resolve is kept, not reported unused', LikePatterns::structureContains($overDeep, $id, $names), true);

// A notices-shaped option: nested objects, cross-links and a self-reference, in
// the size range the multilingual plugins store. Bounded time and memory is the
// assertion — unguarded, this is the walk that never returned.
$notice = new stdClass();
$notice->id = 'notice-1';
$notice->text = str_repeat('Translation status copy for a stored notice. ', 180);
$notice->actions = ['dismiss' => ['label' => 'Dismiss', 'url' => 'https://example.test/x']];

$group = new stdClass();
$group->notices = ['notice-1' => $notice];
$group->parent = $group;
$notice->group = $group;
$notice->siblings = [$notice];

$payload = serialize($group);
check('reference fixture is option-sized', strlen($payload) >= 8259, true);
check('reference fixture carries object references', substr_count($payload, 'r:') >= 3, true);

$peakBefore = memory_get_peak_usage();
$startedAt = microtime(true);
$fixtureVerdict = LikePatterns::structureContains(LikePatterns::decodeStored($payload), $id, $names);
$elapsed = microtime(true) - $startedAt;
$peakGrowth = memory_get_peak_usage() - $peakBefore;

check('reference fixture resolves to a verdict', $fixtureVerdict, false);
check('reference fixture completes in bounded time', $elapsed < 2.0, true);
check('reference fixture completes in bounded memory', $peakGrowth < 8 * 1024 * 1024, true);

$notice->image = 123;
check('reference fixture still finds the id', LikePatterns::structureContains(LikePatterns::decodeStored(serialize($group)), $id, $names), true);

// -------------------------------------------------- decodeStored / decodeJson

check('decodeJson object', LikePatterns::decodeJson(' {"a":1}'), ['a' => 1]);
check('decodeJson array', LikePatterns::decodeJson('[1,2]'), [1, 2]);
check('decodeJson scalar is null', LikePatterns::decodeJson('123'), null);
check('decodeJson empty is null', LikePatterns::decodeJson(''), null);
check('decodeJson serialized is null', LikePatterns::decodeJson('a:1:{i:0;i:1;}'), null);
check('decodeStored serialized array', LikePatterns::decodeStored('a:1:{s:4:"logo";i:123;}'), ['logo' => 123]);
check('decodeStored json', LikePatterns::decodeStored('{"logo":123}'), ['logo' => 123]);
check('decodeStored plain string passes through', LikePatterns::decodeStored('123'), '123');
check('decodeStored never instantiates classes', LikePatterns::decodeStored('O:8:"stdClass":1:{s:4:"logo";i:123;}') instanceof \__PHP_Incomplete_Class, true);
check('decodeStored incomplete object still searchable', LikePatterns::structureContains(LikePatterns::decodeStored('O:8:"stdClass":1:{s:4:"logo";i:123;}'), $id, $names), true);
check('decodeStored broken serialized falls back to string', LikePatterns::decodeStored('a:1:{s:4:"logo";'), 'a:1:{s:4:"logo";');

// -------------------------------------------------- query builders

[$conditions, $params] = LikePatterns::idConditions('pm.meta_value', $id);
check('id conditions and params align', count($conditions) === count($params), true);
check('id conditions cover every verifier', count($conditions), 19);
check('exact condition is bare', $params[0], '123');
check('comma-start pattern', $params[1], '123,%');
check('serialized int pattern is wildcarded', $params[4], '%i:123;%');
check('json string value pattern', $params[8], '%"123"%');
check('json number value patterns', [$params[9], $params[10]], ['%:123,%', '%:123}%']);
check('compact array member patterns', [$params[11], $params[12], $params[13]], ['%[123,%', '%[123]%', '%,123]%']);
check('whitespace-anchored number patterns', [$params[14], $params[15], $params[16]], ['% 123%', "%\n123%", "%\t123%"]);
check('attachment-page link patterns', [$params[17], $params[18]], ['%attachment\_id=123%', '%wp-att-123%']);
check('the link conditions are the same two the helper builds alone', LikePatterns::attachmentLinkConditions('c.comment_content', $id)[1], [$params[17], $params[18]]);

// The image-class needle is its own builder and is NOT part of idConditions() —
// it is bound by the one column that wants markup needles without id ones, and
// adding it here would widen the nineteen every id-carrying detector pays.
[$classConditions, $classParams] = LikePatterns::imageClassConditions('c.comment_content', $id);
check('the image-class builder is one condition', [count($classConditions), count($classParams)], [1, 1]);
check('the image-class pattern is the editor class, wildcarded', $classParams[0], '%wp-image-123%');
check('idConditions does not carry the image class', in_array('%wp-image-123%', $params, true), false);

[$nameConditions, $nameParams] = LikePatterns::basenameConditions('p.post_content', $names);
check('one condition per basename', count($nameConditions), count($names));
check('basename patterns are wildcarded', str_starts_with($nameParams[0], '%') && str_ends_with($nameParams[0], '%'), true);
check('no basenames, no conditions', LikePatterns::basenameConditions('p.post_content', [])[0], []);

// -------------------------------------------------- post_content regexes

$verify = new ReflectionMethod(PostContentDetector::class, 'verify');
$verify->setAccessible(true);
$detector = new PostContentDetector();
$match = static fn(string $content): ?string => $verify->invoke($detector, $content, $ctx);

check('wp-image class', $match('<img class="wp-image-123" src="/x.jpg">'), 'wp-image-class');
check('wp-image class, longer id', $match('<img class="wp-image-1234" src="/x.jpg">'), null);
check('wp-image class, prefixed id', $match('<img class="wp-image-9123" src="/x.jpg">'), null);
check('uppercase wp-image class', $match('<img class="WP-IMAGE-123" src="/x.jpg">'), 'wp-image-class');
check('uppercase wp-image class, longer id', $match('<img class="WP-IMAGE-1234" src="/x.jpg">'), null);
check('block attribute', $match('<!-- wp:image {"id":123,"sizeSlug":"large"} -->'), 'block-id');
check('block attribute, longer id', $match('<!-- wp:image {"id":1234,"sizeSlug":"large"} -->'), null);
check('gallery block', $match('<!-- wp:gallery {"ids":[4,123,9],"linkTo":"none"} -->'), 'gallery');
check('gallery block, single item', $match('<!-- wp:gallery {"ids":[123]} -->'), 'gallery');
check('gallery block, longer id', $match('<!-- wp:gallery {"ids":[4,1234,9]} -->'), null);
check('gallery shortcode', $match('[gallery ids="4,123,9"]'), 'gallery');
check('gallery shortcode, single quotes', $match("[gallery columns=\"2\" ids='123']"), 'gallery');
check('gallery shortcode, longer id', $match('[gallery ids="4,1234,9"]'), null);
check('resized URL in content', $match('<a href="https://example.test/wp-content/uploads/2026/07/hero-300x200.jpg">file</a>'), 'url');
check('uppercase resized URL in content', $match('<a href="https://example.test/wp-content/uploads/2026/07/HERO-300X200.JPG">file</a>'), 'url');
check('uppercase unrelated URL in content', $match('<a href="https://example.test/wp-content/uploads/2026/07/OTHER.JPG">file</a>'), null);
check('original URL in content', $match('<a href="https://example.test/wp-content/uploads/2026/07/hero.jpg">file</a>'), 'url');
check('unrelated content', $match('<p>Nothing to see here — 1234, other.jpg.</p>'), null);
check('empty content', $match(''), null);

// Field values stored in block delimiters (ACF blocks and the same model elsewhere).
check('acf block, image field as string', $match('<!-- wp:acf/hero {"id":"block_64a1f2","name":"acf/hero","data":{"title":"Hi","_title":"field_a1","image":"123","_image":"field_b2"},"mode":"edit"} /-->'), 'acf-block');
check('acf block, image field as int', $match('<!-- wp:acf/hero {"id":"block_64a1f2","name":"acf/hero","data":{"image":123,"_image":"field_b2"},"mode":"preview"} /-->'), 'acf-block');
check('acf block, keyed by field key', $match('<!-- wp:acf/hero {"name":"acf/hero","data":{"field_b2":"123"},"mode":"edit"} /-->'), 'acf-block');
check('acf block, gallery of strings', $match('<!-- wp:acf/gallery {"name":"acf/gallery","data":{"images":["123","456"],"_images":"field_c3"},"mode":"edit"} /-->'), 'acf-block');
check('acf block, gallery of ints', $match('<!-- wp:acf/gallery {"name":"acf/gallery","data":{"images":[456,123],"_images":"field_c3"},"mode":"edit"} /-->'), 'acf-block');
check('acf block with inner blocks', $match('<!-- wp:acf/section {"name":"acf/section","data":{"bg":"123","_bg":"field_d4"},"mode":"preview"} --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- /wp:acf/section -->'), 'acf-block');
check('acf block, pretty-printed json', $match("<!-- wp:acf/card {\n    \"id\": \"block_5fb5\",\n    \"name\": \"acf\\/card\",\n    \"data\": {\n        \"icon\": \"123\",\n        \"_icon\": \"field_5fb5\"\n    },\n    \"mode\": \"edit\"\n} /-->"), 'acf-block');
check('acf block, longer id', $match('<!-- wp:acf/hero {"name":"acf/hero","data":{"image":"1234","_image":"field_b2"},"mode":"edit"} /-->'), null);
check('acf block, id in a text field', $match('<!-- wp:acf/hero {"name":"acf/hero","data":{"note":"ticket 123","_note":"field_b2"},"mode":"edit"} /-->'), null);
check('acf block, block id is not the attachment', $match('<!-- wp:acf/hero {"id":"block_123","name":"acf/hero","data":{"title":"x","_title":"field_a1"},"mode":"edit"} /-->'), null);

// The whole attribute object of a namespaced block is searched, not only its
// "data" key — "data" is one field framework's convention, and a vendor block
// that keeps its ID at the top level (or under any other key) is the case that
// used to scan unused. The spacer row is the accepted cost of that: a dimension
// that happens to equal the ID keeps one file too many, which is recoverable,
// where the miss it replaces deleted a file that was in use.
check('namespaced block attribute outside data', $match('<!-- wp:acme/hero {"imageId":123} /-->'), 'acf-block');
check('namespaced block attribute outside data, as a string', $match('<!-- wp:acme/hero {"imageId":"123"} /-->'), 'acf-block');
check('namespaced block id nested under a non-data key', $match('<!-- wp:acme/slider {"settings":{"slides":[{"image":123}]},"loop":true} /-->'), 'acf-block');
check('namespaced block attribute, longer id', $match('<!-- wp:acme/hero {"imageId":1234} /-->'), null);
check('namespaced block attribute, id inside a longer number', $match('<!-- wp:acme/hero {"imageId":91230} /-->'), null);
check('namespaced block nested attribute, longer id', $match('<!-- wp:acme/slider {"settings":{"slides":[{"image":1234}]}} /-->'), null);
check('namespaced block dimension matching the id is kept, not lost', $match('<!-- wp:vendor/spacer {"height":123,"unit":"px"} /-->'), 'acf-block');

// Pretty-printed JSON — whitespace between the colon and the number. The
// verifier decodes the delimiter so it never cared about the spacing; the SQL
// pass did, and the query rows further down are the half that was broken.
check('namespaced block attribute, one space after the colon', $match('<!-- wp:acme/hero {"imageId": 123} /-->'), 'acf-block');
check('namespaced block attribute, several spaces after the colon', $match('<!-- wp:acme/hero {"imageId":    123} /-->'), 'acf-block');
check('namespaced block attribute, newline and indent after the colon', $match("<!-- wp:acme/hero {\n    \"imageId\":\n        123\n} /-->"), 'acf-block');
check('namespaced block array element on its own indented line', $match("<!-- wp:acme/gallery {\n    \"slides\": [\n        456,\n        123\n    ]\n} /-->"), 'acf-block');
check('namespaced block array element, tab-indented', $match("<!-- wp:acme/gallery {\n\t\"slides\": [\n\t\t123\n\t]\n} /-->"), 'acf-block');
check('pretty-printed gallery ids array', $match("<!-- wp:gallery {\n    \"ids\": [\n        456,\n        123\n    ]\n} -->"), 'gallery');
check('namespaced block attribute, spaced longer id', $match('<!-- wp:acme/hero {"imageId": 1234} /-->'), null);
check('namespaced block attribute, spaced id inside a longer number', $match('<!-- wp:acme/hero {"imageId": 91230} /-->'), null);
check('namespaced block array element, longer id on its own line', $match("<!-- wp:acme/gallery {\n    \"slides\": [\n        1234\n    ]\n} /-->"), null);

// Core blocks stay out of it, and this row is the deliberate boundary rather
// than an oversight: core's attribute schemas are finite and core-defined,
// every core block carrying an attachment names it "id"/"ids" — verified above
// with digit boundaries — and each also saves the URL or a wp-image-N class
// into its markup. Nothing is missed by leaving them out, while core's numeric
// attributes are on every page.
check('core block attrs are not searched as block fields', $match('<!-- wp:spacer {"height":123} --><div style="height:123px"></div><!-- /wp:spacer -->'), null);
check('undecodable namespaced block keeps a bounded id', $match('<!-- wp:acf/hero {"data":{"image":"123",} /-->'), 'acf-block');
check('undecodable namespaced block, longer id', $match('<!-- wp:acf/hero {"data":{"image":"1234",} /-->'), null);

// Shortcode attributes holding an ID or an ID list.
check('page-builder image shortcode', $match('[vc_single_image image="123" img_size="full"]'), 'shortcode');
check('page-builder gallery shortcode', $match('[vc_gallery type="image_grid" images="4,123,9"]'), 'shortcode');
check('core playlist shortcode', $match('[playlist ids="123"]'), 'shortcode');
check('single-quoted shortcode attribute', $match("[foo image='123']"), 'shortcode');
check('unquoted shortcode attribute', $match('[foo image=123]'), 'shortcode');
check('self-closing shortcode', $match('[foo image="123" /]'), 'shortcode');
check('shortcode, longer id', $match('[vc_single_image image="1234"]'), null);
check('shortcode, id inside a list member', $match('[vc_gallery images="4,91230,9"]'), null);
check('shortcode, dimension attribute ignored', $match('[embed width="123" height="123"]'), null);
check('shortcode, count attribute ignored', $match('[gallery columns="123"]'), null);
check('shortcode, id in body text', $match('[caption]Order 123 shipped[/caption]'), null);
check('shortcode without attributes', $match('[gallery]'), null);
check('json array is not a shortcode', $match('{"images":["123"]}'), null);
check('markdown-style link is not a shortcode', $match('[read more](https://example.test/?p=123)'), null);

// Links to the attachment's own page. This is what a reference to a document
// looks like — a PDF or a .docx is linked to, never embedded — so all three
// forms verifying to null meant a linked document scanned as orphaned.
check('attachment page link, query arg', $match('<p><a href="https://example.test/?attachment_id=123">Download the brochure</a></p>'), 'attachment-page');
check('attachment page link, editor rel marker', $match('<p><a href="https://example.test/brochure/" rel="attachment wp-att-123">Brochure</a></p>'), 'attachment-page');
check('attachment page link, class marker', $match('<p><a class="wp-att-123" href="https://example.test/brochure/">Brochure</a></p>'), 'attachment-page');
check('attachment page link, pretty permalink with the id appended', $match('<a href="/2026/07/brochure/?attachment_id=123&#038;preview=1">Brochure</a>'), 'attachment-page');
check('attachment page link, longer id in the query arg', $match('<a href="/?attachment_id=1234">x</a>'), null);
check('attachment page link, prefixed id in the query arg', $match('<a href="/?attachment_id=9123">x</a>'), null);
check('attachment page link, longer id in the rel marker', $match('<a rel="attachment wp-att-1234">x</a>'), null);
check('attachment page link, prefixed id in the rel marker', $match('<a rel="attachment wp-att-9123">x</a>'), null);
check('a post permalink carrying the same number is not an attachment link', $match('<a href="/?p=123">x</a>'), null);

// data-id="123" — carried by gallery, slider and lightbox markup. The broad
// pass always admitted it (the quoted-value needle); nothing verified it.
check('data-id attribute in markup', $match('<div class="slider"><figure data-id="123"></figure></div>'), 'id-attribute');
check('data-id attribute, single quoted', $match("<figure data-id='123'></figure>"), 'id-attribute');
check('data-id attribute, longer id', $match('<figure data-id="1234"></figure>'), null);
check('data-id attribute, prefixed id', $match('<figure data-id="9123"></figure>'), null);

// The rewritten query: every placeholder bound, autosave filter first, then the
// id needles — the scanned row's own exclusion is no longer in the statement
// (freshet-155; it is asserted where it moved to, further down).
$detector->find($ctx);
$sql = $GLOBALS['wpdb']->lastSql;
$bound = $GLOBALS['wpdb']->lastParams;
check('post_content query placeholders match params', substr_count($sql, '%s') + substr_count($sql, '%d'), count($bound));
check('post_content query first binds the autosave name', $bound[0], '%-autosave-v1');
check('post_content query then binds the id needles', $bound[1], '%wp-image-123%');
check('post_content query reaches the excerpt', str_contains($sql, 'p.post_excerpt LIKE'), true);
check('post_content query admits autosave revisions only', str_contains($sql, "(p.post_type <> 'revision' OR p.post_name LIKE %s)"), true);

// A verifier the broad pass never reaches is a verifier that does nothing, so
// the block shapes above are checked against the LIKE needles the query
// actually binds: unwrap each '%…%' param and match it in PHP.
$admits = static function (string $content) use ($bound): bool {
    foreach ($bound as $param) {
        if (!is_string($param) || !str_starts_with($param, '%') || !str_ends_with($param, '%')) {
            continue;
        }

        $needle = stripslashes(substr($param, 1, -1));

        if ($needle !== '' && str_contains($content, $needle)) {
            return true;
        }
    }

    return false;
};

check('query admits a block attribute outside data', $admits('<!-- wp:acme/hero {"imageId":123} /-->'), true);
check('query admits a block id nested under a non-data key', $admits('<!-- wp:acme/slider {"settings":{"slides":[{"image":123}]},"loop":true} /-->'), true);
check('query admits an attachment_id query arg', $admits('<a href="https://example.test/?attachment_id=123">PDF</a>'), true);
check('query admits the editor rel marker', $admits('<a href="/brochure/" rel="attachment wp-att-123">Brochure</a>'), true);
check('query admits a class-only wp-att marker', $admits('<a class="wp-att-123" href="/brochure/">Brochure</a>'), true);
check('query admits a data-id attribute', $admits('<figure data-id="123"></figure>'), true);
check('query admits a single-quoted data-id attribute', $admits("<figure data-id='123'></figure>"), true);
check('query admits an unquoted data-id attribute', $admits('<figure data-id=123></figure>'), true);

// The pretty-printed forms, which no needle admitted before: hand-edited
// content, a block written programmatically, or a prettified export. Every one
// of these verified as a reference above while never being fetched at all.
check('query admits one space after the colon', $admits('<!-- wp:acme/hero {"imageId": 123} /-->'), true);
check('query admits several spaces after the colon', $admits('<!-- wp:acme/hero {"imageId":    123} /-->'), true);
check('query admits a newline and indent after the colon', $admits("<!-- wp:acme/hero {\n    \"imageId\":\n        123\n} /-->"), true);
check('query admits a pretty-printed array element', $admits("<!-- wp:acme/gallery {\n    \"ids\": [\n        456,\n        123\n    ]\n} /-->"), true);
check('query admits a tab-indented array element', $admits("<!-- wp:acme/gallery {\n\t\"ids\": [\n\t\t123\n\t]\n} /-->"), true);
check('query admits a pretty-printed core block id', $admits('<!-- wp:image {"id": 123,"sizeSlug":"large"} -->'), true);

// The cost of that, stated as a pair rather than left implicit: the whitespace
// needles carry no right-hand delimiter, so the prefilter deliberately fetches
// neighbours of the id and verify() is what throws them away. Over-fetching
// costs one rejected row; the under-fetching it replaces cost a used file.
check('query admits a spaced longer id, having no right boundary', $admits('<!-- wp:acme/hero {"imageId": 1234} /-->'), true);
check('the verifier rejects the spaced longer id the query admitted', $match('<!-- wp:acme/hero {"imageId": 1234} /-->'), null);
check('query admits a spaced id in prose, having no right boundary', $admits('<p>Order 123 shipped.</p>'), true);
check('the verifier rejects the prose the query admitted', $match('<p>Order 123 shipped.</p>'), null);

// End to end: the row the query now fetches becomes a reference. Both halves
// had to change hands for this — the needle admits it, the verifier confirms it.
$GLOBALS['wpdb']->rows = [
    (object) [
        'ID' => '9',
        'post_parent' => '0',
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_content' => '<!-- wp:acme/hero {"imageId": 123} /-->',
        'post_excerpt' => '',
    ],
];

$prettyRefs = $detector->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('a pretty-printed block attribute yields one reference', count($prettyRefs), 1);
check('the pretty-printed reference is a block field match', $prettyRefs[0]->match, 'acf-block');
check('the pretty-printed reference points at the post', $prettyRefs[0]->objectId, 9);
check('the pretty-printed reference is confirmed', $prettyRefs[0]->confidence, Reference::CONFIRMED);

// -------------------------------------------------- term descriptions
//
// A category, tag or product-category description is free content and carries
// images like any other: an <img> pasted into one is the routine case, and it
// used to scan unused because nothing read that column at all.

$termVerify = new ReflectionMethod(TermDescriptionDetector::class, 'verify');
$termVerify->setAccessible(true);
$termDetector = new TermDescriptionDetector();
$termMatch = static fn(string $description): ?string => $termVerify->invoke($termDetector, $description, $ctx);

check('image in a term description', $termMatch('<p>Our range</p><img src="/wp-content/uploads/2026/07/hero.jpg" alt="">'), 'url');
check('resized image in a term description', $termMatch('<img src="https://example.test/wp-content/uploads/2026/07/hero-300x200.jpg">'), 'url');
check('unrelated image in a term description', $termMatch('<img src="/wp-content/uploads/2026/07/other.jpg">'), null);
check('bare id as the whole description', $termMatch('123'), 'exact');
check('id in a quoted attribute', $termMatch('[gallery ids="123"]'), 'id-attribute');
check('id in a comma list', $termMatch('[gallery ids="4,123,9"]'), 'comma-list');
check('id in a json description', $termMatch('{"id":123}'), 'block-id');
check('id under an arbitrary json key', $termMatch('{"hero":{"image":123}}'), 'serialized');
check('id in a serialized description', $termMatch('a:1:{s:5:"image";i:123;}'), 'serialized');
check('id as a substring of a longer number', $termMatch('[gallery ids="1234"]'), null);
check('id as a prefixed number', $termMatch('[gallery ids="9123"]'), null);
check('id inside a longer list member', $termMatch('[gallery ids="4,91230,9"]'), null);
check('longer id in a json description', $termMatch('{"hero":{"image":1234}}'), null);
check('empty description', $termMatch(''), null);
check('description with no reference', $termMatch('<p>Everything for the workshop.</p>'), null);

// The reference a hit emits: the Evidence report and the attachment meta box
// both resolve a term link from objectType + objectId, so those two fields are
// the whole of what makes a finding traceable back to a screen.
$GLOBALS['wpdb']->rows = [
    (object) ['term_id' => '42', 'description' => '<img src="/wp-content/uploads/2026/07/hero.jpg">'],
    (object) ['term_id' => '43', 'description' => '[gallery ids="123"]'],
    (object) ['term_id' => '44', 'description' => '<p>Nothing here — 1234.</p>'],
];

$termRefs = $termDetector->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('one reference per matching term', count($termRefs), 2);
check('reference points at a term', $termRefs[0]->objectType, 'term');
check('reference carries the term id as an int', $termRefs[0]->objectId, 42);
check('reference names the description', $termRefs[0]->detail, 'description');
check('a file URL in a description is confirmed', $termRefs[0]->confidence, Reference::CONFIRMED);
check('a URL match is labelled as one', $termRefs[0]->match, 'url');
check('an id in a description is possible, not confirmed', $termRefs[1]->confidence, Reference::POSSIBLE);
check('a possible reference still counts as used', $termRefs[1]->countsAsUsed(), true);
check('the detector names itself', $termRefs[0]->detector, 'term-description');

$termSql = $GLOBALS['wpdb']->lastSql;
$termBound = $GLOBALS['wpdb']->lastParams;

check('term description query placeholders match params', substr_count($termSql, '%s') + substr_count($termSql, '%d'), count($termBound));
check('term description query reads the description column', str_contains($termSql, 'tt.description'), true);
check('term description query skips empty descriptions', str_contains($termSql, "tt.description <> ''"), true);
check('term description query binds ids and basenames', count($termBound), count($conditions) + count($names));

// Same discipline as the post_content block above: a verifier the broad pass
// never reaches does nothing, so every shape verified above is matched against
// the conditions this query actually binds — the bare-id case exactly, the
// rest as unwrapped LIKE needles.
$termAdmits = static function (string $description) use ($termBound): bool {
    foreach ($termBound as $param) {
        if (!is_string($param)) {
            continue;
        }

        if ($param === $description) {
            return true; // the bare '= %s' condition
        }

        if (!str_starts_with($param, '%') || !str_ends_with($param, '%')) {
            continue;
        }

        $needle = stripslashes(substr($param, 1, -1));

        if ($needle !== '' && str_contains($description, $needle)) {
            return true;
        }
    }

    return false;
};

check('query admits an image in a description', $termAdmits('<img src="/wp-content/uploads/2026/07/hero.jpg">'), true);
check('query admits a bare id', $termAdmits('123'), true);
check('query admits a quoted id', $termAdmits('[gallery ids="123"]'), true);
check('query admits an id in a comma list', $termAdmits('[gallery ids="4,123,9"]'), true);
check('query admits a json id', $termAdmits('{"id":123}'), true);
check('query admits an id under an arbitrary json key', $termAdmits('{"hero":{"image":123}}'), true);
check('query admits a serialized id', $termAdmits('a:1:{s:5:"image";i:123;}'), true);
check('query does not fetch an unrelated description', $termAdmits('<p>Everything for the workshop.</p>'), false);

// ------------------------------- quoted ids in stored values (termmeta,
//                                 usermeta, commentmeta, postmeta, options)
//
// idConditions() binds a '%"123"%' condition on every column it is handed, and
// only TermDescriptionDetector could answer it. The five detectors below fell
// through to structureContains(decodeStored(…)), which speaks for a value that
// decodes and says nothing about one that does not: a [gallery ids="123"] in a
// text widget, an ACF wysiwyg field or a data-id="123" in stored markup was
// fetched by the broad pass and then discarded, so the file scanned unused.
//
// Each gets the positive, the two substring negatives, and a value that does
// decode — which must keep its more specific verdict, because the new branch
// sits after the decode-based ones, never in front of them. Then the same
// reachability discipline as the sections above: a shape the chain accepts is
// matched against the conditions its own query actually binds.

$quotedShortcode = '[gallery ids="123"]';
$quotedMarkup = '<p>Team</p><img data-id="123">';
$longerId = '[gallery ids="1234"]';
$prefixedId = '[gallery ids="9123"]';

/** True when a query's bound params admit $value — the fetch the SQL would do. */
$admits = static function (array $bound, string $value): bool {
    foreach ($bound as $param) {
        if (!is_string($param)) {
            continue;
        }

        if ($param === $value) {
            return true; // the bare '= %s' condition
        }

        if (!str_starts_with($param, '%') || !str_ends_with($param, '%')) {
            continue;
        }

        $needle = stripslashes(substr($param, 1, -1));

        if ($needle !== '' && str_contains($value, $needle)) {
            return true;
        }
    }

    return false;
};

// --- termmeta

$GLOBALS['wpdb']->rows = [
    (object) ['term_id' => '42', 'meta_key' => 'blurb', 'meta_value' => $quotedShortcode],
    (object) ['term_id' => '43', 'meta_key' => 'blurb', 'meta_value' => $longerId],
    (object) ['term_id' => '44', 'meta_key' => 'blurb', 'meta_value' => $prefixedId],
    (object) ['term_id' => '45', 'meta_key' => 'blurb', 'meta_value' => '{"id":123}'],
];

$termMetaRefs = (new TermMetaDetector())->find($ctx);
$termMetaBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a quoted id in termmeta resolves, a longer or prefixed one does not', count($termMetaRefs), 2);
check('the termmeta quoted id is labelled as markup', $termMetaRefs[0]->match, 'id-attribute');
check('the termmeta quoted id is possible, not confirmed', $termMetaRefs[0]->confidence, Reference::POSSIBLE);
check('a possible termmeta reference still counts as used', $termMetaRefs[0]->countsAsUsed(), true);
check('the termmeta reference points at its term', $termMetaRefs[0]->objectId, 42);
check('a termmeta value that decodes keeps its block-id verdict', $termMetaRefs[1]->match, 'block-id');
check('the termmeta query admits a quoted id', $admits($termMetaBound, $quotedShortcode), true);

// --- usermeta

$GLOBALS['wpdb']->rows = [
    (object) ['user_id' => '5', 'meta_key' => 'profile_html', 'meta_value' => $quotedMarkup],
    (object) ['user_id' => '6', 'meta_key' => 'profile_html', 'meta_value' => $longerId],
    (object) ['user_id' => '7', 'meta_key' => 'profile_html', 'meta_value' => $prefixedId],
    (object) ['user_id' => '8', 'meta_key' => 'profile_html', 'meta_value' => '{"hero":{"image":"123"}}'],
];

$userMetaRefs = (new UserMetaDetector())->find($ctx);
$userMetaBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a quoted id in usermeta resolves, a longer or prefixed one does not', count($userMetaRefs), 2);
check('the usermeta quoted id is labelled as markup', $userMetaRefs[0]->match, 'id-attribute');
check('the usermeta reference points at its user', $userMetaRefs[0]->objectId, 5);
check('a usermeta value that decodes keeps its serialized verdict', $userMetaRefs[1]->match, 'serialized');
check('the usermeta query admits a quoted id in markup', $admits($userMetaBound, $quotedMarkup), true);

// --- commentmeta, and comment_content deliberately left alone

$commentDetector = new CommentDetector();
$inMeta = new ReflectionMethod(CommentDetector::class, 'inMeta');
$inMeta->setAccessible(true);

$GLOBALS['wpdb']->rows = [
    (object) ['comment_id' => '11', 'meta_key' => 'review_media', 'meta_value' => $quotedShortcode, 'comment_approved' => '1'],
    (object) ['comment_id' => '12', 'meta_key' => 'review_media', 'meta_value' => $longerId, 'comment_approved' => '1'],
    (object) ['comment_id' => '13', 'meta_key' => 'review_media', 'meta_value' => $prefixedId, 'comment_approved' => '1'],
    (object) ['comment_id' => '14', 'meta_key' => 'review_media', 'meta_value' => 'a:1:{s:5:"image";i:123;}', 'comment_approved' => '1'],
];

$commentMetaRefs = $inMeta->invoke($commentDetector, $ctx);
$commentMetaBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a quoted id in commentmeta resolves, a longer or prefixed one does not', count($commentMetaRefs), 2);
check('the commentmeta quoted id is labelled as markup', $commentMetaRefs[0]->match, 'id-attribute');
check('the commentmeta reference points at its comment', $commentMetaRefs[0]->objectId, 11);
check('a commentmeta value that decodes keeps its serialized verdict', $commentMetaRefs[1]->match, 'serialized');
check('the commentmeta query admits a quoted id', $admits($commentMetaBound, $quotedShortcode), true);

// --- comment_content, which now binds three markup conditions of its own
//
// It used to bind basenames alone, on the reasoning that only a URL can
// reference a file in a comment. A link to the file's own attachment page is
// the counter-example, and the ordinary one for a document: a support reply
// points at the PDF instead of embedding it, and that comment kept nothing
// alive. The editor's image class is the other: a comment carrying markup
// pasted out of the editor carries `class="wp-image-123"` with it, which is not
// a shape a commenter types but the one the editor writes. The id shapes stay
// out — a bare number in prose is noise, not a reference — so the quoted-id
// condition is still deliberately absent here, and that remains asserted rather
// than assumed, together with the bare integer it would drag in.
$inContent = new ReflectionMethod(CommentDetector::class, 'inContent');
$inContent->setAccessible(true);

$commentLinkQuery = '<p>See <a href="https://example.test/?attachment_id=123">the brochure</a>.</p>';
$commentLinkRel = '<p><a href="/brochure/" rel="attachment wp-att-123">Brochure</a></p>';
$commentLinkLonger = '<p><a href="/?attachment_id=1234">Another file</a></p>';
$commentLinkPrefixed = '<p><a rel="attachment wp-att-9123">Another file</a></p>';
$commentImageClass = '<p>Like this one:</p><figure><img class="alignnone size-large wp-image-123" src="/other.png"></figure>';
$commentImageLonger = '<figure><img class="wp-image-1234" src="/other.png"></figure>';
$commentBareId = '<p>Order 123 arrived, and 1230 is still open.</p>';
$commentGalleryShortcode = '<p>[gallery ids="123,456"]</p>';

$GLOBALS['wpdb']->rows = [
    (object) ['comment_ID' => '21', 'comment_approved' => '1', 'comment_content' => $commentLinkQuery],
    (object) ['comment_ID' => '22', 'comment_approved' => '1', 'comment_content' => $commentLinkRel],
    (object) ['comment_ID' => '23', 'comment_approved' => '1', 'comment_content' => $commentLinkLonger],
    (object) ['comment_ID' => '24', 'comment_approved' => '1', 'comment_content' => $commentLinkPrefixed],
    (object) ['comment_ID' => '25', 'comment_approved' => 'trash', 'comment_content' => $commentLinkQuery],
    (object) ['comment_ID' => '26', 'comment_approved' => '1', 'comment_content' => '<img src="/wp-content/uploads/2026/07/hero.jpg">'],
    (object) ['comment_ID' => '27', 'comment_approved' => '1', 'comment_content' => $commentImageClass],
    (object) ['comment_ID' => '28', 'comment_approved' => '1', 'comment_content' => $commentImageLonger],
    (object) ['comment_ID' => '29', 'comment_approved' => '1', 'comment_content' => $commentBareId],
];

$commentContentRefs = $inContent->invoke($commentDetector, $ctx);
$contentBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];
$commentContentIds = array_map(static fn($r) => $r->objectId, $commentContentRefs);

check('both link forms and the image class resolve, no near-miss does', $commentContentIds, [21, 22, 25, 26, 27]);
check('a linked attachment page in a comment is labelled as one', $commentContentRefs[0]->match, 'attachment-page');
check('the rel marker resolves to the same label', $commentContentRefs[1]->match, 'attachment-page');
check('a linked attachment page in a comment is confirmed', $commentContentRefs[0]->confidence, Reference::CONFIRMED);
check('a link in a comment counts as used', $commentContentRefs[0]->countsAsUsed(), true);
check('the comment reference names the body column', $commentContentRefs[0]->detail, 'comment_content');
check('the comment reference points at its comment', $commentContentRefs[0]->objectId, 21);
check('a link in a trashed comment stays possible', $commentContentRefs[2]->confidence, Reference::POSSIBLE);
check('a file URL in a comment is still a URL match', $commentContentRefs[3]->match, 'url');
check('comment_content binds basenames, the two link conditions and the image class', count($contentBound), count($names) + 3);
check('comment_content admits both link forms', [$admits($contentBound, $commentLinkQuery), $admits($contentBound, $commentLinkRel)], [true, true]);
check('comment_content admits the editor image class', $admits($contentBound, $commentImageClass), true);
check('comment_content still never fetches a quoted id', $admits($contentBound, $quotedShortcode), false);

// The image class in a comment body, which is what a paste out of the editor
// produces — the id form Review A named, and the only one added here.
check('an image class in a comment is labelled as content', $commentContentRefs[4]->match, 'wp-image-class');
check('an image class in a comment is confirmed', $commentContentRefs[4]->confidence, Reference::CONFIRMED);
check('an image class in a comment counts as used', $commentContentRefs[4]->countsAsUsed(), true);
check('the image-class reference points at its comment', $commentContentRefs[4]->objectId, 27);
check('comment_content admits a longer id in the class, having no right boundary', $admits($contentBound, $commentImageLonger), true);
check('the verifier rejects the longer id in the class', in_array(28, $commentContentIds, true), false);

// The line this task must not cross: a bare integer in prose is not a
// reference. No condition on this column admits one, so the row is never
// fetched — and were it fetched anyway (a basename that happens to appear in
// the same comment), no verifier here answers a naked number either.
check('comment_content never fetches a bare id in prose', $admits($contentBound, $commentBareId), false);
check('a bare id in prose resolves to nothing even when handed over', in_array(29, $commentContentIds, true), false);

// [gallery ids="…"] in a comment stays out, and this is the assertion behind
// that decision rather than a silence: every needle that would fetch it is a
// quoted-id shape, so the existing predicates cannot reach the row and catching
// it would mean new SQL on this column.
check('comment_content does not fetch a gallery shortcode', $admits($contentBound, $commentGalleryShortcode), false);

// The boundaries, as the pair each needle earns: 'attachment_id=' has no
// right-hand delimiter, so the longer id IS fetched and the verifier is what
// throws it away; a prefixed id shares no left anchor, so it is never fetched
// at all. Both halves asserted, so a later widening cannot drop one of them.
check('comment_content admits a longer id, having no right boundary', $admits($contentBound, $commentLinkLonger), true);
check('the verifier rejects the longer id the query admitted', in_array(23, $commentContentIds, true), false);
check('comment_content does not fetch a prefixed id at all', $admits($contentBound, $commentLinkPrefixed), false);
check('the verifier returns nothing for the prefixed id either', in_array(24, $commentContentIds, true), false);

// --- postmeta

// The fixture rows carry every column the SELECT names, post_type and
// post_parent included — the query joins the posts table to read them, and a
// row that omits one only exercises the fallback.
$GLOBALS['wpdb']->rows = [
    (object) ['post_id' => '31', 'meta_key' => 'intro_html', 'meta_value' => $quotedMarkup, 'post_type' => 'page', 'post_parent' => '0', 'post_status' => 'publish'],
    (object) ['post_id' => '32', 'meta_key' => 'intro_html', 'meta_value' => $longerId, 'post_type' => 'page', 'post_parent' => '0', 'post_status' => 'publish'],
    (object) ['post_id' => '33', 'meta_key' => 'intro_html', 'meta_value' => $prefixedId, 'post_type' => 'page', 'post_parent' => '0', 'post_status' => 'publish'],
    (object) ['post_id' => '34', 'meta_key' => 'settings', 'meta_value' => '{"hero":{"image":123}}', 'post_type' => 'page', 'post_parent' => '0', 'post_status' => 'publish'],
];

$postMetaRefs = (new PostmetaDetector())->find($ctx);
$postMetaBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a quoted id in postmeta resolves, a longer or prefixed one does not', count($postMetaRefs), 2);
check('the postmeta quoted id is labelled as markup', $postMetaRefs[0]->match, 'id-attribute');
check('the postmeta reference points at its post', $postMetaRefs[0]->objectId, 31);
check('a postmeta value that decodes keeps its serialized verdict', $postMetaRefs[1]->match, 'serialized');
check('the postmeta query admits a quoted id in markup', $admits($postMetaBound, $quotedMarkup), true);

// --- the two post-table detectors agree about autosaves
//
// They used to disagree: post_content admitted a revision whose post_name ends
// '-autosave-v1', postmeta excluded every revision outright, so an unsaved
// draft's text was searched and its custom fields were not. One row, two
// answers about whether it exists — which is the defect, independent of how
// often it bites. Both conditions now come from LikePatterns::revisionCondition()
// and both readings of the fetched row match: the reference belongs to the
// parent post (an autosave has no screen of its own) and it is `possible`
// however precisely the value verified, because the edit may never be saved.
//
// Direction chosen on a measurement, not on the reasoning: on two production
// copies one attachment is named by an autosave's field and by nothing else on
// the site, so excluding autosaves from both would have widened deletion onto a
// live draft's file. Admitting them cannot delete anything that is used.

$autosaveContent = '<img class="wp-image-123" src="/other.png">';

$GLOBALS['wpdb']->rows = [
    (object) [
        'ID' => '910', 'post_parent' => '900', 'post_type' => 'revision', 'post_status' => 'inherit',
        'post_content' => $autosaveContent, 'post_excerpt' => '',
    ],
];
$autosaveContentRefs = $detector->find($ctx);
$autosaveContentBound = $GLOBALS['wpdb']->lastParams;
$autosaveContentSql = $GLOBALS['wpdb']->lastSql;

$GLOBALS['wpdb']->rows = [
    (object) [
        'post_id' => '910', 'meta_key' => 'hero_image', 'meta_value' => '123',
        'post_type' => 'revision', 'post_parent' => '900', 'post_status' => 'inherit',
    ],
];
$autosaveMetaRefs = (new PostmetaDetector())->find($ctx);
$autosaveMetaBound = $GLOBALS['wpdb']->lastParams;
$autosaveMetaSql = $GLOBALS['wpdb']->lastSql;
$GLOBALS['wpdb']->rows = [];

// One condition, one answer: the same clause text and the same bound needle in
// both queries, and the needle is a suffix with no trailing '%', so a plain
// revision is still excluded from both.
check('both queries carry the same revision condition', [
    str_contains($autosaveContentSql, "(p.post_type <> 'revision' OR p.post_name LIKE %s)"),
    str_contains($autosaveMetaSql, "(p.post_type <> 'revision' OR p.post_name LIKE %s)"),
], [true, true]);
check('both queries bind the same autosave needle first', [$autosaveContentBound[0], $autosaveMetaBound[0]], ['%-autosave-v1', '%-autosave-v1']);
check('the autosave needle is a suffix, so a plain revision stays out', str_ends_with($autosaveMetaBound[0], '-autosave-v1'), true);

// And the same fetched autosave is read the same way by both.
$verdict = static fn(array $refs): array => $refs === []
    ? ['no reference']
    : [$refs[0]->objectType, $refs[0]->objectId, $refs[0]->match, $refs[0]->confidence, $refs[0]->countsAsUsed()];

check('one autosave, one reference, from each detector', [count($autosaveContentRefs), count($autosaveMetaRefs)], [1, 1]);
check('both detectors read the fixture autosave identically', $verdict($autosaveMetaRefs), $verdict($autosaveContentRefs));
check('the shared verdict is the parent post, possible, and used', $verdict($autosaveContentRefs), ['post', 900, 'autosave', Reference::POSSIBLE, true]);

// The meta_key survives the relabelling: 'autosave' is what the row *is*, and
// the field it sits in is still the useful half of the detail line.
check('the postmeta autosave reference still names its field', $autosaveMetaRefs[0]->detail, 'hero_image');

// --- an attachment row's own description and caption (148)
//
// WordPress stores an attachment's description in post_content and its caption
// in post_excerpt. Both are ordinary rich text that can name another file, and
// on a real library two documents were referenced from nowhere else: sibling
// import-log uploads whose description is the URL of the file. The query
// excluded post_type 'attachment' outright, so neither column was ever read
// for anybody, and both documents scanned unused.
//
// The guard that has to come with admitting them is in the SQL rather than in
// the loop: an attachment's own description very often carries its own
// filename, so a row able to reference itself would be used for ever and could
// never be cleaned up. "p.ID <> %d" is what stops that, and it is asserted
// twice below — once as the value bound to it, and once by filtering the
// candidate rows through the id the detector actually bound, so a fix that
// dropped the clause could not pass on the positive cases alone.

$attachmentDescription = 'http://example.test/wp-content/uploads/2026/07/hero-scaled.jpg';

$ownRow = (object) [
    'ID' => (string) FIXTURE_ID, 'post_parent' => '0', 'post_type' => 'attachment', 'post_status' => 'inherit',
    'post_content' => $attachmentDescription, 'post_excerpt' => '',
];
$siblingRow = (object) [
    'ID' => '820', 'post_parent' => '0', 'post_type' => 'attachment', 'post_status' => 'private',
    'post_content' => $attachmentDescription, 'post_excerpt' => '',
];
$captionRow = (object) [
    'ID' => '821', 'post_parent' => '0', 'post_type' => 'attachment', 'post_status' => 'inherit',
    'post_content' => '', 'post_excerpt' => 'Cover shot: hero-300x200.jpg',
];
$GLOBALS['wpdb']->rows = [$ownRow, $siblingRow, $captionRow];
$detector->find($ctx);
$attachmentBound = $GLOBALS['wpdb']->lastParams;
$attachmentSql = $GLOBALS['wpdb']->lastSql;
$GLOBALS['wpdb']->rows = [];

check('the post-content query no longer excludes attachment rows', str_contains($attachmentSql, "'attachment'"), false);
check('and still excludes menu items, whose content is not a reference', str_contains($attachmentSql, "p.post_type <> 'nav_menu_item'"), true);
// "A row never references itself" moved out of the statement and into find():
// the broad pass is shared across a whole sibling group, and a row excluded in
// SQL would be hidden from the siblings that legitimately name it (freshet-155).
check('the statement no longer excludes the scanned row', str_contains($attachmentSql, 'p.ID <> %d'), false);
check('the autosave needle is still what it binds first', $attachmentBound[0], '%-autosave-v1');

// Every row the WHERE leaves behind, the scanned attachment's own included —
// dropping that one is the detector's job now, so handing it back is the test.
// The menu item stays out: that exclusion is still in SQL.
$GLOBALS['wpdb']->rows = [$ownRow, $siblingRow, $captionRow];
$attachmentRefs = $detector->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('an attachment does not reference itself', array_map(static fn($r): int => $r->objectId, $attachmentRefs), [820, 821]);
check("a sibling upload's description is a reference", $attachmentRefs[0]->match, 'url');
check('and it counts as used', $attachmentRefs[0]->countsAsUsed(), true);
check('the reference points at the attachment row carrying it', [$attachmentRefs[0]->objectType, $attachmentRefs[0]->objectId], ['post', 820]);
check('a private import row is confirmed like any other post', $attachmentRefs[0]->confidence, Reference::CONFIRMED);
check('a caption naming a size is a reference too', [$attachmentRefs[1]->objectId, $attachmentRefs[1]->match], [821, 'url']);
check('the query admits a description carrying the file URL', $admits($attachmentBound, $attachmentDescription), true);
check('and a caption carrying a size name', $admits($attachmentBound, (string) $captionRow->post_excerpt), true);

// --- options: all three branches, because all three are behind one query

$widgetValue = serialize([2 => ['title' => '', 'text' => $quotedShortcode]]);
$themeModValue = serialize(['footer_html' => $quotedMarkup]);
$blobValue = serialize(['intro' => $quotedShortcode]);

$GLOBALS['wpdb']->rows = [
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $quotedMarkup],
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $longerId],
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $prefixedId],
    (object) ['option_name' => 'widget_text', 'option_value' => $widgetValue],
    (object) ['option_name' => 'theme_mods_freshet', 'option_value' => $themeModValue],
    (object) ['option_name' => 'my_plugin_settings', 'option_value' => $blobValue],
    (object) ['option_name' => 'my_plugin_settings', 'option_value' => '{"hero":{"image":123}}'],
];

$optionRefs = (new OptionsDetector())->find($ctx);
$optionBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every options branch answers a quoted id, and neither near-miss does', count($optionRefs), 5);
check('a quoted id in a plain option is labelled as markup', $optionRefs[0]->match, 'id-attribute');
check('a quoted id in a plain option is possible, not confirmed', $optionRefs[0]->confidence, Reference::POSSIBLE);
check('a shortcode in a text widget resolves', $optionRefs[1]->match, 'id-attribute');
check('a widget hit is still reported as an option', $optionRefs[1]->objectType, 'option');
check('markup in a theme mod resolves', $optionRefs[2]->match, 'id-attribute');
check('a theme-mod hit keeps its object type', $optionRefs[2]->objectType, 'theme_mod');
check('a shortcode inside a serialized blob resolves', $optionRefs[3]->match, 'id-attribute');
check('an option that decodes keeps its serialized verdict', $optionRefs[4]->match, 'serialized');
check('the options query admits a quoted id in markup', $admits($optionBound, $quotedMarkup), true);
check('the options query admits a widget carrying one', $admits($optionBound, $widgetValue), true);
check('the options query admits a theme mod carrying one', $admits($optionBound, $themeModValue), true);
check('the options query does not fetch a longer id', $admits($optionBound, $longerId), false);
check('the options query does not fetch a prefixed id', $admits($optionBound, $prefixedId), false);

// ------------------------------ pretty-printed JSON numbers in stored values
//                                (termmeta, usermeta, commentmeta, postmeta,
//                                options)
//
// Every condition idConditions() bound put its delimiter hard against the
// digits — ':123,', ':123}', '"id":123' — so {"imageId": 123} with a single
// space matched none of them and the row was never fetched at all. The
// verifier half was never the problem: decodeStored() throws the spacing away
// before structureContains() ever sees the number, so it answered correctly
// the moment it was handed the row, and nothing handed it one. The same gap
// closed for post_content above; these are the five columns behind the shared
// helper, which is paid for by six detectors on every attachment.
//
// Each detector gets the three shapes that matter — one space, several spaces,
// a newline plus indent — and the array-element position, driven through the
// real find() so "fetched" and "resolves as a reference" are one assertion
// rather than two. The SQL half is then checked against the params that same
// call bound. The near-misses sit in the same fixture set with ids of their
// own, so a row that is fetched and then thrown away is asserted as an
// absence, not left implicit in a count.

$prettyOne = '{"imageId": 123}';
$prettySpaces = '{"imageId":     123}';
$prettyIndented = "{\n    \"imageId\":\n        123\n}";
$prettyArray = "{\n    \"ids\": [\n        456,\n        123\n    ]\n}";
$prettyTabbed = "{\n\t\"ids\": [\n\t\t123\n\t]\n}";
$prettyLonger = '{"imageId": 1234}';
$prettyProse = 'Delivered 123 boxes on Tuesday.';

// The control for every row that follows, and the reason a zero here would be
// a real zero: every condition that predates this task fetches none of these
// shapes, and the three whitespace-anchored ones — indices 14-16, named rather
// than counted from the end, because the list has grown since — are the whole
// of the difference. Half of this pair failing means the widening has been
// dropped; the other half failing means it never did anything.
[, $prettyIdParams] = LikePatterns::idConditions('pm.meta_value', $id);
$prettyBefore = array_slice($prettyIdParams, 0, 14);
$prettyShapes = [$prettyOne, $prettySpaces, $prettyIndented, $prettyArray, $prettyTabbed];

check('the conditions that predate this fetch none of the pretty-printed shapes', array_map(static fn(string $v): bool => $admits($prettyBefore, $v), $prettyShapes), [false, false, false, false, false]);
check('the three appended conditions fetch every one of them', array_map(static fn(string $v): bool => $admits($prettyIdParams, $v), $prettyShapes), [true, true, true, true, true]);

/** The five shapes, each on its own object id, then the two near-misses. */
$prettyRows = [
    71 => $prettyOne,
    72 => $prettySpaces,
    73 => $prettyIndented,
    74 => $prettyArray,
    75 => $prettyTabbed,
    78 => $prettyLonger,
    79 => $prettyProse,
];

// --- termmeta

$GLOBALS['wpdb']->rows = [];
foreach ($prettyRows as $termId => $value) {
    $GLOBALS['wpdb']->rows[] = (object) ['term_id' => (string) $termId, 'meta_key' => 'settings', 'meta_value' => $value];
}

$prettyTermRefs = (new TermMetaDetector())->find($ctx);
$prettyTermBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every pretty-printed shape in termmeta resolves, neither near-miss does', array_map(static fn($r) => $r->objectId, $prettyTermRefs), [71, 72, 73, 74, 75]);
check('a pretty-printed termmeta value is resolved in the structure', $prettyTermRefs[0]->match, 'serialized');
check('a pretty-printed termmeta reference still counts as used', $prettyTermRefs[0]->countsAsUsed(), true);
check('the termmeta query admits one space after the colon', $admits($prettyTermBound, $prettyOne), true);
check('the termmeta query admits several spaces after the colon', $admits($prettyTermBound, $prettySpaces), true);
check('the termmeta query admits a newline and indent', $admits($prettyTermBound, $prettyIndented), true);
check('the termmeta query admits an indented array element', $admits($prettyTermBound, $prettyArray), true);
check('the termmeta query admits a tab-indented array element', $admits($prettyTermBound, $prettyTabbed), true);

// --- usermeta

$GLOBALS['wpdb']->rows = [];
foreach ($prettyRows as $userId => $value) {
    $GLOBALS['wpdb']->rows[] = (object) ['user_id' => (string) $userId, 'meta_key' => 'settings', 'meta_value' => $value];
}

$prettyUserRefs = (new UserMetaDetector())->find($ctx);
$prettyUserBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every pretty-printed shape in usermeta resolves, neither near-miss does', array_map(static fn($r) => $r->objectId, $prettyUserRefs), [71, 72, 73, 74, 75]);
check('a pretty-printed usermeta value is resolved in the structure', $prettyUserRefs[0]->match, 'serialized');
check('the usermeta query admits one space after the colon', $admits($prettyUserBound, $prettyOne), true);
check('the usermeta query admits several spaces after the colon', $admits($prettyUserBound, $prettySpaces), true);
check('the usermeta query admits a newline and indent', $admits($prettyUserBound, $prettyIndented), true);
check('the usermeta query admits an indented array element', $admits($prettyUserBound, $prettyArray), true);

// --- commentmeta

$GLOBALS['wpdb']->rows = [];
foreach ($prettyRows as $commentId => $value) {
    $GLOBALS['wpdb']->rows[] = (object) ['comment_id' => (string) $commentId, 'meta_key' => 'settings', 'meta_value' => $value, 'comment_approved' => '1'];
}

$prettyCommentRefs = $inMeta->invoke($commentDetector, $ctx);
$prettyCommentBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every pretty-printed shape in commentmeta resolves, neither near-miss does', array_map(static fn($r) => $r->objectId, $prettyCommentRefs), [71, 72, 73, 74, 75]);
check('a pretty-printed commentmeta value is resolved in the structure', $prettyCommentRefs[0]->match, 'serialized');
check('the commentmeta query admits one space after the colon', $admits($prettyCommentBound, $prettyOne), true);
check('the commentmeta query admits several spaces after the colon', $admits($prettyCommentBound, $prettySpaces), true);
check('the commentmeta query admits a newline and indent', $admits($prettyCommentBound, $prettyIndented), true);
check('the commentmeta query admits an indented array element', $admits($prettyCommentBound, $prettyArray), true);

// --- postmeta

$GLOBALS['wpdb']->rows = [];
foreach ($prettyRows as $postId => $value) {
    $GLOBALS['wpdb']->rows[] = (object) ['post_id' => (string) $postId, 'meta_key' => 'settings', 'meta_value' => $value, 'post_status' => 'publish'];
}

$prettyPostRefs = (new PostmetaDetector())->find($ctx);
$prettyPostBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every pretty-printed shape in postmeta resolves, neither near-miss does', array_map(static fn($r) => $r->objectId, $prettyPostRefs), [71, 72, 73, 74, 75]);
check('a pretty-printed postmeta value is resolved in the structure', $prettyPostRefs[0]->match, 'serialized');
check('the postmeta query admits one space after the colon', $admits($prettyPostBound, $prettyOne), true);
check('the postmeta query admits several spaces after the colon', $admits($prettyPostBound, $prettySpaces), true);
check('the postmeta query admits a newline and indent', $admits($prettyPostBound, $prettyIndented), true);
check('the postmeta query admits an indented array element', $admits($prettyPostBound, $prettyArray), true);
check('the postmeta query admits a tab-indented array element', $admits($prettyPostBound, $prettyTabbed), true);

// --- options

$GLOBALS['wpdb']->rows = [];
foreach ($prettyRows as $value) {
    $GLOBALS['wpdb']->rows[] = (object) ['option_name' => 'my_plugin_settings', 'option_value' => $value];
}

$prettyOptionRefs = (new OptionsDetector())->find($ctx);
$prettyOptionBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every pretty-printed option resolves, neither near-miss does', count($prettyOptionRefs), 5);
check('a pretty-printed option is resolved in the structure', $prettyOptionRefs[0]->match, 'serialized');
check('the options query admits one space after the colon', $admits($prettyOptionBound, $prettyOne), true);
check('the options query admits several spaces after the colon', $admits($prettyOptionBound, $prettySpaces), true);
check('the options query admits a newline and indent', $admits($prettyOptionBound, $prettyIndented), true);
check('the options query admits an indented array element', $admits($prettyOptionBound, $prettyArray), true);
check('the options query admits a tab-indented array element', $admits($prettyOptionBound, $prettyTabbed), true);

// The cost of a whitespace-anchored needle, stated as pairs rather than left
// implicit: it carries no right-hand delimiter, so the prefilter deliberately
// fetches the id's longer neighbours and any prose that happens to name the
// number, and verification is what throws them away. Over-fetching costs a
// rejected row; the under-fetching it replaces cost a used file. A later
// widening cannot drop half of a pair without failing here.
check('the query admits a spaced longer id, having no right boundary', $admits($prettyPostBound, $prettyLonger), true);
check('the verifier rejects the spaced longer id the query admitted', in_array(78, array_map(static fn($r) => $r->objectId, $prettyPostRefs), true), false);
check('the query admits a spaced id in prose, having no right boundary', $admits($prettyPostBound, $prettyProse), true);
check('the verifier rejects the prose the query admitted', in_array(79, array_map(static fn($r) => $r->objectId, $prettyPostRefs), true), false);
check('the options query admits a spaced longer id too', $admits($prettyOptionBound, $prettyLonger), true);
check('the options verifier rejects both near-misses it fetched', count($prettyOptionRefs), 5);

// ------------------------------ compact JSON arrays in stored values
//                                (termmeta, usermeta, commentmeta, postmeta,
//                                options, term descriptions)
//
// wp_json_encode() writes an id list with no space anywhere: [483],
// {"gallery":[483]}, [123,456]. Every condition on this helper wanted a
// delimiter a bracket terminates — the number-value ones ':123,' / ':123}',
// the comma-list ones a column that starts '123,', ends ',123' or carries
// ',123,' — so the compact form was never fetched at all, and the verifier,
// which decodes the array and compares the int, was never handed the row.
// That is the "JSON under arbitrary keys" mechanism the product is sold on
// failing on the commonest spelling of it.
//
// The three needles are the ones PostContentDetector already carries, so one
// product does not spell the same shape two ways. Both ends are delimiters, so
// unlike the whitespace trio above these over-fetch nothing: [1234] is not
// admitted for 123 and [12,34] is not admitted for 1234, which is asserted
// below as a fetch that does not happen rather than a rejection that does.

$compactSole = '[123]';
$compactKeyed = '{"gallery":[123]}';
$compactPair = '[123,456]';
$compactSpaced = '[123, 456]';
$compactKey = '{"123":{"src":"/x.jpg"}}';
$compactSerializedKey = 'a:1:{s:3:"123";a:1:{s:3:"src";s:6:"/x.jpg";}}';
$compactLonger = '[1234]';
$compactSplit = '[12,34]';

$ctx456 = AttachmentContext::forAttachment(456);
$ctx1234 = AttachmentContext::forAttachment(1234);

// The control, in the same shape as the pretty-printed one above: the fourteen
// conditions that predate this task — the eleven, plus the whitespace trio at
// 14-16 — fetch none of the compact shapes, and the three appended for them are
// the whole of the difference.
[, $compactIdParams] = LikePatterns::idConditions('pm.meta_value', $id);
$compactBefore = array_merge(array_slice($compactIdParams, 0, 11), array_slice($compactIdParams, 14, 3));
$compactShapes = [$compactSole, $compactKeyed, $compactPair, $compactSpaced];

check('the conditions that predate this fetch no compact array member', array_map(static fn(string $v): bool => $admits($compactBefore, $v), $compactShapes), [false, false, false, false]);
check('the three appended conditions fetch every compact shape', array_map(static fn(string $v): bool => $admits($compactIdParams, $v), $compactShapes), [true, true, true, true]);

// The spaced pair's second member was already fetched before this task, by the
// whitespace needle freshet-130 added. Asserted so a later change to either
// widening cannot silently drop it.
[, $compact456Params] = LikePatterns::idConditions('pm.meta_value', 456);
$compact456Before = array_merge(array_slice($compact456Params, 0, 11), array_slice($compact456Params, 14, 3));
check('the spaced pair second member was already fetched', $admits($compact456Before, $compactSpaced), true);
check('the compact pair second member is fetched only now', [$admits($compact456Before, $compactPair), $admits($compact456Params, $compactPair)], [false, true]);

/** The four compact shapes, then the two id-in-key shapes, then the two near-misses. */
$compactRows = [
    81 => $compactSole,
    82 => $compactKeyed,
    83 => $compactPair,
    84 => $compactSpaced,
    85 => $compactKey,
    86 => $compactSerializedKey,
    88 => $compactLonger,
    89 => $compactSplit,
];

/** Drives one detector over $compactRows and returns [objectIds, boundParams]. */
$runCompact = static function (callable $rows, callable $run) use ($compactRows): array {
    $GLOBALS['wpdb']->rows = [];
    foreach ($compactRows as $objectId => $value) {
        $GLOBALS['wpdb']->rows[] = $rows($objectId, $value);
    }

    $refs = $run();
    $bound = $GLOBALS['wpdb']->lastParams;
    $GLOBALS['wpdb']->rows = [];

    return [array_map(static fn($r) => $r->objectId, $refs), $bound, $refs];
};

// --- termmeta

[$compactTermIds, $compactTermBound, $compactTermRefs] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['term_id' => (string) $oid, 'meta_key' => 'settings', 'meta_value' => $v],
    static fn(): array => (new TermMetaDetector())->find($ctx)
);

check('every compact and keyed shape in termmeta resolves, neither near-miss does', $compactTermIds, [81, 82, 83, 84, 85, 86]);
check('a compact array in termmeta is resolved in the structure', $compactTermRefs[0]->match, 'serialized');
check('a compact array reference in termmeta still counts as used', $compactTermRefs[0]->countsAsUsed(), true);
check('the termmeta query admits a sole compact member', $admits($compactTermBound, $compactSole), true);
check('the termmeta query admits a compact member under a key', $admits($compactTermBound, $compactKeyed), true);
check('the termmeta query admits the compact pair', $admits($compactTermBound, $compactPair), true);
check('the termmeta query admits the spaced pair', $admits($compactTermBound, $compactSpaced), true);

// --- usermeta

[$compactUserIds, $compactUserBound] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['user_id' => (string) $oid, 'meta_key' => 'settings', 'meta_value' => $v],
    static fn(): array => (new UserMetaDetector())->find($ctx)
);

check('every compact and keyed shape in usermeta resolves, neither near-miss does', $compactUserIds, [81, 82, 83, 84, 85, 86]);
check('the usermeta query admits a sole compact member', $admits($compactUserBound, $compactSole), true);
check('the usermeta query admits a compact member under a key', $admits($compactUserBound, $compactKeyed), true);
check('the usermeta query admits the compact pair', $admits($compactUserBound, $compactPair), true);

// --- commentmeta

[$compactCommentIds, $compactCommentBound] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['comment_id' => (string) $oid, 'meta_key' => 'settings', 'meta_value' => $v, 'comment_approved' => '1'],
    static fn(): array => $inMeta->invoke($commentDetector, $ctx)
);

check('every compact and keyed shape in commentmeta resolves, neither near-miss does', $compactCommentIds, [81, 82, 83, 84, 85, 86]);
check('the commentmeta query admits a sole compact member', $admits($compactCommentBound, $compactSole), true);
check('the commentmeta query admits a compact member under a key', $admits($compactCommentBound, $compactKeyed), true);
check('the commentmeta query admits the compact pair', $admits($compactCommentBound, $compactPair), true);

// --- postmeta

[$compactPostIds, $compactPostBound, $compactPostRefs] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['post_id' => (string) $oid, 'meta_key' => 'settings', 'meta_value' => $v, 'post_status' => 'publish'],
    static fn(): array => (new PostmetaDetector())->find($ctx)
);

check('every compact and keyed shape in postmeta resolves, neither near-miss does', $compactPostIds, [81, 82, 83, 84, 85, 86]);
check('a compact array in postmeta is resolved in the structure', $compactPostRefs[0]->match, 'serialized');
check('the postmeta query admits a sole compact member', $admits($compactPostBound, $compactSole), true);
check('the postmeta query admits a compact member under a key', $admits($compactPostBound, $compactKeyed), true);
check('the postmeta query admits the compact pair', $admits($compactPostBound, $compactPair), true);
check('the postmeta query admits the spaced pair', $admits($compactPostBound, $compactSpaced), true);

// --- options

[$compactOptionIds, $compactOptionBound] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['option_name' => 'my_plugin_settings', 'option_value' => $v],
    static fn(): array => (new OptionsDetector())->find($ctx)
);

check('every compact and keyed option resolves, neither near-miss does', count($compactOptionIds), 6);
check('the options query admits a sole compact member', $admits($compactOptionBound, $compactSole), true);
check('the options query admits a compact member under a key', $admits($compactOptionBound, $compactKeyed), true);
check('the options query admits the compact pair', $admits($compactOptionBound, $compactPair), true);

// --- term descriptions, the sixth column behind the same helper

[$compactDescIds, $compactDescBound] = $runCompact(
    static fn(int $oid, string $v): object => (object) ['term_id' => (string) $oid, 'description' => $v],
    static fn(): array => $termDetector->find($ctx)
);

check('every compact and keyed description resolves, neither near-miss does', $compactDescIds, [81, 82, 83, 84, 85, 86]);
check('the term description query admits a sole compact member', $admits($compactDescBound, $compactSole), true);
check('the term description query admits a compact member under a key', $admits($compactDescBound, $compactKeyed), true);
check('the term description query admits the compact pair', $admits($compactDescBound, $compactPair), true);

// Acceptance 2: the compact pair resolves for BOTH members, not only the one
// the leading bracket anchors. Driven on a context for 456, whose only route in
// is the ',456]' needle.

$GLOBALS['wpdb']->rows = [(object) ['post_id' => '90', 'meta_key' => 'settings', 'meta_value' => $compactPair, 'post_status' => 'publish']];
$compactSecond = (new PostmetaDetector())->find($ctx456);
$compactSecondBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('the compact pair resolves for its second member too', array_map(static fn($r) => $r->objectId, $compactSecond), [90]);
check('the second member is fetched by the closing-bracket needle', $admits($compactSecondBound, $compactPair), true);

$GLOBALS['wpdb']->rows = [(object) ['post_id' => '91', 'meta_key' => 'settings', 'meta_value' => $compactSpaced, 'post_status' => 'publish']];
$compactSpacedSecond = (new PostmetaDetector())->find($ctx456);
$GLOBALS['wpdb']->rows = [];

check('the spaced pair still resolves for its second member', array_map(static fn($r) => $r->objectId, $compactSpacedSecond), [91]);

// Acceptance 3: the id-in-key shapes are pinned, not built. Both were already
// admitted by the '%"123"%' condition — the JSON key carries the same quotes a
// string value does, and a serialized key is indistinguishable from a
// serialized value — and both are answered by hasQuotedId()/hasSerializedString().
// Nothing here is new; the point is that it stops being incidental.

check('the id-in-key shapes were admitted before this task', [$admits($compactBefore, $compactKey), $admits($compactBefore, $compactSerializedKey)], [true, true]);
check('a json key that is the id is verified as a quoted id', $compactPostRefs[4]->match, 'id-attribute');
check('a serialized key that is the id is verified as a serialized string', $compactPostRefs[5]->match, 'serialized');
check('no condition was added for the key position', count(LikePatterns::idConditions('pm.meta_value', $id)[0]), 19);

// Acceptance 4: the boundaries. Both ends of these three needles are
// delimiters, so unlike the whitespace trio the near-misses are never fetched
// at all — asserted as the fetch that does not happen, and then again as the
// absence from the references, so a later widening cannot half-drop either.

check('a longer id in a compact array is not fetched', $admits($compactPostBound, $compactLonger), false);
check('the verifier returns nothing for the longer id either', in_array(88, $compactPostIds, true), false);
check('a split pair is not fetched for the joined id', $admits(LikePatterns::idConditions('pm.meta_value', 1234)[1], $compactSplit), false);

$GLOBALS['wpdb']->rows = [(object) ['post_id' => '92', 'meta_key' => 'settings', 'meta_value' => $compactSplit, 'post_status' => 'publish']];
$compactSplitRefs = (new PostmetaDetector())->find($ctx1234);
$GLOBALS['wpdb']->rows = [];

check('the verifier returns nothing for a split pair either', $compactSplitRefs, []);
check('the longer-id array is not fetched on any of the six columns', array_map(static fn(array $b): bool => $admits($b, $compactLonger), [$compactTermBound, $compactUserBound, $compactCommentBound, $compactPostBound, $compactOptionBound, $compactDescBound]), [false, false, false, false, false, false]);

// ------------------------------ attachment-page links in stored values
//                                (termmeta, usermeta, commentmeta, postmeta,
//                                options, term descriptions)
//
// The document case again, on the six columns behind the shared helper rather
// than on post_content: a wysiwyg field, a text widget, a footer theme mod or a
// category description holding <a href="?attachment_id=123">the brochure</a>.
// Every condition on this helper wants the id written as a value — quoted,
// bracketed, comma-listed, serialized — and a link writes it as a query arg or
// inside a class name, so the row was never fetched and no verifier was ever
// handed it. The verifiers themselves are the pair post_content has used since
// the document case was fixed; nothing new was written for either form.

$linkQuery = '<p>See <a href="https://example.test/?attachment_id=123">the brochure</a>.</p>';
$linkRel = '<p><a href="/brochure/" rel="attachment wp-att-123">Brochure</a></p>';
$linkClass = '<p><a class="doc wp-att-123" href="/brochure/">Brochure</a></p>';
$linkLonger = '<p><a href="/?attachment_id=1234">Another file</a></p>';
$linkPrefixed = '<p><a rel="attachment wp-att-9123">Another file</a></p>';

// The control, in the shape the two widenings before this one used: every
// condition that predates this task fetches neither link form, and the two
// appended for them are the whole of the difference. Half of this pair failing
// means the widening was dropped; the other half means it never did anything.
[, $linkIdParams] = LikePatterns::idConditions('pm.meta_value', $id);
$linkBefore = array_slice($linkIdParams, 0, 17);
$linkShapes = [$linkQuery, $linkRel, $linkClass];

check('the conditions that predate this fetch neither link form', array_map(static fn(string $v): bool => $admits($linkBefore, $v), $linkShapes), [false, false, false]);
check('the two appended conditions fetch every link form', array_map(static fn(string $v): bool => $admits($linkIdParams, $v), $linkShapes), [true, true, true]);

/** The three link forms, then the two near-misses. */
$linkRows = [
    61 => $linkQuery,
    62 => $linkRel,
    63 => $linkClass,
    68 => $linkLonger,
    69 => $linkPrefixed,
];

/** Drives one detector over $linkRows and returns [objectIds, boundParams, refs]. */
$runLink = static function (callable $rows, callable $run) use ($linkRows): array {
    $GLOBALS['wpdb']->rows = [];
    foreach ($linkRows as $objectId => $value) {
        $GLOBALS['wpdb']->rows[] = $rows($objectId, $value);
    }

    $refs = $run();
    $bound = $GLOBALS['wpdb']->lastParams;
    $GLOBALS['wpdb']->rows = [];

    return [array_map(static fn($r) => $r->objectId, $refs), $bound, $refs];
};

// --- termmeta

[$linkTermIds, $linkTermBound, $linkTermRefs] = $runLink(
    static fn(int $oid, string $v): object => (object) ['term_id' => (string) $oid, 'meta_key' => 'blurb', 'meta_value' => $v],
    static fn(): array => (new TermMetaDetector())->find($ctx)
);

check('every link form in termmeta resolves, neither near-miss does', $linkTermIds, [61, 62, 63]);
check('a linked attachment page in termmeta is labelled as one', $linkTermRefs[0]->match, 'attachment-page');
check('a linked attachment page in termmeta is possible, not confirmed', $linkTermRefs[0]->confidence, Reference::POSSIBLE);
check('a linked attachment page in termmeta still counts as used', $linkTermRefs[0]->countsAsUsed(), true);
check('the termmeta query admits the query-arg form', $admits($linkTermBound, $linkQuery), true);
check('the termmeta query admits the rel marker', $admits($linkTermBound, $linkRel), true);
check('the termmeta query admits the class marker', $admits($linkTermBound, $linkClass), true);

// --- usermeta

[$linkUserIds, $linkUserBound, $linkUserRefs] = $runLink(
    static fn(int $oid, string $v): object => (object) ['user_id' => (string) $oid, 'meta_key' => 'profile_html', 'meta_value' => $v],
    static fn(): array => (new UserMetaDetector())->find($ctx)
);

check('every link form in usermeta resolves, neither near-miss does', $linkUserIds, [61, 62, 63]);
check('a linked attachment page in usermeta is labelled as one', $linkUserRefs[0]->match, 'attachment-page');
check('the usermeta query admits the query-arg form', $admits($linkUserBound, $linkQuery), true);
check('the usermeta query admits the rel marker', $admits($linkUserBound, $linkRel), true);

// --- commentmeta

[$linkCommentIds, $linkCommentBound, $linkCommentRefs] = $runLink(
    static fn(int $oid, string $v): object => (object) ['comment_id' => (string) $oid, 'meta_key' => 'review_media', 'meta_value' => $v, 'comment_approved' => '1'],
    static fn(): array => $inMeta->invoke($commentDetector, $ctx)
);

check('every link form in commentmeta resolves, neither near-miss does', $linkCommentIds, [61, 62, 63]);
check('a linked attachment page in commentmeta is labelled as one', $linkCommentRefs[0]->match, 'attachment-page');
check('the commentmeta query admits the query-arg form', $admits($linkCommentBound, $linkQuery), true);
check('the commentmeta query admits the rel marker', $admits($linkCommentBound, $linkRel), true);

// --- postmeta

[$linkPostIds, $linkPostBound, $linkPostRefs] = $runLink(
    static fn(int $oid, string $v): object => (object) ['post_id' => (string) $oid, 'meta_key' => 'intro_html', 'meta_value' => $v, 'post_status' => 'publish'],
    static fn(): array => (new PostmetaDetector())->find($ctx)
);

check('every link form in postmeta resolves, neither near-miss does', $linkPostIds, [61, 62, 63]);
check('a linked attachment page in postmeta is labelled as one', $linkPostRefs[0]->match, 'attachment-page');
check('the postmeta query admits the query-arg form', $admits($linkPostBound, $linkQuery), true);
check('the postmeta query admits the rel marker', $admits($linkPostBound, $linkRel), true);
check('the postmeta query admits the class marker', $admits($linkPostBound, $linkClass), true);

// --- options: all three branches, because all three are behind one query

$linkWidget = serialize([2 => ['title' => '', 'text' => $linkQuery]]);
$linkThemeMod = serialize(['footer_html' => $linkRel]);

$GLOBALS['wpdb']->rows = [
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $linkQuery],
    (object) ['option_name' => 'widget_text', 'option_value' => $linkWidget],
    (object) ['option_name' => 'theme_mods_freshet', 'option_value' => $linkThemeMod],
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $linkLonger],
    (object) ['option_name' => 'my_plugin_intro', 'option_value' => $linkPrefixed],
];

$linkOptionRefs = (new OptionsDetector())->find($ctx);
$linkOptionBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('every options branch answers a link, and neither near-miss does', count($linkOptionRefs), 3);
check('a link in a plain option is labelled as one', $linkOptionRefs[0]->match, 'attachment-page');
check('a link in a text widget resolves', $linkOptionRefs[1]->match, 'attachment-page');
check('a widget hit is still reported as an option', $linkOptionRefs[1]->objectType, 'option');
check('a link in a theme mod resolves', $linkOptionRefs[2]->match, 'attachment-page');
check('a theme-mod hit keeps its object type', $linkOptionRefs[2]->objectType, 'theme_mod');
check('the options query admits the query-arg form', $admits($linkOptionBound, $linkQuery), true);
check('the options query admits a widget carrying a link', $admits($linkOptionBound, $linkWidget), true);
check('the options query admits a theme mod carrying a link', $admits($linkOptionBound, $linkThemeMod), true);

// --- term descriptions, the sixth column behind the same helper

[$linkDescIds, $linkDescBound, $linkDescRefs] = $runLink(
    static fn(int $oid, string $v): object => (object) ['term_id' => (string) $oid, 'description' => $v],
    static fn(): array => $termDetector->find($ctx)
);

check('every link form in a description resolves, neither near-miss does', $linkDescIds, [61, 62, 63]);
check('a linked attachment page in a description is labelled as one', $linkDescRefs[0]->match, 'attachment-page');
check('a linked attachment page in a description is possible, not confirmed', $linkDescRefs[0]->confidence, Reference::POSSIBLE);
check('the term description query admits the query-arg form', $admits($linkDescBound, $linkQuery), true);
check('the term description query admits the rel marker', $admits($linkDescBound, $linkRel), true);

// The boundaries, as the pair each needle earns. 'attachment_id=' and 'wp-att-'
// are left-delimiters with no right-hand one, so the longer id IS fetched on
// every column and the verifier is what throws it away; a prefixed id shares no
// left anchor, so it is never fetched at all. Both halves are asserted, on all
// six columns, so a later widening cannot drop one of them.

$linkBounds = [$linkTermBound, $linkUserBound, $linkCommentBound, $linkPostBound, $linkOptionBound, $linkDescBound];

check('every column admits the longer id, having no right boundary', array_map(static fn(array $b): bool => $admits($b, $linkLonger), $linkBounds), [true, true, true, true, true, true]);
check('no column fetches the prefixed id at all', array_map(static fn(array $b): bool => $admits($b, $linkPrefixed), $linkBounds), [false, false, false, false, false, false]);
check('the verifiers reject the longer id every query admitted', array_map(static fn(array $ids): bool => in_array(68, $ids, true), [$linkTermIds, $linkUserIds, $linkCommentIds, $linkPostIds, $linkDescIds]), [false, false, false, false, false]);
check('the options verifier rejects both near-misses it saw', count($linkOptionRefs), 3);

// A value that decodes keeps its more specific verdict: the link branch sits
// after the structure walk, never in front of it, exactly as the quoted-id one
// does. A JSON blob whose href happens to carry the id is still stored data.

$GLOBALS['wpdb']->rows = [(object) ['post_id' => '64', 'meta_key' => 'settings', 'meta_value' => '{"hero":{"image":123},"link":"/?attachment_id=123"}', 'post_status' => 'publish']];
$linkDecodes = (new PostmetaDetector())->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('a value that decodes keeps its serialized verdict over the link', $linkDecodes[0]->match, 'serialized');

// --------------------------------------------------------- license states
//
// The gate has one job: decide whether the Used view exists on this site. It
// must never open on a site that has not paid, never shut on a site that has
// because the license server had a bad afternoon, and never call out at all
// when there is no key — the wordpress.org build must not phone home.

$TRANSIENT = 'freshet_unusedmedia_license_status';
$LAST_OK = 'freshet_unusedmedia_license_last_ok';

$license = static fn(): RemoteLicense => new RemoteLicense(new LicenseClient('https://license.test'));

$reply = static fn(bool $valid): array => [
    'code' => 200,
    'body' => json_encode(['success' => true, 'data' => ['valid' => $valid]]),
];

$reset = static function (string $key = '', ?int $lastOk = null) use ($LAST_OK): void {
    $GLOBALS['options'] = $key !== '' ? [RemoteLicense::OPTION_KEY => $key] : [];
    $GLOBALS['transients'] = [];
    $GLOBALS['http'] = null;
    $GLOBALS['httpCalls'] = 0;

    if ($lastOk !== null) {
        $GLOBALS['options'][$LAST_OK] = $lastOk;
    }
};

// No key at all — the free site, and the one state that must make no request.
$reset();
check('no key is not licensed', $license()->isPro(), false);
check('no key never calls the license server', $GLOBALS['httpCalls'], 0);

// A validating key: licensed, and the success is what the grace window dates from.
$reset('KEY-1');
$GLOBALS['http'] = $reply(true);
check('a validating key is licensed', $license()->isPro(), true);
check('a validating key records the last success', isset($GLOBALS['options'][$LAST_OK]), true);

// Expired or revoked: the server's "no" is authoritative even for a site that
// validated a minute ago — a lapsed license is not a network problem.
$reset('KEY-1', time() - 60);
$GLOBALS['http'] = $reply(false);
check('an expired key is not licensed', $license()->isPro(), false);
check('an expired key caches the refusal', $GLOBALS['transients'][$TRANSIENT]['valid'], false);

// Server unreachable: fail open, but only from a real previous success and only
// for seven days. Boundary included — blocking the server is not a way to buy.
$reset('KEY-1', time() - 3 * DAY_IN_SECONDS);
check('an unreachable server keeps a recent license', $license()->isPro(), true);

$reset('KEY-1', time() - 8 * DAY_IN_SECONDS);
check('an unreachable server drops a stale license', $license()->isPro(), false);

$reset('KEY-1', time() - 7 * DAY_IN_SECONDS);
check('the grace window ends at seven days', $license()->isPro(), false);

$reset('KEY-1');
check('an unreachable server never opens without a prior success', $license()->isPro(), false);

// The cache: one call per 12h, and never a verdict borrowed from another key.
$reset('KEY-1');
$GLOBALS['http'] = $reply(true);
$license()->isPro();
$license()->isPro();
check('a cached validation makes no second request', $GLOBALS['httpCalls'], 1);

$reset('KEY-NEW');
$GLOBALS['transients'][$TRANSIENT] = ['key' => 'KEY-OLD', 'valid' => true];
$GLOBALS['http'] = $reply(false);
check('a cached verdict for another key is ignored', $license()->isPro(), false);

// The directory build, where there is no client and no feature to unlock.
check('the wordpress.org build is never licensed', (new NoLicense())->isPro(), false);

// ------------------------------------------------- the upload grace, in words

// The window the screens quote has to be the window the detector enforces: a
// grace someone has filtered and a paragraph still saying "24 hours" is the one
// line on the screen that lies (freshet-D92 (4)). Same read, one filter.

$grace = static function (mixed $seconds = null): void {
    if ($seconds === null) {
        unset($GLOBALS['filters']['freshet_unusedmedia_upload_grace']);

        return;
    }

    $GLOBALS['filters']['freshet_unusedmedia_upload_grace'] = $seconds;
};

$grace();
check('the default grace is a day', UploadGrace::seconds(), DAY_IN_SECONDS);
check('the default grace reads as hours, not "1 day"', UploadGrace::window(), '24 hours');
check('the held-back line quotes the window', UploadGrace::heldBack(), 'Held back — uploaded in the last 24 hours');
check('the default grace is active', UploadGrace::isActive(), true);

$grace(2 * DAY_IN_SECONDS);
check('a two-day grace reads as days', UploadGrace::window(), '2 days');
check('the held-back line follows the filter', UploadGrace::heldBack(), 'Held back — uploaded in the last 2 days');

$grace(HOUR_IN_SECONDS);
check('an hour reads as an hour', UploadGrace::window(), '1 hour');

$grace(90 * MINUTE_IN_SECONDS);
check('ninety minutes is not a whole hour', UploadGrace::window(), '90 minutes');

$grace(30);
check('half a minute reads in seconds', UploadGrace::window(), '30 seconds');

$grace('86400');
check('a string from a filter is still a day', UploadGrace::seconds(), DAY_IN_SECONDS);

// 0 disables the hold entirely, which is how the test install sees a fresh
// upload immediately — and a screen with nothing to hold back says nothing.
$grace(0);
check('a zero grace holds nothing back', UploadGrace::isActive(), false);

$grace(-1);
check('a negative grace holds nothing back', UploadGrace::isActive(), false);

$grace();

// --------------------------------------------- the generated-size shape
//
// The names AttachmentContext now reads off the disk, and the one rule that
// keeps that safe: the part before `-WIDTHxHEIGHT` is compared to the stem
// WHOLE, never as a prefix. `hero.jpg` and `hero-1.jpg` are two uploads, and
// the second one's sizes are named after it — so a prefix match would hand
// hero.jpg a name for a file it does not own, and a page referencing that file
// would then keep the wrong original alive. Every negative below is a file that
// really does sit in the same directory as the positives.

check('a generated size names the stem it was cut from', SizeSiblings::sizeStem('hero-300x200.jpg'), 'hero');
check('an alternate-mime copy of one is still that stem', SizeSiblings::sizeStem('hero-300x200.jpg.webp'), 'hero');
check('a second upload\'s size names the second upload', SizeSiblings::sizeStem('hero-1-300x200.jpg'), 'hero-1');
check('so hero does not own it', SizeSiblings::sizeStem('hero-1-300x200.jpg') === 'hero', false);
check('the original itself is not a size', SizeSiblings::sizeStem('hero.jpg'), '');
check('nor is the -scaled copy of it', SizeSiblings::sizeStem('hero-scaled.jpg'), '');
check('nor a name that merely ends in digits', SizeSiblings::sizeStem('hero-300.jpg'), '');
check('nor one with the dimensions in the middle', SizeSiblings::sizeStem('hero-300x200-print.jpg'), '');
check('nor a name with no extension at all', SizeSiblings::sizeStem('hero-300x200'), '');
check('a non-ASCII name keeps its own stem', SizeSiblings::sizeStem(FIXTURE_UNICODE_STEM . '-300x200.jpg'), FIXTURE_UNICODE_STEM);

// The stem an original is known by, which is what a size's stem is compared
// against. The -scaled and -rotated copies and the original are one stem.
check('the original is its own stem', SizeSiblings::stem('hero.jpg'), 'hero');
check('the -scaled copy is the same stem', SizeSiblings::stem('hero-scaled.jpg'), 'hero');
check('the -rotated copy too', SizeSiblings::stem('hero-rotated.jpg'), 'hero');
check('a second upload is a stem of its own', SizeSiblings::stem('hero-1.jpg'), 'hero-1');

// The dimensions are NOT stripped, because core does not strip them either: an
// upload that arrives called `photo-1024x768.jpg` has its sizes named from the
// whole of that. Reading it as `photo` would hand it every `photo-*x*.jpg` in
// the directory, which is another upload's family.
check('an upload whose own name ends in dimensions keeps them', SizeSiblings::stem('photo-1024x768.jpg'), 'photo-1024x768');
check('and its own sizes are cut from the whole name', SizeSiblings::sizeStem('photo-1024x768-300x200.jpg'), 'photo-1024x768');
check('so the shorter name does not claim them', SizeSiblings::sizeStem('photo-1024x768-300x200.jpg') === 'photo', false);

// The format is part of the boundary too. A library holding `doc.pdf` and
// `doc.jpg` as two uploads is ordinary, and `doc-300x200.jpg` belongs to the
// second one — so a size is this attachment's only when it is in this
// attachment's format, with the alternate-mime chain allowed on the end.
check('a size in this attachment\'s own format is its own', SizeSiblings::isSibling('hero-300x200.jpg', 'hero', 'jpg'), true);
check('an alternate-mime copy of that size still is', SizeSiblings::isSibling('hero-300x200.jpg.webp', 'hero', 'jpg'), true);
check('a same-named upload in another format is not', SizeSiblings::isSibling('doc-300x200.jpg', 'doc', 'pdf'), false);
check('and neither is another stem in the right format', SizeSiblings::isSibling('logo-300x200.jpg', 'hero', 'jpg'), false);

// How the sizes are named is read off what core recorded for this very
// attachment, rather than guessed from the attached file. A document is why:
// core renders it to `doc-pdf.jpg` first and cuts the previews from that, so
// neither the stem nor the format can be read off `doc.pdf`.
check(
    'the naming is read off the metadata core wrote',
    SizeSiblings::naming('/uploads/2026/07/doc.pdf', ['sizes' => ['medium' => ['file' => 'doc-pdf-300x169.jpg']]]),
    ['doc-pdf', 'jpg']
);
check(
    'and falls back to the attached file where there is none',
    SizeSiblings::naming('/uploads/2026/07/hero-scaled.jpg', false),
    ['hero', 'jpg']
);

// A directory that is not there is silence, not a failure: media is routinely
// offloaded, and a scan that stopped on a missing month would be worse than one
// that finds no siblings in it.
check('an unreadable directory yields no siblings', SizeSiblings::forFile('/nonexistent-freshet/2026/07/hero-scaled.jpg'), []);

// --------------------------------------------- a library that is not on this server
//
// The disk read is half of how an attachment's names are found, and on a
// library whose files live on an offload or CDN service it does nothing —
// `get_attached_file()` is filtered to a stream URI, and a folder that cannot
// be opened yields the same empty listing as a folder with nothing in it. Two
// things follow, and both are asserted here rather than reasoned about.
//
// The first is that asking must be silent. `is_dir()` on a scheme no wrapper
// claims warns before it answers, and a scan asks once per directory — with
// display_errors on, that text goes into the body of the AJAX response ahead of
// the JSON the scan screen is waiting for, so the screen breaks rather than
// merely getting noisy. The check below is the failure itself: whatever the
// call prints on its way out has to be nothing.
//
// The second is that the silence must be counted. Nothing else in a run records
// it — no error is raised and no attachment is left short — so without a tally
// there is no way for the screen to say that the protection this class exists
// to give is switched off for this library.

define('DISK_READ_DIR', sys_get_temp_dir() . '/freshet-unusedmedia-disk-read/2026/07');

if (!is_dir(DISK_READ_DIR)) {
    mkdir(DISK_READ_DIR, 0777, true);
}

// The original, one of its sizes, and a second upload's size — the boundary
// case, in the same directory, exactly as it is on a real month's folder.
foreach (['hero-scaled.jpg', 'hero-300x200.jpg', 'hero-1-300x200.jpg'] as $diskFixture) {
    file_put_contents(DISK_READ_DIR . '/' . $diskFixture, 'x');
}

SizeSiblings::flush();
SizeSiblings::takeDirectoryReads();

check('a readable directory still yields this attachment\'s own sizes', SizeSiblings::forFile(DISK_READ_DIR . '/hero-scaled.jpg'), ['hero-300x200.jpg']);
check('and the run counts it as a directory it read', SizeSiblings::takeDirectoryReads(), ['read' => 1, 'unread' => 0]);

// The premise of the next two: this box has no s3 wrapper, which is what makes
// the path warn. A box that had one would read the remote directory through the
// same call and the disk read would simply work — the answer is what matters
// here, never the shape of the path.
check('no wrapper claims the offloaded scheme on this box', in_array('s3', stream_get_wrappers(), true), false);

$displayErrors = ini_get('display_errors');
ini_set('display_errors', '1');
$reporting = error_reporting(E_ALL);

ob_start();
$offloaded = SizeSiblings::forFile('s3://example-bucket/2026/07/hero-scaled.jpg');
$emitted = (string) ob_get_clean();

error_reporting($reporting);
ini_set('display_errors', (string) $displayErrors);

check('an offloaded path yields no siblings', $offloaded, []);
check('and prints nothing where a response body would be', $emitted, '');
check('and the run counts it as a directory it could not read', SizeSiblings::takeDirectoryReads(), ['read' => 0, 'unread' => 1]);
check('the tally is handed over once and starts again', SizeSiblings::takeDirectoryReads(), ['read' => 0, 'unread' => 0]);

// A batch is a page of attachment IDs, and ID order is not directory order: an
// import, a re-upload or a plugin that writes its own files puts two months
// next to each other in the same page. With one remembered listing that batch
// re-read a directory per attachment, so the memo holds a few — bounded,
// because a scan eventually visits every directory the library has and
// remembering all of them trades a directory read for memory that grows with
// the library (freshet-149).

$memoDirs = [];

foreach (['08', '09', '10', '11', '12'] as $month) {
    $memoDir = sys_get_temp_dir() . '/freshet-unusedmedia-disk-read/2026/' . $month;

    if (!is_dir($memoDir)) {
        mkdir($memoDir, 0777, true);
    }

    foreach (['hero-scaled.jpg', 'hero-300x200.jpg'] as $diskFixture) {
        file_put_contents($memoDir . '/' . $diskFixture, 'x');
    }

    $memoDirs[] = $memoDir;
}

SizeSiblings::flush();
SizeSiblings::takeDirectoryReads();

for ($alternating = 0; $alternating < 6; $alternating++) {
    SizeSiblings::forFile($memoDirs[$alternating % 2] . '/hero-scaled.jpg');
}

check('a batch alternating between two directories reads each of them once', SizeSiblings::takeDirectoryReads(), ['read' => 2, 'unread' => 0]);

SizeSiblings::flush();
SizeSiblings::takeDirectoryReads();
SizeSiblings::forFile($memoDirs[0] . '/hero-scaled.jpg');

check('and the batch boundary still drops what was remembered', SizeSiblings::takeDirectoryReads(), ['read' => 1, 'unread' => 0]);

// One directory more than the memo holds. The oldest goes, the newest stays,
// and the answer is the directory's own either way — which is the property that
// matters: a memo that returned a neighbour's listing would attach one
// attachment's leftover sizes to another.
foreach ($memoDirs as $memoDir) {
    SizeSiblings::forFile($memoDir . '/hero-scaled.jpg');
}

SizeSiblings::takeDirectoryReads();

check('the oldest listing is the one evicted', SizeSiblings::forFile($memoDirs[0] . '/hero-scaled.jpg'), ['hero-300x200.jpg']);
check('so asking for it again is a read', SizeSiblings::takeDirectoryReads(), ['read' => 1, 'unread' => 0]);
check('while the most recent is still remembered', SizeSiblings::forFile($memoDirs[4] . '/hero-scaled.jpg'), ['hero-300x200.jpg']);
check('and costs nothing to ask for', SizeSiblings::takeDirectoryReads(), ['read' => 0, 'unread' => 0]);

SizeSiblings::flush();

// --------------------------------------------- the superseded generation
//
// An image edited in wp-admin keeps its old files. Core rewrites the stem —
// `sunset.jpg` becomes `sunset-e1673970542774.jpg` — rewrites the metadata to
// describe the new generation only, and records the old one under
// `_wp_attachment_backup_sizes` and nowhere else. So the pre-edit original is
// in none of this class's other sources: not the attached file, which is the
// edited one; not the metadata; and not the disk read above, whose stem is the
// attached file's own and is compared whole (`sunset` is not
// `sunset-e1673970542774`). A page still showing the pre-edit file therefore
// resolved to no attachment at all, and the attachment could scan unused while
// that file was on screen — the same failure the section above closes, reached
// by a different route.

$edited = AttachmentContext::forAttachment(FIXTURE_EDITED_ID)->basenames;

check('the edited file is a name', in_array(FIXTURE_EDITED_FILE, $edited, true), true);
check('and so is the edited generation\'s size', in_array(FIXTURE_EDITED_SIZE, $edited, true), true);
check('the superseded original is a name too', in_array(FIXTURE_PRE_EDIT_FILE, $edited, true), true);
check('and so is the superseded size', in_array(FIXTURE_PRE_EDIT_SIZE, $edited, true), true);
check('a backup entry that is not an array adds nothing', count($edited), 4);

// Both engines, so a later change cannot half-drop it: the query has to fetch
// the row and the verifier has to accept it, and either one alone is a file
// that still reads unused.
$preEdit = '<img src="https://example.test/wp-content/uploads/2026/07/' . FIXTURE_PRE_EDIT_FILE . '" alt="">';

[$editedConditions, $editedParams] = LikePatterns::basenameConditions('post_content', $edited);

check('the query fetches a row carrying the pre-edit original', in_array('%' . FIXTURE_PRE_EDIT_FILE . '%', $editedParams, true), true);
check('and the verifier resolves that markup to this attachment', LikePatterns::containsBasename($preEdit, $edited), true);
check('one condition per name, no more', count($editedConditions), count($edited));

// The other direction: nothing referencing the superseded generation leaves the
// attachment judged on its live names exactly as before. The backup names are
// full filenames like every other one — never a stem another upload's files
// could be claimed through, which is what would attach a reference to the wrong
// file.
check('a second upload sharing the stem is not one of these names', in_array('sunset-2.jpg', $edited, true), false);
check('nor is a size cut from that second upload', in_array('sunset-2-300x200.jpg', $edited, true), false);
check('so markup for it resolves elsewhere', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/sunset-2.jpg">', $edited), false);
check('while the live edited file still resolves here', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/' . FIXTURE_EDITED_FILE . '">', $edited), true);

// An attachment that was never edited has no such key, and gains nothing.
check('an unedited attachment gains no names', count($names), 5);

// And the one shape a production library actually carries beside the arrays:
// the key present with an empty value. It must contribute no name and say
// nothing while doing it — a warning per attachment is a scan that fills a log.
$diagnostics = [];

set_error_handler(static function (int $errno, string $message) use (&$diagnostics): bool {
    $diagnostics[] = $message;

    return true;
});

$emptyBackup = AttachmentContext::forAttachment(FIXTURE_EMPTY_BACKUP_ID)->basenames;

restore_error_handler();

check('an empty backup value contributes no name', $emptyBackup, ['beach.jpg']);
check('and raises no diagnostic', $diagnostics, []);

// ------------------------------ core's own record is not somebody's reference
//
// The other side of the section above. Now that every superseded filename is a
// needle, the row core keeps them in is a matchable row — and two uploads cut
// from the same image carry byte-identical `_wp_attachment_backup_sizes`
// values, so each would answer for the other. The scanned attachment's own rows
// were never the risk (the query carries `pm.post_id <> %d`); a sibling's are.
// The exclusion is in the WHERE, so the check is on the statement the detector
// builds, and then on the rows that WHERE actually leaves it.

$editedCtx = AttachmentContext::forAttachment(FIXTURE_EDITED_ID);
$excludedKeys = (array) (new ReflectionClass(PostmetaDetector::class))->getConstant('EXCLUDED_KEYS');

// A sibling upload's backup record, and an ordinary field holding the same
// filename. Only one of the two is a human pointing at the file.
$siblingBackupRow = (object) [
    'post_id' => '77',
    'meta_key' => '_wp_attachment_backup_sizes',
    'meta_value' => serialize(['full-orig' => ['width' => 1600, 'height' => 1200, 'file' => FIXTURE_PRE_EDIT_FILE]]),
    'post_status' => 'inherit',
];
$ordinaryRow = (object) [
    'post_id' => '78',
    'meta_key' => 'hero_html',
    'meta_value' => '<img src="/wp-content/uploads/2026/07/' . FIXTURE_PRE_EDIT_FILE . '" alt="">',
    'post_status' => 'publish',
];

$GLOBALS['wpdb']->rows = [$siblingBackupRow, $ordinaryRow];
(new PostmetaDetector())->find($editedCtx);
$backupBound = $GLOBALS['wpdb']->lastParams;

check('the backup-sizes key is excluded like the metadata key', in_array('_wp_attachment_backup_sizes', $excludedKeys, true), true);
check('and it is bound into the statement, not merely listed', in_array('_wp_attachment_backup_sizes', $backupBound, true), true);

// The rows the WHERE leaves behind — filtered by the detector's own excluded
// keys rather than a list repeated here, so this cannot pass a fix it did not get.
$GLOBALS['wpdb']->rows = array_values(array_filter(
    [$siblingBackupRow, $ordinaryRow],
    static fn(object $row): bool => !in_array($row->meta_key, $excludedKeys, true)
));

$siblingRefs = (new PostmetaDetector())->find($editedCtx);
$GLOBALS['wpdb']->rows = [];

$siblingKeys = array_map(static fn($ref): string => $ref->detail, $siblingRefs);

check('a sibling attachment\'s backup record is not a reference', in_array('_wp_attachment_backup_sizes', $siblingKeys, true), false);
check('an ordinary meta key carrying the same filename still is', in_array('hero_html', $siblingKeys, true), true);
check('so the pair yields exactly one reference', count($siblingRefs), 1);

// -------------------------------- a query that failed is not an empty answer

// The defect (freshet-141): $wpdb hands back the same empty array for "no rows
// matched" and for "the query never ran" — last_result is emptied before every
// query and a failed one leaves it empty — so every detector read a timed-out
// query as "nothing references this file", and the file went to the deletable
// pool.
//
// Both directions are asserted, and the empty one is not a formality: a
// detector that had stopped answering altogether would pass the error case on
// its own. The pair says the detector still reports nothing when there is
// nothing, and refuses to report anything when it could not look.

$failureCtx = AttachmentContext::forAttachment(FIXTURE_ID);

$queryDetectors = [
    'postmeta' => new PostmetaDetector(),
    'post-content' => new PostContentDetector(),
    'options' => new OptionsDetector(),
    'termmeta' => new TermMetaDetector(),
    'term-description' => new TermDescriptionDetector(),
    'usermeta' => new UserMetaDetector(),
    'comment' => new CommentDetector(),
];

foreach ($queryDetectors as $label => $detector) {
    $GLOBALS['wpdb']->failWith = '';
    $GLOBALS['wpdb']->rows = [];

    check("{$label}: a genuinely empty result is still no references", $detector->find($failureCtx), []);

    $GLOBALS['wpdb']->failWith = 'MySQL server has gone away';
    $thrown = null;

    try {
        $detector->find($failureCtx);
    } catch (QueryFailed $e) {
        $thrown = $e;
    }

    check("{$label}: a failed query refuses to answer", $thrown instanceof QueryFailed, true);
    check("{$label}: and it names which read failed", $thrown?->context, $label);
    check("{$label}: and carries the driver's message", str_contains((string) $thrown?->getMessage(), 'gone away'), true);
}

$GLOBALS['wpdb']->failWith = '';
$GLOBALS['wpdb']->last_error = '';
$GLOBALS['wpdb']->rows = [];

// The second half of the comment detector: its meta query is a separate read in
// a separate method, so the content read is let through and only the meta one
// fails. A guard on the first read alone would leave this one silent, and
// silent is the whole defect.
$GLOBALS['wpdb']->calls = 0;
$GLOBALS['wpdb']->failFrom = 2;
$GLOBALS['wpdb']->failWith = 'Lost connection to MySQL server during query';
$metaThrown = null;

try {
    (new CommentDetector())->find($failureCtx);
} catch (QueryFailed $e) {
    $metaThrown = $e;
}

check('the comment meta read refuses on its own', $metaThrown instanceof QueryFailed, true);
check('and says which of the two it was', $metaThrown?->context, 'comment (meta)');

$GLOBALS['wpdb']->failFrom = 0;
$GLOBALS['wpdb']->calls = 0;
$GLOBALS['wpdb']->failWith = '';
$GLOBALS['wpdb']->last_error = '';

// A stale error left on $wpdb by someone else's query is not this plugin's to
// interpret, and Db::forget() is what a scan starts from — without it the first
// detector in the request inherits a failure it did not cause.
$GLOBALS['wpdb']->last_error = 'Table \'wp_somebody_else\' doesn\'t exist';
\FreshetUnusedMedia\Scan\Db::forget();
check('a foreign error is forgotten at the start of a scan', $GLOBALS['wpdb']->last_error, '');
check('and the detector then answers normally', (new PostmetaDetector())->find($failureCtx), []);

// ------------------------------- block markup outside post_content (143)
//
// A block delimiter is not confined to post_content. A `widget_block` widget,
// a theme mod, an option holding a stored template, a meta field holding a
// page-builder payload — all of them carry the same
// `<!-- wp:vendor/name {…} /-->` markup, and a field-framework block keeps its
// attachment as a bare integer inside that attribute JSON.
//
// Every id condition the query binds wants a delimiter hard against the digits
// (":123,", ":123}", "\"123\"", "i:123;"), so those rows ARE fetched — but the
// only verifier that could read them, the block parse, lived privately inside
// PostContentDetector and nothing else called it. A leaf beginning "<" is not
// JSON, so the structure walk stopped there too: the row came back, was handed
// to every verifier in turn, and each one declined. The string form
// ({"logo":"123"}) survived by accident on the quoted-id fall-through; the
// integer form did not, and the file scanned unused.
//
// The carriers below are the five the review executed, plus termmeta and
// usermeta, which store the same thing. The parser is now one implementation in
// LikePatterns, reached from the structure walk's string leaf (so every
// detector that walks a stored value gets it) and directly by the two callers
// that have no walk to enter — post content, and a plain option value.

$blockCarrier = '<!-- wp:acf/hero {"id":"block_64a1f2","name":"acf/hero","data":{"logo":123,"_logo":"field_a1"},"mode":"edit"} /-->';
$blockDecoy = '<!-- wp:acf/hero {"id":"block_7c3e","name":"acf/hero","data":{"logo":1234,"_logo":"field_a1"},"mode":"edit"} /-->';
$blockPrefixDecoy = '<!-- wp:acme/hero {"imageId":9123} /-->';

// The two option branches that unserialize before they walk.
$blockWidget = serialize([2 => ['content' => $blockCarrier]]);
$blockWidgetDecoy = serialize([2 => ['content' => $blockDecoy]]);
$blockThemeMod = serialize(['footer_html' => $blockCarrier]);
$blockSerializedOption = serialize(['intro' => $blockCarrier]);

check('the shared parser reads an int id out of a block delimiter', LikePatterns::hasBlockAttribute($blockCarrier, $id, $names), true);
check('and rejects a longer id in the same position', LikePatterns::hasBlockAttribute($blockDecoy, $id, $names), false);
check('and rejects a prefixed one', LikePatterns::hasBlockAttribute($blockPrefixDecoy, $id, $names), false);
check('block markup with no delimiter at all is not a reference', LikePatterns::hasBlockAttribute('<p>123</p>', $id, $names), false);
check('the structure walk reaches the delimiter at a string leaf', LikePatterns::structureContains(['content' => $blockCarrier], $id, $names), true);
check('and the walk rejects the decoy at the same leaf', LikePatterns::structureContains(['content' => $blockDecoy], $id, $names), false);

// A delimiter nested inside JSON, where the namespace slash and the attribute
// quotes are escaped: the raw text does not match the delimiter pattern at all,
// and only the walk — which decodes the leaf first — can reach it.
$blockInJson = json_encode(['blocks' => [['markup' => $blockCarrier]]]);
check('a json-escaped delimiter resolves through the walk', LikePatterns::structureContains(LikePatterns::decodeStored((string) $blockInJson), $id, $names), true);

// --- options: widget_block, a theme mod, a plain option and a serialized one

$GLOBALS['wpdb']->rows = [
    (object) ['option_name' => 'widget_block', 'option_value' => $blockWidget],
    (object) ['option_name' => 'theme_mods_freshet', 'option_value' => $blockThemeMod],
    (object) ['option_name' => 'my_plugin_template', 'option_value' => $blockCarrier],
    (object) ['option_name' => 'my_plugin_settings', 'option_value' => $blockSerializedOption],
    (object) ['option_name' => 'widget_block', 'option_value' => $blockWidgetDecoy],
    (object) ['option_name' => 'my_plugin_template', 'option_value' => $blockDecoy],
    (object) ['option_name' => 'my_plugin_template', 'option_value' => $blockPrefixDecoy],
];

$blockOptionRefs = (new OptionsDetector())->find($ctx);
$blockOptionBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('all four option carriers resolve and no decoy does', count($blockOptionRefs), 4);
check('a block in a widget_block widget is used', $blockOptionRefs[0]->countsAsUsed(), true);
check('and is resolved in the widget structure', $blockOptionRefs[0]->match, 'widget');
check('a block in a theme mod is used', $blockOptionRefs[1]->countsAsUsed(), true);
check('and keeps its theme-mod object type', $blockOptionRefs[1]->objectType, 'theme_mod');
check('a block in a plain option is used', $blockOptionRefs[2]->countsAsUsed(), true);
check('and is labelled as the block field it is', $blockOptionRefs[2]->match, 'acf-block');
check('a block inside a serialized option is used', $blockOptionRefs[3]->countsAsUsed(), true);
check('the options query already fetches the widget carrier', $admits($blockOptionBound, $blockWidget), true);
check('the options query already fetches the theme mod', $admits($blockOptionBound, $blockThemeMod), true);
check('the options query already fetches the plain option', $admits($blockOptionBound, $blockCarrier), true);
check('the options query already fetches the serialized option', $admits($blockOptionBound, $blockSerializedOption), true);

// --- postmeta, termmeta, usermeta: the same markup parked in a meta value

$GLOBALS['wpdb']->rows = [
    (object) ['post_id' => '41', 'meta_key' => 'hero_block', 'meta_value' => $blockCarrier, 'post_status' => 'publish'],
    (object) ['post_id' => '42', 'meta_key' => 'hero_block', 'meta_value' => $blockDecoy, 'post_status' => 'publish'],
    (object) ['post_id' => '43', 'meta_key' => 'hero_block', 'meta_value' => $blockPrefixDecoy, 'post_status' => 'publish'],
];
$blockPostRefs = (new PostmetaDetector())->find($ctx);
$blockPostBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a block in a postmeta value resolves and neither decoy does', array_map(static fn($r) => $r->objectId, $blockPostRefs), [41]);
check('and it counts as used', $blockPostRefs[0]->countsAsUsed(), true);
check('the postmeta query already fetches the carrier', $admits($blockPostBound, $blockCarrier), true);

$GLOBALS['wpdb']->rows = [
    (object) ['term_id' => '51', 'meta_key' => 'hero_block', 'meta_value' => $blockCarrier],
    (object) ['term_id' => '52', 'meta_key' => 'hero_block', 'meta_value' => $blockDecoy],
];
$blockTermRefs = (new TermMetaDetector())->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('a block in a termmeta value resolves and the decoy does not', array_map(static fn($r) => $r->objectId, $blockTermRefs), [51]);
check('and it counts as used', $blockTermRefs[0]->countsAsUsed(), true);

$GLOBALS['wpdb']->rows = [
    (object) ['user_id' => '61', 'meta_key' => 'hero_block', 'meta_value' => $blockCarrier],
    (object) ['user_id' => '62', 'meta_key' => 'hero_block', 'meta_value' => $blockDecoy],
];
$blockUserRefs = (new UserMetaDetector())->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('a block in a usermeta value resolves and the decoy does not', array_map(static fn($r) => $r->objectId, $blockUserRefs), [61]);
check('and it counts as used', $blockUserRefs[0]->countsAsUsed(), true);

// The one that already worked, re-asserted here so the shared parser is proven
// to still answer its original caller after being moved out of it.
$GLOBALS['wpdb']->rows = [
    (object) ['ID' => '71', 'post_parent' => '0', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $blockCarrier, 'post_excerpt' => ''],
    (object) ['ID' => '72', 'post_parent' => '0', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $blockDecoy, 'post_excerpt' => ''],
];
$blockContentRefs = (new PostContentDetector())->find($ctx);
$GLOBALS['wpdb']->rows = [];

check('post content still resolves the same delimiter', array_map(static fn($r) => $r->objectId, $blockContentRefs), [71]);
check('and still labels it a block field', $blockContentRefs[0]->match, 'acf-block');

// ------------------------------------------- one broad pass for one group
//
// Rows standing on one `_wp_attached_file` are one file, and the delete path
// re-scans every one of them before it removes anything. They ask the detectors
// nearly the same question — one file, one basename set, different ids — so it
// was asked once per row, ten times over on a library where a file averages ten
// rows, and that was 94% of what a delete request spent (freshet-155).
//
// Three things have to survive making it one pass. The needles may only widen.
// Every row still reaches its own verdict. And a read that did not answer must
// refuse for every row behind it rather than reading as "no references found",
// which is freshet-141 and the reason this is asserted rather than assumed.

$groupIds = [FIXTURE_ID, FIXTURE_SIBLING_ID];

SharedReads::open($groupIds);

$ownCtx = AttachmentContext::forAttachment(FIXTURE_ID);
$siblingCtx = AttachmentContext::forAttachment(FIXTURE_SIBLING_ID);

check('both rows of an open group share the pass', [$ownCtx->shared, $siblingCtx->shared], [true, true]);
check('and both bind every id in it', [$ownCtx->queryIds, $siblingCtx->queryIds], [$groupIds, $groupIds]);
check('while each still verifies as itself', [$ownCtx->id, $siblingCtx->id], $groupIds);

// The union, and it has to be one: two rows on a path can carry different
// metadata, and a size named by only one of them that fell out of the needles
// is a reference nobody fetches — which is a live file offered for deletion.
check(
    'the shared needles are the union of the group basenames',
    in_array(FIXTURE_SIBLING_SIZE, $ownCtx->queryBasenames, true),
    true
);
check(
    'and a row does not take its sibling name as its own',
    in_array(FIXTURE_SIBLING_SIZE, $ownCtx->basenames, true),
    false
);

// One candidate row, and it is the sibling's own meta naming the shared file.
// For the row being scanned it is a reference; for the sibling it is that row
// talking about itself, which never was one. Same fetched row, two answers —
// which is what "the group shares the reads, never the verdict" has to mean.
$sharedCandidate = (object) [
    'post_id' => (string) FIXTURE_SIBLING_ID,
    'meta_key' => 'gallery_caption',
    'meta_value' => 'Cover shot: hero-300x200.jpg',
    'post_type' => 'attachment',
    'post_parent' => '0',
    'post_status' => 'inherit',
];

$postmeta = new PostmetaDetector();

$GLOBALS['wpdb']->rows = [$sharedCandidate];
$GLOBALS['wpdb']->calls = 0;

$ownRefs = $postmeta->find($ownCtx);
$groupBound = $GLOBALS['wpdb']->lastParams;
$siblingRefs = $postmeta->find($siblingCtx);

$GLOBALS['wpdb']->rows = [];

check('the group pays for one broad pass, not one per row', $GLOBALS['wpdb']->calls, 1);
check('and that pass binds the sibling id as well as its own', in_array('%"' . FIXTURE_SIBLING_ID . '"%', $groupBound, true), true);
check('and its own', in_array('%"' . FIXTURE_ID . '"%', $groupBound, true), true);
check("a sibling's caption naming the file is a reference", array_map(static fn($r): int => $r->objectId, $ownRefs), [FIXTURE_SIBLING_ID]);
check('and it counts as used', $ownRefs[0]->countsAsUsed(), true);
check('while the sibling does not reference itself', $siblingRefs, []);

// Which is the whole of Acceptance: one shared read, two different verdicts.
check('so one shared read leaves the two rows on different verdicts', [$ownRefs !== [], $siblingRefs !== []], [true, false]);

// A read that did not answer. It refuses once and goes on refusing: a cached
// QueryFailed that decayed into a cached empty set would tell nine rows out of
// ten that nothing references them, which is exactly the silent "unused"
// freshet-141 exists to prevent.
SharedReads::close();
SharedReads::open($groupIds);

$GLOBALS['wpdb']->failWith = 'MySQL server has gone away';
$GLOBALS['wpdb']->calls = 0;

$firstRefusal = null;
$secondRefusal = null;
$secondResult = 'not reached';

try {
    $postmeta->find($ownCtx);
} catch (QueryFailed $e) {
    $firstRefusal = $e;
}

try {
    $secondResult = $postmeta->find($siblingCtx);
} catch (QueryFailed $e) {
    $secondRefusal = $e;
}

$GLOBALS['wpdb']->failWith = '';
$GLOBALS['wpdb']->last_error = '';
SharedReads::close();

check('a shared read that did not answer refuses', $firstRefusal instanceof QueryFailed, true);
check('and refuses for every row behind it', $secondRefusal instanceof QueryFailed, true);
check('rather than handing the row behind it an empty result', $secondResult, 'not reached');
check('it is the same refusal, not a second one guessed at', $secondRefusal === $firstRefusal, true);
check('so a failed pass is not re-issued once per row either', $GLOBALS['wpdb']->calls, 1);

// Closed, the group is gone: a row scanned afterwards asks for itself, which is
// what every caller outside the delete path does and what the whole plugin did
// before this. The memo cannot outlive the group it was read for.
$loneCtx = AttachmentContext::forAttachment(FIXTURE_ID);

check('a closed group leaves the row asking for itself', [$loneCtx->shared, $loneCtx->queryIds], [false, [FIXTURE_ID]]);
check('and with its own basenames, not the union', in_array(FIXTURE_SIBLING_SIZE, $loneCtx->queryBasenames, true), false);

// A group past the bound is left alone rather than asked for in one enormous
// statement. Nothing is opened, so every row queries for itself exactly as it
// did before — the fallback needs no reading of its own.
SharedReads::open(range(1, SharedReads::MAX_ROWS + 1));

check('a group past the bound opens nothing', SharedReads::isOpen(), false);

SharedReads::open([FIXTURE_ID]);

check('and neither does a group of one, which has nothing to share', SharedReads::isOpen(), false);

SharedReads::close();

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

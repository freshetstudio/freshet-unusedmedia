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

// --------------------------------------------------------------- WP stubs

/**
 * Fixture attachments: id 123, hero.jpg with a -scaled original, two sizes and
 * a WebP source; id 124, a non-ASCII filename with no sizes.
 */
const FIXTURE_ID = 123;
const FIXTURE_UNICODE_ID = 124;
const FIXTURE_UNICODE_NAME = '写真.jpg';

function wp_basename(string $path): string
{
    return basename(str_replace('\\', '/', $path));
}

function get_post_meta(int $id, string $key, bool $single = false): string
{
    if ($key !== '_wp_attached_file') {
        return '';
    }

    return match ($id) {
        FIXTURE_ID => '2026/07/hero-scaled.jpg',
        FIXTURE_UNICODE_ID => '2026/07/' . FIXTURE_UNICODE_NAME,
        default => '',
    };
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
    return $id === FIXTURE_ID
        ? [
            'original_image' => 'hero.jpg',
            'sizes' => [
                'medium' => ['file' => 'hero-300x200.jpg', 'sources' => ['image/webp' => ['file' => 'hero-300x200.webp']]],
                'thumbnail' => ['file' => 'hero-150x150.jpg'],
            ],
        ]
        : false;
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
        return $this->rows;
    }
};

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

// -------------------------------------------------- hasSerializedString

check('serialized string', LikePatterns::hasSerializedString('a:1:{s:5:"image";s:3:"123";}', $id), true);
check('serialized longer string', LikePatterns::hasSerializedString('s:4:"1234"', $id), false);
check('serialized prefixed string', LikePatterns::hasSerializedString('s:4:"9123"', $id), false);
check('length prefix must agree', LikePatterns::hasSerializedString('s:6:"123456"', $id), false);

// -------------------------------------------------- hasJsonId

check('json id', LikePatterns::hasJsonId('{"id":123}', $id), true);
check('json id, spaced', LikePatterns::hasJsonId('{"id": 123}', $id), true);
check('json id as string', LikePatterns::hasJsonId('{"id":"123"}', $id), true);
check('json longer id', LikePatterns::hasJsonId('{"id":1234}', $id), false);
check('json longer id as string', LikePatterns::hasJsonId('{"id":"1234"}', $id), false);
check('json suffixed key is not id', LikePatterns::hasJsonId('{"media_id":123}', $id), false);
check('json ids array is not a bare id', LikePatterns::hasJsonId('{"ids":[123]}', $id), false);

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

check('wp-att in a rel attribute', LikePatterns::hasAttachmentLinkId('<a href="/hero" rel="attachment wp-att-123">hero</a>', $id), true);
check('wp-att in a class attribute', LikePatterns::hasAttachmentLinkId('<a class="link wp-att-123" href="/hero">hero</a>', $id), true);
check('wp-att, longer id', LikePatterns::hasAttachmentLinkId('<a rel="attachment wp-att-1234">x</a>', $id), false);
check('wp-att, prefixed id', LikePatterns::hasAttachmentLinkId('<a rel="attachment wp-att-9123">x</a>', $id), false);
check('wp-image is not wp-att', LikePatterns::hasAttachmentLinkId('<img class="wp-image-123">', $id), false);

check('data-id attribute', LikePatterns::hasDataId('<figure data-id="123"></figure>', $id), true);
check('data-id, single quoted', LikePatterns::hasDataId("<figure data-id='123'></figure>", $id), true);
check('data-id, unquoted', LikePatterns::hasDataId('<figure data-id=123></figure>', $id), true);
check('data-id, longer id', LikePatterns::hasDataId('<figure data-id="1234"></figure>', $id), false);
check('data-id, prefixed id', LikePatterns::hasDataId('<figure data-id="9123"></figure>', $id), false);
check('data-id, unquoted longer id', LikePatterns::hasDataId('<figure data-id=1234></figure>', $id), false);
check('data-id, id inside a list', LikePatterns::hasDataId('<figure data-id="4,123,9"></figure>', $id), false);
check('another data attribute is not data-id', LikePatterns::hasDataId('<figure data-slide-id="123"></figure>', $id), false);

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
check('id conditions cover every verifier', count($conditions), 11);
check('exact condition is bare', $params[0], '123');
check('comma-start pattern', $params[1], '123,%');
check('serialized int pattern is wildcarded', $params[4], '%i:123;%');
check('json string value pattern', $params[8], '%"123"%');
check('json number value patterns', [$params[9], $params[10]], ['%:123,%', '%:123}%']);

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
check('block attribute', $match('<!-- wp:image {"id":123,"sizeSlug":"large"} -->'), 'block-id');
check('block attribute, longer id', $match('<!-- wp:image {"id":1234,"sizeSlug":"large"} -->'), null);
check('gallery block', $match('<!-- wp:gallery {"ids":[4,123,9],"linkTo":"none"} -->'), 'gallery');
check('gallery block, single item', $match('<!-- wp:gallery {"ids":[123]} -->'), 'gallery');
check('gallery block, longer id', $match('<!-- wp:gallery {"ids":[4,1234,9]} -->'), null);
check('gallery shortcode', $match('[gallery ids="4,123,9"]'), 'gallery');
check('gallery shortcode, single quotes', $match("[gallery columns=\"2\" ids='123']"), 'gallery');
check('gallery shortcode, longer id', $match('[gallery ids="4,1234,9"]'), null);
check('resized URL in content', $match('<a href="https://example.test/wp-content/uploads/2026/07/hero-300x200.jpg">file</a>'), 'url');
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

// The rewritten query: every placeholder bound, autosave filter first, own ID excluded.
$detector->find($ctx);
$sql = $GLOBALS['wpdb']->lastSql;
$bound = $GLOBALS['wpdb']->lastParams;
check('post_content query placeholders match params', substr_count($sql, '%s') + substr_count($sql, '%d'), count($bound));
check('post_content query first binds the autosave name', $bound[0], '%-autosave-v1');
check('post_content query second binds the own id', $bound[1], 123);
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

// The other half of the detector is out of scope on purpose: comment_content is
// queried on basenames only, so it binds no id condition and therefore has no
// unverified one. Asserted rather than assumed — if that ever changes, this
// fails and the chain has to answer for it.
$inContent = new ReflectionMethod(CommentDetector::class, 'inContent');
$inContent->setAccessible(true);
$inContent->invoke($commentDetector, $ctx);
$contentBound = $GLOBALS['wpdb']->lastParams;

check('comment_content binds basenames and nothing else', count($contentBound), count($names));
check('comment_content never fetches a quoted id', $admits($contentBound, $quotedShortcode), false);

// --- postmeta

$GLOBALS['wpdb']->rows = [
    (object) ['post_id' => '31', 'meta_key' => 'intro_html', 'meta_value' => $quotedMarkup, 'post_status' => 'publish'],
    (object) ['post_id' => '32', 'meta_key' => 'intro_html', 'meta_value' => $longerId, 'post_status' => 'publish'],
    (object) ['post_id' => '33', 'meta_key' => 'intro_html', 'meta_value' => $prefixedId, 'post_status' => 'publish'],
    (object) ['post_id' => '34', 'meta_key' => 'settings', 'meta_value' => '{"hero":{"image":123}}', 'post_status' => 'publish'],
];

$postMetaRefs = (new PostmetaDetector())->find($ctx);
$postMetaBound = $GLOBALS['wpdb']->lastParams;
$GLOBALS['wpdb']->rows = [];

check('a quoted id in postmeta resolves, a longer or prefixed one does not', count($postMetaRefs), 2);
check('the postmeta quoted id is labelled as markup', $postMetaRefs[0]->match, 'id-attribute');
check('the postmeta reference points at its post', $postMetaRefs[0]->objectId, 31);
check('a postmeta value that decodes keeps its serialized verdict', $postMetaRefs[1]->match, 'serialized');
check('the postmeta query admits a quoted id in markup', $admits($postMetaBound, $quotedMarkup), true);

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

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

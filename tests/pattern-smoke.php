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

define('ABSPATH', dirname(__DIR__) . '/');

// --------------------------------------------------------------- WP stubs

/** Fixture attachment: id 123, hero.jpg with a -scaled original and one size. */
const FIXTURE_ID = 123;

function wp_basename(string $path): string
{
    return basename(str_replace('\\', '/', $path));
}

function get_post_meta(int $id, string $key, bool $single = false): string
{
    return $id === FIXTURE_ID && $key === '_wp_attached_file' ? '2026/07/hero-scaled.jpg' : '';
}

function wp_get_attachment_metadata(int $id): array|false
{
    return $id === FIXTURE_ID
        ? [
            'original_image' => 'hero.jpg',
            'sizes' => [
                'medium' => ['file' => 'hero-300x200.jpg'],
                'thumbnail' => ['file' => 'hero-150x150.jpg'],
            ],
        ]
        : false;
}

function get_post(int $id): ?object
{
    return $id === FIXTURE_ID ? (object) ['post_parent' => 7] : null;
}

/** Minimal $wpdb: only esc_like is reached by the query builders. */
$GLOBALS['wpdb'] = new class {
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }
};

require_once ABSPATH . 'src/Scan/AttachmentContext.php';
require_once ABSPATH . 'src/Scan/Reference.php';
require_once ABSPATH . 'src/Detector/DetectorInterface.php';
require_once ABSPATH . 'src/Detector/LikePatterns.php';
require_once ABSPATH . 'src/Detector/PostContentDetector.php';

use FreshetUnusedMedia\Detector\LikePatterns;
use FreshetUnusedMedia\Detector\PostContentDetector;
use FreshetUnusedMedia\Scan\AttachmentContext;

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
check('basenames deduplicated', count($names), count(array_unique($names)));

// -------------------------------------------------- containsBasename

check('basename in raw URL', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/hero-300x200.jpg">', $names), true);
check('unrelated filename', LikePatterns::containsBasename('<img src="/wp-content/uploads/2026/07/other.jpg">', $names), false);
check('empty basename never matches', LikePatterns::containsBasename('anything at all', ['']), false);
check('no basenames at all', LikePatterns::containsBasename('anything at all', []), false);

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

// -------------------------------------------------- query builders

[$conditions, $params] = LikePatterns::idConditions('pm.meta_value', $id);
check('id conditions and params align', count($conditions) === count($params), true);
check('id conditions cover every verifier', count($conditions), 8);
check('exact condition is bare', $params[0], '123');
check('comma-start pattern', $params[1], '123,%');
check('serialized int pattern is wildcarded', $params[4], '%i:123;%');

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

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

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

/**
 * Minimal $wpdb: esc_like for the query builders, and a recording prepare()
 * so a detector's SQL placeholders can be checked against its params.
 */
$GLOBALS['wpdb'] = new class {
    public string $posts = 'wp_posts';
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

    public function get_results(string $sql): array
    {
        return [];
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
check('namespaced block without data is not searched', $match('<!-- wp:vendor/spacer {"height":123,"unit":"px"} /-->'), null);
check('core block attrs are not data', $match('<!-- wp:spacer {"height":123} --><div style="height:123px"></div><!-- /wp:spacer -->'), null);
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

// The rewritten query: every placeholder bound, autosave filter first, own ID excluded.
$detector->find($ctx);
$sql = $GLOBALS['wpdb']->lastSql;
$bound = $GLOBALS['wpdb']->lastParams;
check('post_content query placeholders match params', substr_count($sql, '%s') + substr_count($sql, '%d'), count($bound));
check('post_content query first binds the autosave name', $bound[0], '%-autosave-v1');
check('post_content query second binds the own id', $bound[1], 123);
check('post_content query reaches the excerpt', str_contains($sql, 'p.post_excerpt LIKE'), true);
check('post_content query admits autosave revisions only', str_contains($sql, "(p.post_type <> 'revision' OR p.post_name LIKE %s)"), true);

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

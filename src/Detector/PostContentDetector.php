<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in post_content (and post_excerpt): block attributes
 * (wp:image/wp:gallery), wp-image-N classes, field values stored in block
 * delimiters (ACF blocks and the like), shortcode attributes ([gallery],
 * [playlist], page-builder shortcodes) and raw URLs (including resized
 * variants like foo-300x200.jpg).
 */
final class PostContentDetector implements DetectorInterface
{
    /** Shortcode attributes whose numeric values are dimensions or counts, never IDs. */
    private const NON_ID_ATTRIBUTES = [
        'width', 'height', 'columns', 'cols', 'rows', 'size', 'count', 'limit', 'number', 'num',
        'per_page', 'posts_per_page', 'offset', 'page', 'paged', 'order', 'orderby',
        'speed', 'delay', 'interval', 'duration', 'autoplay', 'loop', 'start', 'end',
        'max', 'min', 'step', 'level', 'gap', 'margin', 'padding', 'spacing', 'radius',
        'opacity', 'zoom', 'year', 'month', 'day',
    ];

    public function id(): string
    {
        return 'post-content';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        $id = $ctx->id;

        // Needles are ID-specific so we never fetch every gallery or block post
        // on the site; PHP verification makes the final call.
        $needles = [
            'wp-image-' . $id,    // editor image class
            '"id":' . $id,        // block attribute
            '"' . $id . '"',      // JSON string value / quoted shortcode attribute
            "'" . $id . "'",      // single-quoted shortcode attribute
            '"' . $id . ',',      // quoted list start
            ',' . $id . '"',      // quoted list end
            "'" . $id . ',',      // single-quoted list start
            ',' . $id . "'",      // single-quoted list end
            '=' . $id,            // unquoted shortcode attribute
            ':' . $id . ',',      // JSON number value
            ':' . $id . '}',      // JSON number value, last key
            ',' . $id . ',',      // list middle (shortcode or JSON)
            '[' . $id . ',',      // JSON array start
            ',' . $id . ']',      // JSON array end
            '[' . $id . ']',      // JSON single-item array
        ];

        $conditions = [];
        $params = [];

        foreach ($needles as $needle) {
            $conditions[] = 'p.post_content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($needle) . '%';
        }

        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('p.post_content', $ctx->basenames);
        // An image in an excerpt always carries its URL, so basenames suffice there.
        [$excerptConditions, $excerptParams] = LikePatterns::basenameConditions('p.post_excerpt', $ctx->basenames);

        $conditions = array_merge($conditions, $nameConditions, $excerptConditions);
        $params = array_merge($params, $nameParams, $excerptParams);

        // Revisions are skipped — a reference in an old version is not a use —
        // except autosaves, which hold edits in flight that are not saved yet.
        $sql = "SELECT p.ID, p.post_parent, p.post_type, p.post_status, p.post_content, p.post_excerpt
                FROM {$wpdb->posts} p
                WHERE p.post_type NOT IN ('attachment', 'nav_menu_item')
                  AND (p.post_type <> 'revision' OR p.post_name LIKE %s)
                  AND p.post_status <> 'auto-draft'
                  AND p.ID <> %d
                  AND (" . implode(' OR ', $conditions) . ')';

        $params = array_merge(['%' . $wpdb->esc_like('-autosave-v1'), $id], $params);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $refs = [];

        foreach ((array) $rows as $row) {
            $match = $this->verify((string) $row->post_content . "\n" . (string) $row->post_excerpt, $ctx);

            if ($match === null) {
                continue;
            }

            $autosave = $row->post_type === 'revision';

            $refs[] = new Reference(
                detector: 'post-content',
                objectType: 'post',
                objectId: $autosave ? (int) $row->post_parent : (int) $row->ID,
                detail: $autosave ? 'autosave' : $match,
                match: $autosave ? 'autosave' : $match,
                confidence: $autosave || $row->post_status === 'trash' ? Reference::POSSIBLE : Reference::CONFIRMED,
            );
        }

        return $refs;
    }

    /** Precise boundary-checked verification; returns the match type or null. */
    private function verify(string $content, AttachmentContext $ctx): ?string
    {
        $id = $ctx->id;

        if (LikePatterns::containsBasename($content, $ctx->basenames)) {
            return 'url';
        }

        if (preg_match('/wp-image-' . $id . '(?!\d)/', $content)) {
            return 'wp-image-class';
        }

        if (LikePatterns::hasJsonId($content, $id)) {
            return 'block-id';
        }

        // wp:gallery block: "ids":[4,123,9]
        if (preg_match('/"ids":\s*\[[^\]]*(?<!\d)' . $id . '(?!\d)[^\]]*\]/', $content)) {
            return 'gallery';
        }

        if (self::blockDataContains($content, $id, $ctx->basenames)) {
            return 'acf-block';
        }

        // Classic [gallery ids="4,123,9"] shortcode.
        if (preg_match('/\[gallery[^\]]*ids=["\'][^"\']*(?<!\d)' . $id . '(?!\d)[^"\']*["\']/', $content)) {
            return 'gallery';
        }

        if (self::shortcodeAttributeContains($content, $id)) {
            return 'shortcode';
        }

        return null;
    }

    /**
     * Field-framework blocks (ACF blocks and the plugins built on the same
     * model) keep their values as a "data" object in the block delimiter —
     * IDs only, no URL, and no rendered HTML is ever saved. Only namespaced
     * blocks carry one; the delimiter JSON may span lines.
     *
     * @param string[] $basenames
     */
    private static function blockDataContains(string $content, int $id, array $basenames): bool
    {
        if (!preg_match_all('/<!--\s+wp:[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\s+(\{[\s\S]+?\})\s+\/?-->/', $content, $matches)) {
            return false;
        }

        foreach ($matches[1] as $json) {
            $attrs = json_decode($json, true);

            if (!is_array($attrs)) {
                // Undecodable delimiter: a digit-bounded ID is enough to keep the file.
                if (preg_match('/(?<![\d.])' . $id . '(?![\d.])/', $json)) {
                    return true;
                }

                continue;
            }

            if (isset($attrs['data']) && LikePatterns::structureContains($attrs['data'], $id, $basenames)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Any shortcode attribute whose value is the ID, or a comma list holding
     * it — [vc_single_image image="123"], [playlist ids="4,123"]. Attributes
     * that only ever carry dimensions or counts are ignored.
     */
    private static function shortcodeAttributeContains(string $content, int $id): bool
    {
        $attribute = '[a-zA-Z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]\/]+)';

        if (!preg_match_all('/\[[a-zA-Z0-9_-]+((?:\s+' . $attribute . ')+)\s*\/?\]/', $content, $shortcodes)) {
            return false;
        }

        foreach ($shortcodes[1] as $attributes) {
            preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]\/]+))/', $attributes, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                if (in_array(strtolower($pair[1]), self::NON_ID_ATTRIBUTES, true)) {
                    continue;
                }

                $value = $pair[4] ?? '';

                if (($pair[2] ?? '') !== '') {
                    $value = $pair[2];
                } elseif (($pair[3] ?? '') !== '') {
                    $value = $pair[3];
                }

                if (LikePatterns::inCommaList($value, $id)) {
                    return true;
                }
            }
        }

        return false;
    }
}

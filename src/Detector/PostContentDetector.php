<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in post_content: block attributes (wp:image/wp:gallery),
 * wp-image-N classes, [gallery] shortcodes and raw URLs (including resized
 * variants like foo-300x200.jpg).
 */
final class PostContentDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'post-content';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        $like = $wpdb->esc_like((string) $ctx->id);

        // Gallery membership needles are ID-specific so we never fetch every
        // gallery post on the site; PHP verification makes the final call.
        $galleryNeedles = [
            'ids="' . $ctx->id, // shortcode list start
            ',' . $ctx->id . ',', // list middle (shortcode or JSON)
            ',' . $ctx->id . '"', // shortcode list end
            '[' . $ctx->id . ',', // JSON array start
            ',' . $ctx->id . ']', // JSON array end
            '[' . $ctx->id . ']', // JSON single-item array
        ];

        $conditions = [
            'p.post_content LIKE %s', // wp-image-123
            'p.post_content LIKE %s', // "id":123 (block attrs)
        ];

        $params = [
            '%' . $wpdb->esc_like('wp-image-' . $ctx->id) . '%',
            '%' . $wpdb->esc_like('"id":' . $ctx->id) . '%',
        ];

        foreach ($galleryNeedles as $needle) {
            $conditions[] = 'p.post_content LIKE %s';
            $params[] = '%' . $wpdb->esc_like($needle) . '%';
        }

        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('p.post_content', $ctx->basenames);

        $conditions = array_merge($conditions, $nameConditions);
        $params = array_merge($params, $nameParams);

        $sql = "SELECT p.ID, p.post_status, p.post_content
                FROM {$wpdb->posts} p
                WHERE p.post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
                  AND p.post_status <> 'auto-draft'
                  AND p.ID <> %d
                  AND (" . implode(' OR ', $conditions) . ')';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge([$ctx->id], $params)));

        $refs = [];

        foreach ((array) $rows as $row) {
            $match = $this->verify((string) $row->post_content, $ctx);

            if ($match === null) {
                continue;
            }

            $refs[] = new Reference(
                detector: 'post-content',
                objectType: 'post',
                objectId: (int) $row->ID,
                detail: $match,
                match: $match,
                confidence: $row->post_status === 'trash' ? Reference::POSSIBLE : Reference::CONFIRMED,
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

        // Classic [gallery ids="4,123,9"] shortcode.
        if (preg_match('/\[gallery[^\]]*ids=["\'][^"\']*(?<!\d)' . $id . '(?!\d)[^"\']*["\']/', $content)) {
            return 'gallery';
        }

        return null;
    }
}

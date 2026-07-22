<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in postmeta: ACF fields (plain, serialized, repeater
 * sub-fields), featured images, WooCommerce galleries, Elementor data and
 * any other meta storing the ID or a file URL.
 */
final class PostmetaDetector implements DetectorInterface
{
    private const EXCLUDED_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_wp_attachment_image_alt',
        '_wp_old_slug',
        '_wp_old_date',
        '_edit_lock',
        '_edit_last',
    ];

    public function id(): string
    {
        return 'postmeta';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('pm.meta_value', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('pm.meta_value', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);
        $keyPlaceholders = implode(',', array_fill(0, count(self::EXCLUDED_KEYS), '%s'));

        $sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_status
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type <> 'revision'
                  AND pm.post_id <> %d
                  AND pm.meta_key NOT LIKE %s
                  AND pm.meta_key NOT IN ({$keyPlaceholders})
                  AND (" . implode(' OR ', $conditions) . ')';

        $params = array_merge(
            [$ctx->id, $wpdb->esc_like('_freshet_unusedmedia_') . '%'],
            self::EXCLUDED_KEYS,
            $idParams,
            $nameParams
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $refs = [];

        foreach ((array) $rows as $row) {
            $ref = $this->classify($ctx, (int) $row->post_id, (string) $row->meta_key, (string) $row->meta_value, (string) $row->post_status);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    private function classify(AttachmentContext $ctx, int $postId, string $key, string $value, string $postStatus): ?Reference
    {
        $make = static fn(string $match, string $confidence): Reference => new Reference(
            detector: 'postmeta',
            objectType: 'post',
            objectId: $postId,
            detail: $key,
            match: $match,
            // Trashed posts are restorable — keep them blocking, but flag as possible.
            confidence: $postStatus === 'trash' && $confidence === Reference::CONFIRMED ? Reference::POSSIBLE : $confidence,
        );

        // Known core/plugin keys first.
        if ($key === '_thumbnail_id' && LikePatterns::isExactId($value, $ctx->id)) {
            return $make('thumbnail', Reference::CONFIRMED);
        }

        if ($key === '_product_image_gallery' && LikePatterns::inCommaList($value, $ctx->id)) {
            return $make('woo-gallery', Reference::CONFIRMED);
        }

        if ($key === '_elementor_data') {
            if (LikePatterns::hasJsonId($value, $ctx->id) || LikePatterns::containsBasename($value, $ctx->basenames)) {
                return $make('elementor', Reference::CONFIRMED);
            }

            return null;
        }

        // A size-variant filename in any meta is an unambiguous reference.
        if (LikePatterns::containsBasename($value, $ctx->basenames)) {
            return $make('url', Reference::CONFIRMED);
        }

        $idMatch = match (true) {
            LikePatterns::isExactId($value, $ctx->id) => 'exact',
            LikePatterns::inCommaList($value, $ctx->id) => 'comma-list',
            LikePatterns::hasSerializedString($value, $ctx->id),
            LikePatterns::hasSerializedInt($value, $ctx->id) => 'serialized',
            LikePatterns::hasJsonId($value, $ctx->id) => 'block-id',
            default => null,
        };

        if ($idMatch === null) {
            return null; // LIKE candidate failed precise verification (e.g. id 1234 vs 123).
        }

        // ACF stores a sibling '_<key>' meta holding the field key — that
        // confirms the value really is this field's data (covers image,
        // gallery, file and repeater sub-fields like hero_0_image).
        if (!str_starts_with($key, '_')) {
            $sibling = (string) get_post_meta($postId, '_' . $key, true);

            if (str_starts_with($sibling, 'field_')) {
                return $make('acf', Reference::CONFIRMED);
            }
        }

        return $make($idMatch, Reference::POSSIBLE);
    }
}

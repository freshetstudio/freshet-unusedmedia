<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in usermeta (e.g. ACF image fields on user profiles,
 * custom avatars).
 */
final class UserMetaDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'usermeta';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('um.meta_value', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('um.meta_value', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);

        $sql = "SELECT um.user_id, um.meta_key, um.meta_value
                FROM {$wpdb->usermeta} um
                WHERE um.meta_key <> 'session_tokens'
                  AND um.meta_key NOT LIKE %s
                  AND um.meta_key NOT LIKE %s
                  AND um.meta_key NOT LIKE %s
                  AND (" . implode(' OR ', $conditions) . ')';

        $params = array_merge(
            [
                '%' . $wpdb->esc_like('capabilities'),
                '%' . $wpdb->esc_like('user_level'),
                '%' . $wpdb->esc_like('user-settings') . '%',
            ],
            $idParams,
            $nameParams
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $refs = [];

        foreach ((array) $rows as $row) {
            $userId = (int) $row->user_id;
            $key = (string) $row->meta_key;
            $value = (string) $row->meta_value;

            $make = static fn(string $match, string $confidence): Reference => new Reference(
                detector: 'usermeta',
                objectType: 'user',
                objectId: $userId,
                detail: $key,
                match: $match,
                confidence: $confidence,
            );

            if (LikePatterns::containsBasename($value, $ctx->basenames)) {
                $refs[] = $make('url', Reference::CONFIRMED);
                continue;
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
                continue;
            }

            // ACF sibling meta ('_<key>' = field_…) confirms the reference.
            if (!str_starts_with($key, '_') && str_starts_with((string) get_user_meta($userId, '_' . $key, true), 'field_')) {
                $refs[] = $make('acf', Reference::CONFIRMED);
                continue;
            }

            $refs[] = $make($idMatch, Reference::POSSIBLE);
        }

        return $refs;
    }
}

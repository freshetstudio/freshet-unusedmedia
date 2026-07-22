<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in termmeta (e.g. ACF image fields on categories/terms).
 */
final class TermMetaDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'termmeta';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('tm.meta_value', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('tm.meta_value', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);

        $sql = "SELECT tm.term_id, tm.meta_key, tm.meta_value
                FROM {$wpdb->termmeta} tm
                WHERE (" . implode(' OR ', $conditions) . ')';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($idParams, $nameParams)));

        $refs = [];

        foreach ((array) $rows as $row) {
            $termId = (int) $row->term_id;
            $key = (string) $row->meta_key;
            $value = (string) $row->meta_value;

            $make = static fn(string $match, string $confidence): Reference => new Reference(
                detector: 'termmeta',
                objectType: 'term',
                objectId: $termId,
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
            if (!str_starts_with($key, '_') && str_starts_with((string) get_term_meta($termId, '_' . $key, true), 'field_')) {
                $refs[] = $make('acf', Reference::CONFIRMED);
                continue;
            }

            $refs[] = $make($idMatch, Reference::POSSIBLE);
        }

        return $refs;
    }
}

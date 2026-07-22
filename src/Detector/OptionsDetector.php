<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in wp_options: site icon, custom logo and other theme
 * mods, widgets, and any option storing the ID or a file URL.
 */
final class OptionsDetector implements DetectorInterface
{
    private const EXCLUDED_OPTIONS = [
        'cron',
        'freshet_unusedmedia_scan',
        'freshet_unusedmedia_last_scan',
        'freshet_unusedmedia_content_changed_at',
        'rewrite_rules',
        'wp_user_roles',
        'active_plugins',
        'recently_edited',
        'uninstall_plugins',
    ];

    public function id(): string
    {
        return 'options';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('o.option_value', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('o.option_value', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);
        $optionPlaceholders = implode(',', array_fill(0, count(self::EXCLUDED_OPTIONS), '%s'));

        $sql = "SELECT o.option_name, o.option_value
                FROM {$wpdb->options} o
                WHERE o.option_name NOT LIKE %s
                  AND o.option_name NOT LIKE %s
                  AND o.option_name NOT IN ({$optionPlaceholders})
                  AND (" . implode(' OR ', $conditions) . ')';

        $params = array_merge(
            [$wpdb->esc_like('_transient_') . '%', $wpdb->esc_like('_site_transient_') . '%'],
            self::EXCLUDED_OPTIONS,
            $idParams,
            $nameParams
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $refs = [];

        foreach ((array) $rows as $row) {
            $ref = $this->classify($ctx, (string) $row->option_name, (string) $row->option_value);

            if ($ref !== null) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    private function classify(AttachmentContext $ctx, string $name, string $value): ?Reference
    {
        $make = static fn(string $type, string $match, string $confidence): Reference => new Reference(
            detector: 'options',
            objectType: $type,
            objectId: 0,
            detail: $name,
            match: $match,
            confidence: $confidence,
        );

        // Core media options storing a bare attachment ID.
        if (in_array($name, ['site_icon', 'site_logo'], true) && LikePatterns::isExactId($value, $ctx->id)) {
            return $make('option', 'site-option', Reference::CONFIRMED);
        }

        // Theme mods (custom_logo, header/background images, …): verify in the
        // unserialized structure so array indexes never false-positive.
        if (str_starts_with($name, 'theme_mods_')) {
            $mods = maybe_unserialize($value);

            if (LikePatterns::structureContains($mods, $ctx->id, $ctx->basenames)) {
                return $make('theme_mod', 'theme-mod', Reference::CONFIRMED);
            }

            return null;
        }

        // Widgets: verified in unserialized data = confirmed; unverifiable = possible.
        if (str_starts_with($name, 'widget_')) {
            $data = maybe_unserialize($value);

            if (LikePatterns::structureContains($data, $ctx->id, $ctx->basenames)) {
                return $make('option', 'widget', Reference::CONFIRMED);
            }

            if (LikePatterns::hasSerializedInt($value, $ctx->id)) {
                return $make('option', 'widget', Reference::POSSIBLE);
            }

            return null;
        }

        // Generic options: a filename is unambiguous; a bare ID is only possible.
        if (LikePatterns::containsBasename($value, $ctx->basenames)) {
            return $make('option', 'url', Reference::CONFIRMED);
        }

        $serialized = maybe_unserialize($value);

        if (is_array($serialized) || is_object($serialized)) {
            return LikePatterns::structureContains($serialized, $ctx->id, $ctx->basenames)
                ? $make('option', 'serialized', Reference::POSSIBLE)
                : null;
        }

        if (LikePatterns::isExactId($value, $ctx->id) || LikePatterns::hasJsonId($value, $ctx->id)) {
            return $make('option', 'exact', Reference::POSSIBLE);
        }

        return null;
    }
}

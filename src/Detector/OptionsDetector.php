<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
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
        $rows = Db::rows($this->id(), $wpdb->get_results($wpdb->prepare($sql, ...$params)));

        $refs = [];

        foreach ($rows as $row) {
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

            // A mod holding markup rather than an ID — a footer blob, a custom
            // HTML mod — carries the attachment as a link to its own page or as
            // a quoted attribute, neither of which the walk above can see.
            // Possible, not confirmed: the structure did not resolve it, only
            // the raw value did.
            if (LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
                return $make('theme_mod', 'attachment-page', Reference::POSSIBLE);
            }

            if (LikePatterns::hasQuotedId($value, $ctx->id)) {
                return $make('theme_mod', 'id-attribute', Reference::POSSIBLE);
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

            // A text or custom-HTML widget stores markup, so the link, the
            // shortcode or the data- attribute naming the attachment survives
            // unserialization as an opaque string leaf. The raw value is where
            // it is still legible.
            if (LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
                return $make('option', 'attachment-page', Reference::POSSIBLE);
            }

            if (LikePatterns::hasQuotedId($value, $ctx->id)) {
                return $make('option', 'id-attribute', Reference::POSSIBLE);
            }

            return null;
        }

        // Generic options: a filename is unambiguous; a bare ID is only possible.
        if (LikePatterns::containsBasename($value, $ctx->basenames)) {
            return $make('option', 'url', Reference::CONFIRMED);
        }

        // Serialized or JSON settings blob: verify in the structure (JSON nested
        // in strings included) so array indexes never false-positive.
        $decoded = LikePatterns::decodeStored($value);

        if (is_array($decoded) || is_object($decoded)) {
            if (LikePatterns::structureContains($decoded, $ctx->id, $ctx->basenames)) {
                return $make('option', 'serialized', Reference::POSSIBLE);
            }
        } elseif (LikePatterns::isExactId($value, $ctx->id) || LikePatterns::hasJsonId($value, $ctx->id)) {
            return $make('option', 'exact', Reference::POSSIBLE);
        }

        // After every decode-based branch above: a link to the attachment's own
        // page, which is markup and so is invisible to the walk — an option
        // holding a rendered block of HTML, a stored notice, a page-builder
        // fragment. It is the two link conditions the query binds.
        if (LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
            return $make('option', 'attachment-page', Reference::POSSIBLE);
        }

        // Last: the ID as a quoted attribute the structure walk cannot see —
        // markup stored whole, or a string leaf inside a blob that unserializes
        // but does not resolve. It is the '%"123"%' condition the query binds
        // and nothing answered.
        if (LikePatterns::hasQuotedId($value, $ctx->id)) {
            return $make('option', 'id-attribute', Reference::POSSIBLE);
        }

        return null;
    }
}

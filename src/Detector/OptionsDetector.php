<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

defined('ABSPATH') || exit;

/**
 * Finds references in wp_options: site icon, custom logo and other theme
 * mods, widgets, and any option storing the ID or a file URL.
 */
final class OptionsDetector implements DetectorInterface
{
    /**
     * Core's housekeeping: options whose value is machinery rather than content,
     * so a number or a filename in one is never a reference.
     *
     * This plugin's own options are **not** listed here one by one any more —
     * they are excluded by prefix, see OWN_PREFIX.
     *
     * Public for the same reason EXCLUDED_KEYS is: the reference index walks
     * wp_options with this plugin's own filter rather than with a copy of it
     * (freshet-290) — through rowFilter(), which is what both readers call.
     */
    public const EXCLUDED_OPTIONS = [
        'cron',
        'rewrite_rules',
        'wp_user_roles',
        'active_plugins',
        'recently_edited',
        'uninstall_plugins',
    ];

    /**
     * Every option this plugin writes, by the prefix they all share.
     *
     * **A prefix and not a list, because the list was wrong.** It named the scan
     * cursor, the last-scan summary and the content-changed stamp, and missed
     * five: the **reference index cursor**, the reclaimed-bytes ledger, the
     * orphan-sizes record and two the plugin keeps for itself. The index cursor
     * is the one that cost something — `freshet-377`. It carries the running
     * reference total, the pass reads it as a corpus row, and
     * `hasSerializedInt()` reads `i:55564;` out of it as attachment 55564. So
     * how far the pass had got when it reached that row decided which file was
     * read as used, and `ReferenceIndexer::PAGE` decides how far it has got:
     * 55,941 references at a page of 2,000 and 55,942 at a page of 500 on the
     * same unchanged library, which also means a resumed pass and an
     * uninterrupted one were not the same reading of the corpus.
     *
     * The others are the same shape and were found by looking rather than by
     * being bitten: `ReclaimedLedger`'s `bytes` total is a free-roaming integer,
     * and `OrphanSizes` stores orphan size **filenames** — the basename half,
     * which confirms rather than guesses.
     *
     * The postmeta read has excluded this plugin's own rows by prefix since it
     * was written (`_freshet_unusedmedia_%`); this is the options read catching
     * up with it. An option added later is covered without anyone remembering
     * to add it, which is the point.
     */
    public const OWN_PREFIX = 'freshet_unusedmedia_';

    /**
     * The rows wp_options is never searched for, as one WHERE fragment.
     *
     * One builder for both callers. `find()` and the reference index must agree
     * about which rows exist — the index answers for the detector, so a row one
     * of them skips and the other reads is a verdict that depends on which path
     * asked. They used to carry a copy each of the transient conditions and the
     * exclusion list; this is the filter living in one place, which is the rule
     * `ReferenceIndexer::reads()` states for the rest of them.
     *
     * @param string $alias The wp_options alias in the caller's query.
     * @return array{0: string, 1: array<int, string>} Condition, and its params in order.
     */
    public static function rowFilter(string $alias): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::EXCLUDED_OPTIONS), '%s'));

        return [
            "{$alias}.option_name NOT LIKE %s
               AND {$alias}.option_name NOT LIKE %s
               AND {$alias}.option_name NOT LIKE %s
               AND {$alias}.option_name NOT IN ({$placeholders})",
            array_merge(
                [
                    $wpdb->esc_like('_transient_') . '%',
                    $wpdb->esc_like('_site_transient_') . '%',
                    // The prefix holds underscores, which are LIKE wildcards.
                    $wpdb->esc_like(self::OWN_PREFIX) . '%',
                ],
                self::EXCLUDED_OPTIONS
            ),
        ];
    }

    public function id(): string
    {
        return 'options';
    }

    public function find(AttachmentContext $ctx): array
    {
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides.
        $rows = SharedReads::candidates($this->id(), $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$idConditions, $idParams] = LikePatterns::anyIdConditions('o.option_value', $ids);
            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('o.option_value', $basenames);

            $conditions = array_merge($idConditions, $nameConditions);
            [$rowFilter, $rowParams] = self::rowFilter('o');

            $sql = "SELECT o.option_name, o.option_value
                    FROM {$wpdb->options} o
                    WHERE {$rowFilter}
                      AND (" . implode(' OR ', $conditions) . ')';

            $params = array_merge($rowParams, $idParams, $nameParams);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
            return Db::rows($this->id(), $wpdb->get_results($wpdb->prepare($sql, ...$params)));
        });

        $refs = [];

        foreach ($rows as $row) {
            foreach ($this->references($ctx, $row) as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * The references one candidate row holds for one attachment.
     *
     * Split out of find() so the reference index can run **this** chain rather
     * than a second copy of it (freshet-290). The index reads each row of the
     * table once and verifies every attachment the row could be naming right
     * there, so both paths produce their `Reference` from the same bytes: the
     * order of the predicates, the match labels and the confidences cannot drift
     * between a live scan and an indexed one, because there is nothing to drift
     * from. `LikePatterns::hasJsonIdKey()` being asked last is load-bearing
     * (freshet-291) and this is how it stays last in both.
     *
     * @param object $row A row of this detector's own broad pass, or the same
     *                    row read by the index's cursor over the same table.
     * @return Reference[]
     */
    public function references(AttachmentContext $ctx, object $row): array
    {
        $ref = $this->classify($ctx, (string) $row->option_name, (string) $row->option_value);

        return $ref === null ? [] : [$ref];
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

            // The raw `i:123;` token, and only where the walk above had nothing
            // to walk: in data that unserialized, that token is as often the
            // array key 123 as the value — see
            // LikePatterns::hasUndecodedSerializedInt().
            if (LikePatterns::hasUndecodedSerializedInt($value, $data, $ctx->id)) {
                return $make('option', 'widget', Reference::POSSIBLE);
            }

            // The class the editor writes on an image it inserted. A block
            // widget — WordPress's default widget storage since 5.8 — keeps its
            // content as markup inside the serialized array, and the walk above
            // reads the block delimiter in it; a widget whose delimiter is gone
            // (converted to Custom HTML, pasted into a Text widget) has only
            // this class left, and nothing below reads it. Confirmed rather than
            // possible: the editor writes wp-image-N on nothing but the image it
            // inserted.
            if (LikePatterns::hasImageClass($value, $ctx->id)) {
                return $make('option', 'wp-image-class', Reference::CONFIRMED);
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

        // A plain option value holding block markup — a stored template, a
        // page-builder fragment, a reusable-block payload parked in an option.
        // The decode above never sees it (the value is not itself JSON or
        // serialized, so the structure walk is not entered), and the bare
        // integer inside the delimiter's attribute JSON satisfies none of the
        // fall-throughs below. Every other carrier reaches the same parser
        // through the walk; this branch is the one that does not have a walk.
        if (LikePatterns::hasBlockAttribute($value, $ctx->id, $ctx->basenames)) {
            return $make('option', 'acf-block', Reference::POSSIBLE);
        }

        // A JSON key naming the attachment outside any delimiter, in an option
        // value that did not decode. After the two branches above, so anything
        // they resolve keeps the more specific verdict.
        if (LikePatterns::hasJsonIdKey($value, $ctx->id)) {
            return $make('option', 'block-id', Reference::POSSIBLE);
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

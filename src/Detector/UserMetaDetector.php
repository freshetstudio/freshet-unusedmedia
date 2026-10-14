<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

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
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides.
        $rows = SharedReads::candidates($this->id(), $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$idConditions, $idParams] = LikePatterns::anyIdConditions('um.meta_value', $ids);
            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('um.meta_value', $basenames);

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
            return [$make('url', Reference::CONFIRMED)];
        }

        // Decoded once; the walk, the serialized-int question and the branch
        // under this all read it.
        $decoded = LikePatterns::decodeStored($value);

        // A key whose integer is documented to be something else, still read for
        // the one thing such a record can name. See PostmetaDetector.
        if (NonMediaKeys::denies($key)) {
            return LikePatterns::namesLinkedAttachment($decoded, $ctx->id, $ctx->basenames)
                ? [$make('serialized', Reference::POSSIBLE)]
                : [];
        }

        $idMatch = match (true) {
            LikePatterns::isExactId($value, $ctx->id) => 'exact',
            LikePatterns::inCommaList($value, $ctx->id) => 'comma-list',
            LikePatterns::hasSerializedString($value, $ctx->id),
            LikePatterns::hasUndecodedSerializedInt($value, $decoded, $ctx->id) => 'serialized',
            LikePatterns::hasJsonId($value, $ctx->id) => 'block-id',
            default => null,
        };

        if ($idMatch === null && LikePatterns::structureContains($decoded, $ctx->id, $ctx->basenames)) {
            $idMatch = 'serialized'; // JSON blob or nested JSON in serialized data.
        }

        // A JSON key naming the attachment in a value that did not decode.
        // After the walk, so anything that decodes keeps its verdict.
        if ($idMatch === null && LikePatterns::hasJsonIdKey($value, $ctx->id)) {
            $idMatch = 'block-id';
        }

        // A link to the attachment's own page inside stored markup. Neither
        // branch above can see it: the walk gets an opaque string leaf, and
        // nothing quotes the id. It answers the two link conditions the query
        // binds.
        if ($idMatch === null && LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
            $idMatch = 'attachment-page';
        }

        // Last, so a value that decodes keeps its more specific verdict: the ID
        // as a quoted attribute in a value that is neither serialized nor JSON —
        // [gallery ids="123"], data-id="123" — which the broad pass fetches on
        // its '%"123"%' condition and nothing here answered.
        if ($idMatch === null && LikePatterns::hasQuotedId($value, $ctx->id)) {
            $idMatch = 'id-attribute';
        }

        if ($idMatch === null) {
            return [];
        }

        // ACF sibling meta ('_<key>' = field_…) says whose field the value is;
        // AcfField says whether that field holds an attachment. See
        // PostmetaDetector for the three answers.
        if (!str_starts_with($key, '_')) {
            $sibling = (string) get_user_meta($userId, '_' . $key, true);

            if (str_starts_with($sibling, 'field_')) {
                return match (AcfField::namesAttachment($sibling)) {
                    true => [$make('acf', Reference::CONFIRMED)],
                    false => [],
                    default => [$make('acf', Reference::POSSIBLE)],
                };
            }
        }

        return [$make($idMatch, Reference::POSSIBLE)];
    }
}

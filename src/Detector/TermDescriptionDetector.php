<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

defined('ABSPATH') || exit;

/**
 * Finds references in term descriptions — the description column of the term
 * taxonomy table. Category, tag and product-category descriptions carrying an
 * <img> are routine on a catalogue library, and nothing read that column
 * before this: such a file scanned unused and was offered for deletion.
 *
 * A detector of its own rather than a branch of TermMetaDetector, on three
 * counts: the description is in a different table, it is free content rather
 * than a stored meta value, and none of the meta detector's key-based
 * reasoning carries over — there is no ACF '_<key>' = field_… sibling to
 * confirm a match with, because a description has no key. What the two do
 * share is the object they point at, so this emits the same objectType 'term'
 * and the term_id, which every surface that lists a reference already resolves
 * to a term link.
 */
final class TermDescriptionDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'term-description';
    }

    public function find(AttachmentContext $ctx): array
    {
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides.
        $rows = SharedReads::candidates($this->id(), $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$idConditions, $idParams] = LikePatterns::anyIdConditions('tt.description', $ids);
            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('tt.description', $basenames);

            $conditions = array_merge($idConditions, $nameConditions);

            $sql = "SELECT tt.term_id, tt.description
                    FROM {$wpdb->term_taxonomy} tt
                    WHERE tt.description <> ''
                      AND (" . implode(' OR ', $conditions) . ')';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
            return Db::rows($this->id(), $wpdb->get_results($wpdb->prepare($sql, ...array_merge($idParams, $nameParams))));
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
        $match = $this->verify((string) $row->description, $ctx);

        if ($match === null) {
            return [];
        }

        return [new Reference(
            detector: 'term-description',
            objectType: 'term',
            objectId: (int) $row->term_id,
            detail: 'description',
            match: $match,
            // A file URL in the description is the reference; an ID in it is a
            // shape that usually is one, so it is kept as possible — which still
            // counts as used.
            confidence: $match === 'url' ? Reference::CONFIRMED : Reference::POSSIBLE,
        )];
    }

    /**
     * Precise boundary-checked verification; returns the match type or null.
     * Every branch answers one of the broad conditions above — a verifier the
     * SQL pass cannot reach is a verifier that never runs.
     */
    private function verify(string $description, AttachmentContext $ctx): ?string
    {
        if ($description === '') {
            return null;
        }

        if (LikePatterns::containsBasename($description, $ctx->basenames)) {
            return 'url';
        }

        $id = $ctx->id;

        // Decoded once; the walk and the serialized-int question share it.
        $decoded = LikePatterns::decodeStored($description);

        $match = match (true) {
            LikePatterns::isExactId($description, $id) => 'exact',
            LikePatterns::inCommaList($description, $id) => 'comma-list',
            LikePatterns::hasSerializedString($description, $id),
            LikePatterns::hasUndecodedSerializedInt($description, $decoded, $id) => 'serialized',
            LikePatterns::hasJsonId($description, $id) => 'block-id',
            LikePatterns::hasQuotedId($description, $id) => 'id-attribute',
            default => null,
        };

        if ($match !== null) {
            return $match;
        }

        // A description that is itself a stored structure — JSON, or serialized
        // data a plugin parked there — keeps its IDs under arbitrary keys.
        if (LikePatterns::structureContains($decoded, $id, $ctx->basenames)) {
            return 'serialized';
        }

        // A JSON key naming the attachment in a description that did not decode.
        // After the walk, so anything that decodes keeps its verdict.
        if (LikePatterns::hasJsonIdKey($description, $id)) {
            return 'block-id';
        }

        // A link to the file's own attachment page in the description's markup:
        // a category that links its spec sheet rather than showing an image.
        // The id is written as a query arg or inside a class name, so no branch
        // above admits it — these are the two link conditions the query binds.
        return LikePatterns::hasAttachmentPageLink($description, $id) ? 'attachment-page' : null;
    }
}

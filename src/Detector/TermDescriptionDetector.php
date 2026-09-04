<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

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
 * upgrade a match with, because a description has no key. What the two do
 * share is the object they point at, so this emits the same objectType 'term'
 * and the term_id, which the Evidence report and the attachment meta box
 * already resolve to a term link.
 */
final class TermDescriptionDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'term-description';
    }

    public function find(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('tt.description', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('tt.description', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);

        $sql = "SELECT tt.term_id, tt.description
                FROM {$wpdb->term_taxonomy} tt
                WHERE tt.description <> ''
                  AND (" . implode(' OR ', $conditions) . ')';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($idParams, $nameParams)));

        $refs = [];

        foreach ((array) $rows as $row) {
            $match = $this->verify((string) $row->description, $ctx);

            if ($match === null) {
                continue;
            }

            $refs[] = new Reference(
                detector: 'term-description',
                objectType: 'term',
                objectId: (int) $row->term_id,
                detail: 'description',
                match: $match,
                // A file URL in the description is the reference; an ID in it is
                // a shape that usually is one, so it is kept as possible — which
                // still counts as used.
                confidence: $match === 'url' ? Reference::CONFIRMED : Reference::POSSIBLE,
            );
        }

        return $refs;
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

        $match = match (true) {
            LikePatterns::isExactId($description, $id) => 'exact',
            LikePatterns::inCommaList($description, $id) => 'comma-list',
            LikePatterns::hasSerializedString($description, $id),
            LikePatterns::hasSerializedInt($description, $id) => 'serialized',
            LikePatterns::hasJsonId($description, $id) => 'block-id',
            LikePatterns::hasQuotedId($description, $id) => 'id-attribute',
            default => null,
        };

        if ($match !== null) {
            return $match;
        }

        // A description that is itself a stored structure — JSON, or serialized
        // data a plugin parked there — keeps its IDs under arbitrary keys.
        if (LikePatterns::structureContains(LikePatterns::decodeStored($description), $id, $ctx->basenames)) {
            return 'serialized';
        }

        // A link to the file's own attachment page in the description's markup:
        // a category that links its spec sheet rather than showing an image.
        // The id is written as a query arg or inside a class name, so no branch
        // above admits it — these are the two link conditions the query binds.
        return LikePatterns::hasAttachmentPageLink($description, $id) ? 'attachment-page' : null;
    }
}

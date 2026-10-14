<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

defined('ABSPATH') || exit;

/**
 * Finds references in comments: a file URL, the editor's image class, or a link
 * to the file's attachment page in a comment body, and IDs or URLs in
 * commentmeta (review photos, attachments on replies, ACF comment fields). Spam
 * is ignored; trashed comments are restorable, so they block deletion but only
 * as possible.
 */
final class CommentDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'comment';
    }

    public function find(AttachmentContext $ctx): array
    {
        return array_merge($this->inContent($ctx), $this->inMeta($ctx));
    }

    /**
     * comment_content: a file URL, the class the editor writes on an image it
     * has inserted, or a link to the file's own attachment page.
     *
     * The id shapes are still deliberately not bound here — a comment body is
     * prose, so a bare number in it is noise, and nothing in a sentence quotes,
     * brackets or serializes it. What IS bound are the three forms that are
     * markup rather than numbers, because a comment carries markup verbatim: the
     * file's own URL, the editor's `class="wp-image-123"` — which is not
     * something a commenter types but what a paste out of the editor produces —
     * and the attachment-page link a reply points at a document with. Each names
     * this attachment and nothing else, which is why all three are confirmed
     * here exactly as post_content confirms the identical markup.
     *
     * `[gallery ids="123,456"]` in a comment is deliberately NOT resolved. Every
     * needle that would fetch it is a quoted-id shape ('"123"', '"123,',
     * ',123"'), and binding one on this column is a widening measured and
     * declined (freshet-146): the existing predicates cannot reach the row
     * because no condition here admits it, so catching it means new SQL rather
     * than a new verifier.
     */
    private function inContent(AttachmentContext $ctx): array
    {
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides. Named
        // apart from the commentmeta read below: two tables, two passes.
        $rows = SharedReads::candidates($this->id() . ' (content)', $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('c.comment_content', $basenames);
            [$linkConditions, $linkParams] = LikePatterns::anyAttachmentLinkConditions('c.comment_content', $ids);
            [$classConditions, $classParams] = LikePatterns::anyImageClassConditions('c.comment_content', $ids);

            // The link and class conditions are always present, so — unlike before —
            // there is no attachment for which this query binds nothing.
            $conditions = array_merge($nameConditions, $linkConditions, $classConditions);
            $params = array_merge($nameParams, $linkParams, $classParams);

            $sql = "SELECT c.comment_ID, c.comment_approved, c.comment_content
                    FROM {$wpdb->comments} c
                    WHERE c.comment_approved <> 'spam'
                      AND (" . implode(' OR ', $conditions) . ')';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
            return Db::rows($this->id(), $wpdb->get_results($wpdb->prepare($sql, ...$params)));
        });

        $refs = [];

        foreach ($rows as $row) {
            foreach ($this->contentReferences($ctx, $row) as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * The references one candidate row of comment_content holds for one attachment.
     *
     * Split out of find() so the reference index can run **this** chain rather
     * than a second copy of it (freshet-290). The two reads of this detector get
     * one of these each, because they are two tables and two chains. Both paths
     * produce their `Reference` from the same bytes, so the match labels and the
     * confidences cannot drift between a live scan and an indexed one.
     *
     * @param object $row A row of the broad pass above, or the same row read by
     *                    the index's cursor over the same table.
     * @return Reference[]
     */
    public function contentReferences(AttachmentContext $ctx, object $row): array
    {
        $content = (string) $row->comment_content;

        $match = match (true) {
            LikePatterns::containsBasename($content, $ctx->basenames) => 'url',
            // The image the editor inserted, pasted into a comment with the
            // class it was written with. Same verifier as post_content, so the
            // digit boundary that keeps 1234 out of 123 is the same one.
            LikePatterns::hasImageClass($content, $ctx->id) => 'wp-image-class',
            // Confirmed like the URL, and for the same reason: the link names
            // this attachment and nothing else, which is how post_content reads
            // the identical markup.
            LikePatterns::hasAttachmentPageLink($content, $ctx->id) => 'attachment-page',
            default => null,
        };

        if ($match === null) {
            return [];
        }

        return [new Reference(
            detector: 'comment',
            objectType: 'comment',
            objectId: (int) $row->comment_ID,
            detail: 'comment_content',
            match: $match,
            confidence: $row->comment_approved === 'trash' ? Reference::POSSIBLE : Reference::CONFIRMED,
        )];
    }

    private function inMeta(AttachmentContext $ctx): array
    {
        $rows = SharedReads::candidates($this->id() . ' (meta)', $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$idConditions, $idParams] = LikePatterns::anyIdConditions('cm.meta_value', $ids);
            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('cm.meta_value', $basenames);

            $conditions = array_merge($idConditions, $nameConditions);

            $sql = "SELECT cm.comment_id, cm.meta_key, cm.meta_value, c.comment_approved
                    FROM {$wpdb->commentmeta} cm
                    INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                    WHERE c.comment_approved <> 'spam'
                      AND (" . implode(' OR ', $conditions) . ')';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
            return Db::rows($this->id() . ' (meta)', $wpdb->get_results($wpdb->prepare($sql, ...array_merge($idParams, $nameParams))));
        });

        $refs = [];

        foreach ($rows as $row) {
            foreach ($this->metaReferences($ctx, $row) as $ref) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * The references one candidate row of commentmeta holds for one attachment.
     *
     * Split out of find() so the reference index can run **this** chain rather
     * than a second copy of it (freshet-290). The two reads of this detector get
     * one of these each, because they are two tables and two chains. Both paths
     * produce their `Reference` from the same bytes, so the match labels and the
     * confidences cannot drift between a live scan and an indexed one.
     *
     * @param object $row A row of the broad pass above, or the same row read by
     *                    the index's cursor over the same table.
     * @return Reference[]
     */
    public function metaReferences(AttachmentContext $ctx, object $row): array
    {
        $commentId = (int) $row->comment_id;
        $key = (string) $row->meta_key;
        $value = (string) $row->meta_value;

        $make = static fn(string $match, string $confidence): Reference => new Reference(
            detector: 'comment',
            objectType: 'comment',
            objectId: $commentId,
            detail: $key,
            match: $match,
            confidence: $row->comment_approved === 'trash' && $confidence === Reference::CONFIRMED ? Reference::POSSIBLE : $confidence,
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

        // A JSON key naming the attachment in a value that did not decode. After
        // the walk, so anything that decodes keeps its verdict.
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
            $sibling = (string) get_comment_meta($commentId, '_' . $key, true);

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

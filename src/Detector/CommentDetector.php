<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * Finds references in comments: a file URL or a link to the file's attachment
 * page in a comment body, and IDs or URLs in commentmeta (review photos,
 * attachments on replies, ACF comment fields). Spam is ignored; trashed
 * comments are restorable, so they block deletion but only as possible.
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
     * comment_content: a file URL, or a link to the file's own attachment page.
     *
     * The id shapes are deliberately not bound here — a comment body is prose,
     * so a bare number in it is noise — but a link is not a shape, it is the
     * ordinary way a reply references a document: a support answer or a
     * documentation comment points at the PDF rather than embedding it. Only a
     * URL used to be looked for, so that comment kept the file alive and the
     * link did not.
     */
    private function inContent(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('c.comment_content', $ctx->basenames);
        [$linkConditions, $linkParams] = LikePatterns::attachmentLinkConditions('c.comment_content', $ctx->id);

        // The link conditions are always present, so — unlike before — there is
        // no attachment for which this query binds nothing.
        $conditions = array_merge($nameConditions, $linkConditions);
        $params = array_merge($nameParams, $linkParams);

        $sql = "SELECT c.comment_ID, c.comment_approved, c.comment_content
                FROM {$wpdb->comments} c
                WHERE c.comment_approved <> 'spam'
                  AND (" . implode(' OR ', $conditions) . ')';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $refs = [];

        foreach ((array) $rows as $row) {
            $content = (string) $row->comment_content;

            $match = match (true) {
                LikePatterns::containsBasename($content, $ctx->basenames) => 'url',
                // Confirmed like the URL, and for the same reason: the link
                // names this attachment and nothing else, which is how
                // post_content reads the identical markup.
                LikePatterns::hasAttachmentPageLink($content, $ctx->id) => 'attachment-page',
                default => null,
            };

            if ($match === null) {
                continue;
            }

            $refs[] = new Reference(
                detector: 'comment',
                objectType: 'comment',
                objectId: (int) $row->comment_ID,
                detail: 'comment_content',
                match: $match,
                confidence: $row->comment_approved === 'trash' ? Reference::POSSIBLE : Reference::CONFIRMED,
            );
        }

        return $refs;
    }

    private function inMeta(AttachmentContext $ctx): array
    {
        global $wpdb;

        [$idConditions, $idParams] = LikePatterns::idConditions('cm.meta_value', $ctx->id);
        [$nameConditions, $nameParams] = LikePatterns::basenameConditions('cm.meta_value', $ctx->basenames);

        $conditions = array_merge($idConditions, $nameConditions);

        $sql = "SELECT cm.comment_id, cm.meta_key, cm.meta_value, c.comment_approved
                FROM {$wpdb->commentmeta} cm
                INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
                WHERE c.comment_approved <> 'spam'
                  AND (" . implode(' OR ', $conditions) . ')';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($idParams, $nameParams)));

        $refs = [];

        foreach ((array) $rows as $row) {
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

            if ($idMatch === null && LikePatterns::structureContains(LikePatterns::decodeStored($value), $ctx->id, $ctx->basenames)) {
                $idMatch = 'serialized'; // JSON blob or nested JSON in serialized data.
            }

            // A link to the attachment's own page inside stored markup. Neither
            // branch above can see it: the walk gets an opaque string leaf, and
            // nothing quotes the id. It answers the two link conditions the
            // query binds.
            if ($idMatch === null && LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
                $idMatch = 'attachment-page';
            }

            // Last, so a value that decodes keeps its more specific verdict: the
            // ID as a quoted attribute in a value that is neither serialized nor
            // JSON — [gallery ids="123"], data-id="123" — which the broad pass
            // fetches on its '%"123"%' condition and nothing here answered.
            if ($idMatch === null && LikePatterns::hasQuotedId($value, $ctx->id)) {
                $idMatch = 'id-attribute';
            }

            if ($idMatch === null) {
                continue;
            }

            // ACF sibling meta ('_<key>' = field_…) confirms the reference.
            if (!str_starts_with($key, '_') && str_starts_with((string) get_comment_meta($commentId, '_' . $key, true), 'field_')) {
                $refs[] = $make('acf', Reference::CONFIRMED);
                continue;
            }

            $refs[] = $make($idMatch, Reference::POSSIBLE);
        }

        return $refs;
    }
}

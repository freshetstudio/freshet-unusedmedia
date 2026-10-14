<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

defined('ABSPATH') || exit;

/**
 * Finds references in postmeta: ACF fields (plain, serialized, repeater
 * sub-fields), featured images, WooCommerce galleries, Elementor data and
 * any other meta storing the ID or a file URL.
 */
final class PostmetaDetector implements DetectorInterface
{
    /**
     * Keys that record an attachment's own files rather than display them.
     *
     * `_wp_attachment_metadata` and `_wp_attachment_backup_sizes` are the two
     * that matter here: both are core's record of the filenames belonging to an
     * attachment — the current generation and the one an in-admin edit
     * superseded — so a row of either names a file without referencing it.
     * find() already skips the scanned attachment's own rows, but two uploads
     * cut from the same image carry byte-identical values on these keys, and
     * each would otherwise read as a reference for the other.
     *
     * Public because the reference index walks this table with the same filter
     * (freshet-290, Acceptance 3 — "the **same** table filters"). A second
     * transcription of the list is exactly the drift
     * `LikePatterns::revisionCondition()` exists to prevent.
     */
    public const EXCLUDED_KEYS = [
        '_wp_attached_file',
        '_wp_attachment_metadata',
        '_wp_attachment_backup_sizes',
        '_wp_attachment_image_alt',
        '_wp_old_slug',
        '_wp_old_date',
        '_edit_lock',
        '_edit_last',
    ];

    public function id(): string
    {
        return 'postmeta';
    }

    public function find(AttachmentContext $ctx): array
    {
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides, and hands
        // back the same candidate rows either way.
        $rows = SharedReads::candidates($this->id(), $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            [$idConditions, $idParams] = LikePatterns::anyIdConditions('pm.meta_value', $ids);
            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('pm.meta_value', $basenames);

            $conditions = array_merge($idConditions, $nameConditions);
            $keyPlaceholders = implode(',', array_fill(0, count(self::EXCLUDED_KEYS), '%s'));

            // The same revision filter post_content uses, from the same place: an
            // old version is not a use, but an autosave is an edit in flight, and a
            // draft whose text was searched while its custom fields were not is one
            // row the two detectors gave two answers about.
            [$revisionCondition, $revisionParams] = LikePatterns::revisionCondition('p');

            // "A row never references itself" is answered below rather than here.
            // It is the one condition in this query that is about the row being
            // scanned instead of about the file, and a shared pass serves several
            // rows: excluding any one of them would hide it from its siblings,
            // which legitimately do reference it.
            $sql = "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_parent, p.post_status
                    FROM {$wpdb->postmeta} pm
                    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                    WHERE {$revisionCondition}
                      AND pm.meta_key NOT LIKE %s
                      AND pm.meta_key NOT IN ({$keyPlaceholders})
                      AND (" . implode(' OR ', $conditions) . ')';

            $params = array_merge(
                $revisionParams,
                [$wpdb->esc_like('_freshet_unusedmedia_') . '%'],
                self::EXCLUDED_KEYS,
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
        // The scanned attachment's own meta, which is what the query used to
        // drop with `pm.post_id <> %d`. An attachment whose own custom field
        // carries its own filename would otherwise make itself used for ever.
        if ((int) $row->post_id === $ctx->id) {
            return [];
        }

        $ref = $this->classify($ctx, $row);

        return $ref === null ? [] : [$ref];
    }

    private function classify(AttachmentContext $ctx, object $row): ?Reference
    {
        $postId = (int) $row->post_id;
        $key = (string) $row->meta_key;
        $value = (string) $row->meta_value;
        $postStatus = (string) $row->post_status;

        // Only autosaves reach here as revisions — the query admits no other
        // kind. An autosave has no screen of its own, so the reference points
        // at the parent post the edit belongs to, and it is `possible` however
        // precisely the value verified: the edit may never be saved. Both
        // readings match what post_content already gives the same row.
        $autosave = (string) ($row->post_type ?? '') === 'revision';
        $parentId = (int) ($row->post_parent ?? 0);

        $make = static fn(string $match, string $confidence): Reference => new Reference(
            detector: 'postmeta',
            objectType: 'post',
            objectId: $autosave ? $parentId : $postId,
            detail: $key,
            match: $autosave ? 'autosave' : $match,
            // Trashed posts are restorable — keep them blocking, but flag as possible.
            confidence: $autosave || ($postStatus === 'trash' && $confidence === Reference::CONFIRMED)
                ? Reference::POSSIBLE
                : $confidence,
        );

        // Known core/plugin keys first.
        if ($key === '_thumbnail_id' && LikePatterns::isExactId($value, $ctx->id)) {
            return $make('thumbnail', Reference::CONFIRMED);
        }

        if ($key === '_product_image_gallery' && LikePatterns::inCommaList($value, $ctx->id)) {
            return $make('woo-gallery', Reference::CONFIRMED);
        }

        if ($key === '_elementor_data') {
            if (
                LikePatterns::hasJsonId($value, $ctx->id)
                || LikePatterns::hasJsonIdKey($value, $ctx->id)
                || LikePatterns::containsBasename($value, $ctx->basenames)
            ) {
                return $make('elementor', Reference::CONFIRMED);
            }

            return null;
        }

        // A size-variant filename in any meta is an unambiguous reference.
        if (LikePatterns::containsBasename($value, $ctx->basenames)) {
            return $make('url', Reference::CONFIRMED);
        }

        // Decoded once, and used three times: the walk below needs it, so does
        // the question of whether the raw `i:123;` token means anything here
        // (LikePatterns::hasUndecodedSerializedInt(), which is where `i:7;`
        // matching an eight-element array's *key* stops being a reference), and
        // so does the denied-key branch immediately under this.
        $decoded = LikePatterns::decodeStored($value);

        // Everything below this line reads the value as an id, so a key whose
        // value is documented to be some other integer has nothing to say —
        // Yoast's reading time, a form id, W3 Total Cache's quota. Asked after
        // the filename test on purpose: a counter key is still allowed to hold a
        // URL, and a filename is never ambiguous. The one thing such a record can
        // still say is that it points at another attachment, which is read here
        // and nowhere else.
        if (NonMediaKeys::denies($key)) {
            return LikePatterns::namesLinkedAttachment($decoded, $ctx->id, $ctx->basenames)
                ? $make('serialized', Reference::POSSIBLE)
                : null;
        }

        $idMatch = match (true) {
            LikePatterns::isExactId($value, $ctx->id) => 'exact',
            LikePatterns::inCommaList($value, $ctx->id) => 'comma-list',
            LikePatterns::hasSerializedString($value, $ctx->id),
            LikePatterns::hasUndecodedSerializedInt($value, $decoded, $ctx->id) => 'serialized',
            LikePatterns::hasJsonId($value, $ctx->id) => 'block-id',
            default => null,
        };

        // JSON blobs keep IDs under arbitrary keys ({"logo":123}); serialized
        // values may nest JSON strings. The structural search is the last word.
        if ($idMatch === null && LikePatterns::structureContains($decoded, $ctx->id, $ctx->basenames)) {
            $idMatch = 'serialized';
        }

        // A JSON key naming the attachment in a value that did not decode — a
        // builder fragment stored as markup, a blob inside an attribute. After
        // the walk, so anything that decodes keeps the verdict its decode earned.
        if ($idMatch === null && LikePatterns::hasJsonIdKey($value, $ctx->id)) {
            $idMatch = 'block-id';
        }

        // A link to the attachment's own page inside stored markup — a wysiwyg
        // field holding <a href="?attachment_id=123">the brochure</a>. Neither
        // branch above can see it: the walk gets an opaque string leaf, and
        // nothing quotes the id. It answers the two link conditions the query
        // binds.
        if ($idMatch === null && LikePatterns::hasAttachmentPageLink($value, $ctx->id)) {
            $idMatch = 'attachment-page';
        }

        // Last, so a value that decodes keeps its more specific verdict: the ID
        // as a quoted attribute in a value that is neither serialized nor JSON
        // — a text field holding [gallery ids="123"] or data-id="123" — which
        // the broad pass fetches on its '%"123"%' condition and nothing here
        // answered.
        if ($idMatch === null && LikePatterns::hasQuotedId($value, $ctx->id)) {
            $idMatch = 'id-attribute';
        }

        if ($idMatch === null) {
            return null; // LIKE candidate failed precise verification (e.g. id 1234 vs 123).
        }

        // A key documented to hold an attachment id, which is the other half of
        // what NonMediaKeys knows. Yoast's OpenGraph image was reading as a
        // guess next to Yoast's reading time reading as a guess, off the same
        // prefix; one of the two is the social image an editor picked.
        if (NonMediaKeys::confirms($key)) {
            return $make($idMatch, Reference::CONFIRMED);
        }

        // ACF stores a sibling '_<key>' meta holding the field key — that says
        // the value is this field's data, and `AcfField` is what says whether
        // the field holds an attachment at all. A `field_` sibling alone used to
        // confirm the reference, which is how a select of form ids came to be
        // reported as a confirmed "ACF field" use of attachment 7
        // (freshet-380). ACF's own no is final; its silence is a `possible`.
        if (!str_starts_with($key, '_')) {
            $sibling = (string) get_post_meta($postId, '_' . $key, true);

            if (str_starts_with($sibling, 'field_')) {
                return match (AcfField::namesAttachment($sibling)) {
                    true => $make('acf', Reference::CONFIRMED),
                    false => null,
                    default => $make('acf', Reference::POSSIBLE),
                };
            }
        }

        return $make($idMatch, Reference::POSSIBLE);
    }
}

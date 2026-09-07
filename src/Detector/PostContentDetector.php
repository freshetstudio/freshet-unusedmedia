<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\SharedReads;

defined('ABSPATH') || exit;

/**
 * Finds references in post_content (and post_excerpt): block attributes
 * (wp:image/wp:gallery), wp-image-N classes, field values stored in block
 * delimiters (ACF blocks and the like), shortcode attributes ([gallery],
 * [playlist], page-builder shortcodes) and raw URLs (including resized
 * variants like foo-300x200.jpg).
 */
final class PostContentDetector implements DetectorInterface
{
    /** Shortcode attributes whose numeric values are dimensions or counts, never IDs. */
    private const NON_ID_ATTRIBUTES = [
        'width', 'height', 'columns', 'cols', 'rows', 'size', 'count', 'limit', 'number', 'num',
        'per_page', 'posts_per_page', 'offset', 'page', 'paged', 'order', 'orderby',
        'speed', 'delay', 'interval', 'duration', 'autoplay', 'loop', 'start', 'end',
        'max', 'min', 'step', 'level', 'gap', 'margin', 'padding', 'spacing', 'radius',
        'opacity', 'zoom', 'year', 'month', 'day',
    ];

    public function id(): string
    {
        return 'post-content';
    }

    public function find(AttachmentContext $ctx): array
    {
        // One broad pass for the whole sibling group where one is open, and the
        // pass this always was where one is not — SharedReads decides.
        $rows = SharedReads::candidates($this->id(), $ctx, function (array $ids, array $basenames): array {
            global $wpdb;

            $conditions = [];
            $params = [];

            foreach ($ids as $id) {
                foreach (self::needles((int) $id) as $needle) {
                    $conditions[] = 'p.post_content LIKE %s';
                    $params[] = '%' . $wpdb->esc_like($needle) . '%';
                }
            }

            [$nameConditions, $nameParams] = LikePatterns::basenameConditions('p.post_content', $basenames);
            // An image in an excerpt always carries its URL, so basenames suffice there.
            [$excerptConditions, $excerptParams] = LikePatterns::basenameConditions('p.post_excerpt', $basenames);

            $conditions = array_merge($conditions, $nameConditions, $excerptConditions);
            $params = array_merge($params, $nameParams, $excerptParams);

            // Revisions are skipped — a reference in an old version is not a use —
            // except autosaves, which hold edits in flight that are not saved yet.
            // Shared with PostmetaDetector so the two cannot drift apart on what an
            // autosave is; see LikePatterns::revisionCondition().
            [$revisionCondition, $revisionParams] = LikePatterns::revisionCondition('p');

            // Attachment rows are searched like any other post. WordPress stores an
            // attachment's description in post_content and its caption in
            // post_excerpt, and both are ordinary rich text that can name another
            // file — a document linked from a sibling's description was invisible
            // while post_type excluded 'attachment' here (freshet-148). The row
            // being scanned is kept out in find() rather than here: a shared pass
            // serves every row of a sibling group, and dropping any one of them in
            // SQL would hide it from the siblings that legitimately name it.
            // nav_menu_item stays excluded: a menu item's content is empty and its
            // meta is the postmeta detector's.
            $sql = "SELECT p.ID, p.post_parent, p.post_type, p.post_status, p.post_content, p.post_excerpt
                    FROM {$wpdb->posts} p
                    WHERE p.post_type <> 'nav_menu_item'
                      AND {$revisionCondition}
                      AND p.post_status <> 'auto-draft'
                      AND (" . implode(' OR ', $conditions) . ')';

            $params = array_merge($revisionParams, $params);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, all values bound via prepare().
            return Db::rows($this->id(), $wpdb->get_results($wpdb->prepare($sql, ...$params)));
        });

        $refs = [];

        foreach ($rows as $row) {
            // An attachment whose own description carries its own filename does
            // not reference itself — what "p.ID <> %d" used to say in SQL.
            if ((int) $row->ID === $ctx->id) {
                continue;
            }

            $match = $this->verify((string) $row->post_content . "\n" . (string) $row->post_excerpt, $ctx);

            if ($match === null) {
                continue;
            }

            $autosave = $row->post_type === 'revision';

            $refs[] = new Reference(
                detector: 'post-content',
                objectType: 'post',
                objectId: $autosave ? (int) $row->post_parent : (int) $row->ID,
                detail: $autosave ? 'autosave' : $match,
                match: $autosave ? 'autosave' : $match,
                confidence: $autosave || $row->post_status === 'trash' ? Reference::POSSIBLE : Reference::CONFIRMED,
            );
        }

        return $refs;
    }

    /**
     * The ID-specific needles the broad pass binds on post_content, so we never
     * fetch every gallery or block post on the site; PHP verification makes the
     * final call. One list, called once per row of the sibling group a shared
     * pass covers.
     *
     * @return string[]
     */
    private static function needles(int $id): array
    {
        return [
            'wp-image-' . $id,    // editor image class
            'wp-att-' . $id,      // link to the attachment page (rel/class marker)
            '"id":' . $id,        // block attribute
            '"' . $id . '"',      // JSON string value / quoted shortcode attribute
            "'" . $id . "'",      // single-quoted shortcode attribute
            '"' . $id . ',',      // quoted list start
            ',' . $id . '"',      // quoted list end
            "'" . $id . ',',      // single-quoted list start
            ',' . $id . "'",      // single-quoted list end
            '=' . $id,            // unquoted shortcode attribute
            ':' . $id . ',',      // JSON number value
            ':' . $id . '}',      // JSON number value, last key
            ',' . $id . ',',      // list middle (shortcode or JSON)
            '[' . $id . ',',      // JSON array start
            ',' . $id . ']',      // JSON array end
            '[' . $id . ']',      // JSON single-item array
            // Pretty-printed JSON: {"imageId": 123}, several spaces, or the
            // number on its own indented line. Every needle above puts the
            // delimiter hard against the digits, so none of those forms is
            // fetched at all and the verifier — which is already whitespace-
            // tolerant — never gets to rule. A LIKE cannot express "a run of
            // whitespace", but it does not need to: whatever the run contains,
            // the character immediately before the number is one of these
            // three. Anchoring there costs the right-hand digit boundary (' 123'
            // also admits ' 1234'), which verify() then holds — over-fetching
            // is paid for in one rejected row, under-fetching in a deleted file.
            ' ' . $id,            // space before the value
            "\n" . $id,           // newline before the value
            "\t" . $id,           // tab-indented value
        ];
    }

    /** Precise boundary-checked verification; returns the match type or null. */
    private function verify(string $content, AttachmentContext $ctx): ?string
    {
        $id = $ctx->id;

        if (LikePatterns::containsBasename($content, $ctx->basenames)) {
            return 'url';
        }

        // The editor's image class. One implementation, in LikePatterns with
        // the other markup predicates, because comment_content reads the same
        // form — a comment carries markup pasted out of the editor verbatim —
        // and a second copy of the regex beside this one is drift.
        if (LikePatterns::hasImageClass($content, $id)) {
            return 'wp-image-class';
        }

        if (LikePatterns::hasJsonId($content, $id)) {
            return 'block-id';
        }

        // wp:gallery block: "ids":[4,123,9]
        if (preg_match('/"ids":\s*\[[^\]]*(?<!\d)' . $id . '(?!\d)[^\]]*\]/', $content)) {
            return 'gallery';
        }

        if (LikePatterns::hasBlockAttribute($content, $id, $ctx->basenames)) {
            return 'acf-block';
        }

        // Classic [gallery ids="4,123,9"] shortcode.
        if (preg_match('/\[gallery[^\]]*ids=["\'][^"\']*(?<!\d)' . $id . '(?!\d)[^"\']*["\']/', $content)) {
            return 'gallery';
        }

        if (self::shortcodeAttributeContains($content, $id)) {
            return 'shortcode';
        }

        // A link to the file's own attachment page. For a document this is the
        // usual reference rather than an exotic one — a PDF is linked to, not
        // embedded — so before this a linked PDF read as orphaned. Two forms,
        // both written by the editor's "Link to: attachment page": the query
        // arg in the href, and the rel/class marker on the anchor.
        if (LikePatterns::hasAttachmentPageLink($content, $id)) {
            return 'attachment-page';
        }

        // data-id="123" on a gallery, slider or lightbox element. The broad
        // pass already admitted it (the quoted-value needle); nothing here
        // confirmed it, so the row was fetched and then thrown away.
        if (LikePatterns::hasDataId($content, $id)) {
            return 'id-attribute';
        }

        return null;
    }

    /**
     * Any shortcode attribute whose value is the ID, or a comma list holding
     * it — [vc_single_image image="123"], [playlist ids="4,123"]. Attributes
     * that only ever carry dimensions or counts are ignored.
     */
    private static function shortcodeAttributeContains(string $content, int $id): bool
    {
        $attribute = '[a-zA-Z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s\]\/]+)';

        if (!preg_match_all('/\[[a-zA-Z0-9_-]+((?:\s+' . $attribute . ')+)\s*\/?\]/', $content, $shortcodes)) {
            return false;
        }

        foreach ($shortcodes[1] as $attributes) {
            preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]\/]+))/', $attributes, $pairs, PREG_SET_ORDER);

            foreach ($pairs as $pair) {
                if (in_array(strtolower($pair[1]), self::NON_ID_ATTRIBUTES, true)) {
                    continue;
                }

                $value = $pair[4] ?? '';

                if (($pair[2] ?? '') !== '') {
                    $value = $pair[2];
                } elseif (($pair[3] ?? '') !== '') {
                    $value = $pair[3];
                }

                if (LikePatterns::inCommaList($value, $id)) {
                    return true;
                }
            }
        }

        return false;
    }
}

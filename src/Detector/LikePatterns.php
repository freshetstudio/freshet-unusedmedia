<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

defined('ABSPATH') || exit;

/**
 * Shared query-building and match-verification logic.
 *
 * Strategy everywhere: one broad SQL pass with OR'd LIKE conditions to find
 * candidate rows cheaply, then precise PHP verification with digit-boundary
 * regexes — so attachment 123 never matches wp-image-1234 or "id":1234.
 */
final class LikePatterns
{
    /** Hard ceiling on how deep a stored structure is walked before it is called used. */
    private const MAX_STRUCTURE_DEPTH = 128;

    /** Depth past which arrays carry a path marker, so reference cycles become visible. */
    private const CYCLE_WATCH_DEPTH = 16;

    /** The path marker's key. NUL-wrapped, so no stored key can collide with it. */
    private const CYCLE_MARK = "\0freshet_unusedmedia_path\0";

    /**
     * Whether this runtime can fold case outside ASCII. Resolved once.
     *
     * mbstring is not a requirement of the plugin, so containsBasename() asks
     * before it reaches for it rather than assuming; false leaves the fold
     * exactly as byte-wise as it was.
     */
    private static ?bool $foldsUnicodeCase = null;

    /**
     * The suffix core gives an autosave's post_name: "<parent id>-autosave-v1".
     *
     * @see wp_create_post_autosave()
     */
    public const AUTOSAVE_SUFFIX = '-autosave-v1';

    /**
     * OR'd SQL conditions matching an attachment ID inside a text column:
     * exact value, comma lists, serialized int/string, JSON "id", compact
     * JSON array members, JSON values under any other key, and a link to the
     * attachment's own page.
     *
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function idConditions(string $column, int $id): array
    {
        global $wpdb;

        $like = $wpdb->esc_like((string) $id);
        $serializedString = sprintf('s:%d:"%d"', strlen((string) $id), $id);

        $conditions = [
            "{$column} = %s",          // exact '123'
            "{$column} LIKE %s",       // '123,…' comma list start
            "{$column} LIKE %s",       // '…,123' comma list end
            "{$column} LIKE %s",       // '…,123,…' comma list middle
            "{$column} LIKE %s",       // serialized int i:123;
            "{$column} LIKE %s",       // serialized string s:3:"123"
            "{$column} LIKE %s",       // JSON "id":123
            "{$column} LIKE %s",       // JSON "id":"123"
            "{$column} LIKE %s",       // JSON string value "123" (any key, or array member)
            "{$column} LIKE %s",       // JSON number value :123, (any key)
            "{$column} LIKE %s",       // JSON number value :123} (last key)
            // A compact JSON array member. Every condition above needs a
            // delimiter that a bracket terminates: the number-value ones want
            // ":123," or ":123}", the comma-list ones want the column to start
            // "123,", end ",123" or carry ",123,". So [483], {"gallery":[483]}
            // and the first member of [123,456] were never fetched at all, and
            // the verifier — structureContains(decodeStored(…)), which decodes
            // the array and compares the int — was never handed the row.
            // wp_json_encode() emits exactly this form, so it is the common one.
            // Both ends are delimiters here, unlike the whitespace needles below,
            // so [1234] cannot satisfy 123 and nothing is over-fetched. These are
            // the three array needles PostContentDetector already carries; the
            // same shapes stored as a meta value, an option or a term description
            // are the columns this helper serves.
            "{$column} LIKE %s",       // compact JSON array, first member [123,
            "{$column} LIKE %s",       // compact JSON array, sole member  [123]
            "{$column} LIKE %s",       // compact JSON array, last member  ,123]
            // Pretty-printed JSON: {"imageId": 123}, several spaces, or the
            // number on its own indented line — a plugin writing prettified
            // JSON into a meta value, a hand-edited option, an imported
            // payload. Every condition above puts its delimiter hard against
            // the digits, so none of those forms is fetched at all and the
            // verifier — structureContains(decodeStored(…)), which decodes the
            // spacing away and never cared about it — is never given the row.
            // A LIKE cannot express "a run of whitespace", but it does not need
            // to: whatever the run contains, the character immediately before
            // the number is one of these three. Anchoring there costs the
            // right-hand digit boundary (' 123' also admits ' 1234'), which
            // verification then holds — over-fetching is paid for in a rejected
            // row, under-fetching in a deleted file.
            "{$column} LIKE %s",       // whitespace-preceded number, one space
            "{$column} LIKE %s",       // whitespace-preceded number, newline
            "{$column} LIKE %s",       // whitespace-preceded number, tab indent
        ];

        $params = [
            (string) $id,
            $like . ',%',
            '%,' . $like,
            '%,' . $like . ',%',
            '%' . $wpdb->esc_like('i:' . $id . ';') . '%',
            '%' . $wpdb->esc_like($serializedString) . '%',
            '%' . $wpdb->esc_like('"id":' . $id) . '%',
            '%' . $wpdb->esc_like('"id":"' . $id . '"') . '%',
            '%' . $wpdb->esc_like('"' . $id . '"') . '%',
            '%' . $wpdb->esc_like(':' . $id . ',') . '%',
            '%' . $wpdb->esc_like(':' . $id . '}') . '%',
            '%' . $wpdb->esc_like('[' . $id . ',') . '%',
            '%' . $wpdb->esc_like('[' . $id . ']') . '%',
            '%' . $wpdb->esc_like(',' . $id . ']') . '%',
            '%' . $wpdb->esc_like(' ' . $id) . '%',
            '%' . $wpdb->esc_like("\n" . $id) . '%',
            '%' . $wpdb->esc_like("\t" . $id) . '%',
        ];

        // A link to the attachment's own page, stored in a column that holds
        // markup rather than an id: a wysiwyg field, a text widget's HTML, a
        // term description. Every condition above wants the id written as a
        // value — quoted, bracketed, comma-listed, serialized — and in a link
        // it is written as a query arg or inside a class name, so none of them
        // fetched the row and no verifier was ever handed it. post_content has
        // read these two forms since the document case was fixed; these are the
        // six columns behind this helper, and the same two needles.
        [$linkConditions, $linkParams] = self::attachmentLinkConditions($column, $id);

        return [array_merge($conditions, $linkConditions), array_merge($params, $linkParams)];
    }

    /**
     * OR'd LIKE conditions for a link to the attachment's own page:
     * ?attachment_id=123 in an href, and the rel/class marker wp-att-123 the
     * editor writes on the same anchor. Separate from idConditions() because
     * comment_content binds these two — and the image class below them — and
     * none of the id conditions: a comment body is prose, so the id shapes would
     * be noise there, while the link is exactly how a comment references a
     * document.
     *
     * Both needles are left-delimited only ('=' and '-'), so attachment_id=1234
     * is admitted for 123 and hasAttachmentIdQuery()/hasAttachmentLinkId() are
     * what reject it — the same trade the whitespace needles above make. The
     * prefix is what keeps that cheap: a row can only be over-fetched if it
     * already carries an attachment-page link.
     *
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function attachmentLinkConditions(string $column, int $id): array
    {
        global $wpdb;

        return [
            [
                "{$column} LIKE %s",   // ?attachment_id=123 in a URL
                "{$column} LIKE %s",   // rel="attachment wp-att-123" / class="wp-att-123"
            ],
            [
                '%' . $wpdb->esc_like('attachment_id=' . $id) . '%',
                '%' . $wpdb->esc_like('wp-att-' . $id) . '%',
            ],
        ];
    }

    /**
     * The revision filter every detector reading the posts table shares: a
     * reference in an old version is not a use, so revisions are skipped —
     * except autosaves, which hold an edit in flight that has not been saved
     * yet, and whose file would otherwise be deleted out from under the draft.
     *
     * It lives here, and not inline in each detector, because two detectors
     * reading the same row disagreeing about whether that row exists is a
     * defect on its own: post_content admitted autosaves while postmeta
     * excluded them, so an unsaved draft's text was searched and its custom
     * fields were not (freshet-147). One condition, one answer.
     *
     * A caller that admits autosaves must also read them as autosaves — the
     * reference belongs to the parent post, which is the only thing with a
     * screen, and its confidence is `possible` however precisely the value
     * verified, because the edit may never be saved.
     *
     * @return array{0: string, 1: array<int, string>} [condition, params]
     */
    public static function revisionCondition(string $alias): array
    {
        global $wpdb;

        return [
            "({$alias}.post_type <> 'revision' OR {$alias}.post_name LIKE %s)",
            ['%' . $wpdb->esc_like(self::AUTOSAVE_SUFFIX)],
        ];
    }

    /**
     * The OR'd LIKE condition for the class the block editor writes on an image
     * it has inserted: class="wp-image-123". Kept beside the link pair above and
     * apart from idConditions() for the same reason — comment_content binds
     * these three and none of the id shapes, because the id shapes are numbers
     * and a comment body is prose, while this class is not something a commenter
     * types at all: it arrives verbatim inside markup copied out of the editor.
     *
     * Left-delimited only ('-'), exactly like wp-att- above, so wp-image-1234 is
     * admitted for 123 and hasImageClass() is what rejects it. The prefix is
     * what keeps that cheap: a row can only be over-fetched if it already
     * carries an editor image class.
     *
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function imageClassConditions(string $column, int $id): array
    {
        global $wpdb;

        return [
            ["{$column} LIKE %s"],
            ['%' . $wpdb->esc_like('wp-image-' . $id) . '%'],
        ];
    }

    /**
     * The same three condition sets, for every row of a sibling group at once.
     *
     * A group's rows share their file and therefore their basenames, but not
     * their ids, so the id half of a shared broad pass is the union of the
     * group's — see SharedReads. Looping the single-row builders keeps one
     * definition of each condition set: a second transcription of nineteen
     * needles is exactly the drift `revisionCondition()` exists to prevent.
     *
     * @param int[] $ids
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function anyIdConditions(string $column, array $ids): array
    {
        return self::anyOf([self::class, 'idConditions'], $column, $ids);
    }

    /**
     * @param int[] $ids
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function anyAttachmentLinkConditions(string $column, array $ids): array
    {
        return self::anyOf([self::class, 'attachmentLinkConditions'], $column, $ids);
    }

    /**
     * @param int[] $ids
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function anyImageClassConditions(string $column, array $ids): array
    {
        return self::anyOf([self::class, 'imageClassConditions'], $column, $ids);
    }

    /**
     * One condition builder, OR'd across a list of ids.
     *
     * @param callable(string, int): array{0: string[], 1: array<int, string>} $build
     * @param int[] $ids
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    private static function anyOf(callable $build, string $column, array $ids): array
    {
        $conditions = [];
        $params = [];

        foreach ($ids as $id) {
            [$idConditions, $idParams] = $build($column, (int) $id);

            $conditions = array_merge($conditions, $idConditions);
            $params = array_merge($params, $idParams);
        }

        return [$conditions, $params];
    }

    /**
     * OR'd LIKE conditions for the attachment's basenames.
     *
     * @param string[] $basenames
     * @return array{0: string[], 1: array<int, string>} [conditions, params]
     */
    public static function basenameConditions(string $column, array $basenames): array
    {
        global $wpdb;

        $conditions = [];
        $params = [];

        foreach ($basenames as $name) {
            $conditions[] = "{$column} LIKE %s";
            $params[] = '%' . $wpdb->esc_like($name) . '%';
        }

        return [$conditions, $params];
    }

    // ------------------------------------------------------------ verification

    /**
     * A basename inside a text value, compared without regard to case.
     *
     * Folded because the SQL half already folds: MySQL's LIKE is
     * case-insensitive under every collation WordPress ships, so a value
     * spelling the file HERO-300X200.JPG is fetched by basenameConditions()
     * and a case-sensitive str_contains() then threw it away — a reference
     * that exists and was not counted. Whether such a URL resolves is a fact
     * about the host, not about the library: a case-insensitive filesystem or
     * a normalising CDN serves it, a Linux host 404s it, and nothing in the
     * plugin can tell which it is running on. Folding is the answer that is
     * safe under both — an extra match keeps a file, a missed one deletes a
     * used one.
     *
     * The value is folded once and the needles are compared against the fold,
     * rather than calling stripos() per needle. This runs on every candidate
     * row of every detector and on every string leaf of every walked
     * structure, so the cost was measured rather than guessed. Over three real
     * libraries — 54.9 MB, 9.7 MB and 9.2 MB of post_content, six basenames a
     * call — folding once costs +3.7% / +8.2% / +4.1%, about 0.2 ms per
     * thousand rows, and does not vary with the filename. stripos() per needle
     * swings from -53% to +152% on the same corpus depending on how common the
     * first letter of the basename happens to be in the text, and an uploads
     * folder is full of names beginning i, a and s. A predictable fraction of
     * a millisecond beats a good average with a bad case.
     *
     * The fold is Unicode for case, and it deliberately stops there
     * (freshet-156). strtolower() is byte-wise, so it never touched the case
     * of an accented letter: HERO.JPG folded onto hero.jpg and HÉRO.JPG did
     * not fold onto héro.jpg, while basenameConditions() fetched that row on
     * every collation WordPress ships (measured: general_ci, unicode_ci,
     * unicode_520_ci and 0900_ai_ci all answer 1). That variant is the one
     * that can be a live reference — a volume that folds case folds É onto é
     * exactly as it folds E onto e, so the URL resolves — so it is closed,
     * with mb_strtolower(), gated on the file's own name carrying the accent.
     * Where mbstring is absent the branch never runs and the old behaviour
     * stands; the plugin does not require the extension for this.
     *
     * What is NOT followed is the collation's accent-insensitivity: those same
     * LIKEs also fetch hero.jpg for a stored héro.jpg, and this goes on
     * rejecting it. Three reasons, each measured rather than reasoned. No
     * filesystem folds accents, so such a URL 404s on every host — the row is
     * over-fetch, not a dropped reference. The collations disagree with each
     * other about what an accent even is: on ø, ł and ß the four above give
     * four different answers, so no single fold agrees with the host. And it
     * costs — a transliterating fold measured +422 / +115 / +310 ms per
     * thousand rows on the three libraries above, against the +0.15 / +0.15 /
     * +0.17 the gated case fold costs where no filename is accented, and
     * +22.1 / +5.4 / +16.1 while scanning one that is. Exposure was swept
     * before deciding: 29 local libraries carrying 118 accented filenames
     * between them, and no row anywhere naming one of them in a spelling this
     * rejects. Normalisation is not followed either, and needs no argument —
     * no collation folds NFD onto NFC, so the SQL half does not fetch it.
     *
     * @param string[] $basenames
     */
    public static function containsBasename(string $text, array $basenames): bool
    {
        $folded = null;
        $unicodeFolded = null;
        self::$foldsUnicodeCase ??= function_exists('mb_strtolower') && function_exists('mb_check_encoding');

        foreach ($basenames as $name) {
            if ($name === '') {
                continue;
            }

            $folded ??= strtolower($text);

            if (str_contains($folded, strtolower($name))) {
                return true;
            }

            if (self::$foldsUnicodeCase && !mb_check_encoding($name, 'ASCII')) {
                $unicodeFolded ??= mb_strtolower($text, 'UTF-8');

                if (str_contains($unicodeFolded, mb_strtolower($name, 'UTF-8'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whole value is the ID: '123'. */
    public static function isExactId(string $text, int $id): bool
    {
        return trim($text) === (string) $id;
    }

    /** ID as member of a comma-separated list ('4,123,9'), boundaries enforced. */
    public static function inCommaList(string $text, int $id): bool
    {
        return in_array((string) $id, array_map('trim', explode(',', $text)), true);
    }

    /**
     * Serialized int value i:123; (ambiguous: could be an array key).
     *
     * Deliberately not folded, unlike the markup predicates below. The LIKE
     * that fetches this does fold, so a value carrying I:123; arrives here and
     * is rejected — but serialize() only ever writes the lowercase token and
     * unserialize() only ever reads it, so an uppercase one is not a reference
     * that was dropped, it is text that resembles one. Same for the string
     * form below.
     */
    public static function hasSerializedInt(string $text, int $id): bool
    {
        return str_contains($text, 'i:' . $id . ';');
    }

    /** Serialized string value s:3:"123" (strlen-exact, unambiguous match). */
    public static function hasSerializedString(string $text, int $id): bool
    {
        return str_contains($text, sprintf('s:%d:"%d"', strlen((string) $id), $id));
    }

    /**
     * JSON "id":123 or "id":"123" with a digit boundary. The key is matched
     * without regard to case: the LIKE that fetches it folds, and "ID" is a
     * key real code writes — it is what WordPress itself calls the column.
     */
    public static function hasJsonId(string $text, int $id): bool
    {
        return (bool) preg_match('/"id":\s*"?' . $id . '(?!\d)/i', $text);
    }

    /**
     * The ID as a whole double-quoted value — "123" — anywhere in a string:
     * a shortcode attribute, a data- attribute, a JSON string leaf in markup
     * that is not itself decodable. The quotes are the boundary, so "1234"
     * cannot satisfy 123. This is the verifier for the '%"123"%' condition
     * idConditions() binds; single quotes are deliberately not matched here,
     * because no condition there admits them.
     */
    public static function hasQuotedId(string $text, int $id): bool
    {
        return str_contains($text, '"' . $id . '"');
    }

    /**
     * A link to the attachment's own page: ?attachment_id=123 in a URL. This is
     * what a document reference usually looks like — a PDF is linked to, not
     * embedded — so a file whose only use is a text link scanned unused before
     * this. The literal '=' anchors the left boundary and (?!\d) the right, so
     * attachment_id=1234 can never satisfy 123. Matched without regard to
     * case, because the LIKE that fetches it is: markup carries whatever case
     * its author wrote, and the digits are what the boundaries hold.
     */
    public static function hasAttachmentIdQuery(string $text, int $id): bool
    {
        return (bool) preg_match('/attachment_id=' . $id . '(?!\d)/i', $text);
    }

    /**
     * The marker the editor writes on a link whose target is the attachment
     * page: rel="attachment wp-att-123", and the class="wp-att-123" the same
     * markup carries. Anchored on the wp-att- prefix, digit-bounded on the
     * right, so wp-att-1234 is not a match for 123. Case-folded for the same
     * reason as the query form above.
     */
    public static function hasAttachmentLinkId(string $text, int $id): bool
    {
        return (bool) preg_match('/wp-att-' . $id . '(?!\d)/i', $text);
    }

    /**
     * Either form of a link to the attachment's own page. Only the disjunction
     * of the two verifiers above — they hold the boundaries — so that the eight
     * chains answering the two link conditions spell the pair once.
     */
    public static function hasAttachmentPageLink(string $text, int $id): bool
    {
        return self::hasAttachmentIdQuery($text, $id) || self::hasAttachmentLinkId($text, $id);
    }

    /**
     * The class the editor writes on an image it inserts — wp-image-123. The
     * literal prefix anchors the left boundary and (?!\d) the right, so
     * wp-image-1234 can never satisfy 123. Case-folded for the same reason as
     * the link forms above: the LIKE that fetches it folds, and an <img>
     * carrying the class in any case is still the file on a page.
     */
    public static function hasImageClass(string $text, int $id): bool
    {
        return (bool) preg_match('/wp-image-' . $id . '(?!\d)/i', $text);
    }

    /**
     * data-id="123" — the attribute galleries, sliders and lightboxes carry the
     * attachment they render in. The quote closes the boundary; where the value
     * is written bare the digit boundary does, so 1234 satisfies neither.
     * Case-folded: an HTML attribute name is case-insensitive to the browser,
     * so DATA-ID="123" renders the file and must not read as nothing.
     */
    public static function hasDataId(string $text, int $id): bool
    {
        return (bool) preg_match('/data-id=(["\']?)' . $id . '\1(?!\d)/i', $text);
    }

    /**
     * A namespaced block delimiter whose attribute JSON carries the ID —
     * field-framework blocks (ACF and the plugins built on the same model) and
     * any other vendor block. Their values live in the delimiter: IDs only, no
     * URL, and for a dynamic block no rendered HTML is ever saved, so the
     * delimiter is the only record of the reference.
     *
     * This lives here rather than in the post-content detector because block
     * markup is not confined to post_content: a `widget_block` widget, a theme
     * mod, a page-builder option, a meta field holding a stored template all
     * carry the same delimiters, and the bare integer inside one satisfies none
     * of the string verifiers above — a quoted id survives by accident, an
     * unquoted one was fetched by the query and answered by nothing.
     *
     * Core blocks are deliberately not searched. Their attribute schemas are
     * core-defined and finite, every one that carries an attachment names it
     * "id"/"ids" — already verified by hasJsonId(), with digit boundaries — and
     * each also saves the URL or a wp-image-N class into its markup. There is
     * no unknown key space to miss, while core's numeric attributes (height,
     * width, columns) are everywhere, so widening to them buys no coverage.
     *
     * @param string[] $basenames
     */
    public static function hasBlockAttribute(string $text, int $id, array $basenames): bool
    {
        $seenObjects = [];

        return self::searchBlockAttributes($text, $id, $basenames, $seenObjects, 0);
    }

    /**
     * The parse behind hasBlockAttribute(), sharing the structure walk's depth
     * budget — block markup can nest inside a decoded leaf that itself came out
     * of block markup, so the two recursions have to be bounded together rather
     * than each starting from zero.
     *
     * @param string[]         $basenames
     * @param array<int, true> $seenObjects
     */
    private static function searchBlockAttributes(
        string $text,
        int $id,
        array $basenames,
        array &$seenObjects,
        int $depth
    ): bool {
        // Cheap gate: the pattern below cannot match without this substring, and
        // this runs on every string leaf of every walked structure.
        if (!str_contains($text, 'wp:')) {
            return false;
        }

        if ($depth > self::MAX_STRUCTURE_DEPTH) {
            return true; // Same fail-safe direction as the walk: unresolvable reads as used.
        }

        if (!preg_match_all('/<!--\s+wp:[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*\s+(\{[\s\S]+?\})\s+\/?-->/', $text, $matches)) {
            return false;
        }

        foreach ($matches[1] as $json) {
            $attrs = json_decode($json, true);

            if (!is_array($attrs)) {
                // Undecodable delimiter: a digit-bounded ID is enough to keep the file.
                if (preg_match('/(?<![\d.])' . $id . '(?![\d.])/', $json)) {
                    return true;
                }

                continue;
            }

            // Every attribute, at any depth. An id under a key we do not
            // recognise still keeps the file; a dimension that happens to equal
            // the id keeps one file too many, which is the recoverable half of
            // the trade.
            if (self::searchStructure($attrs, $id, $basenames, $seenObjects, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively search an unserialized/decoded structure for the ID or a
     * basename. A string leaf that is itself a JSON object/array is decoded and
     * searched too — settings blobs keep IDs under arbitrary keys, and
     * serialized theme mods nest JSON strings. A leaf that is not JSON but does
     * carry block markup is parsed as block markup, which is what puts the
     * delimiter within reach of every detector that walks a stored value.
     *
     * A stored value is not guaranteed to be a tree. Serialized reference
     * tokens (r:/R:) unserialize into a graph that points back at itself, and
     * walking one of those without a guard never returns — it climbs until the
     * process runs out of memory, with no chance for the scan time-box to
     * interrupt it. The walk is bounded three ways, in order of precision:
     * object identity, a path marker on arrays, and a hard depth cap.
     *
     * @param string[] $basenames
     */
    public static function structureContains(mixed $value, int $id, array $basenames): bool
    {
        $seenObjects = [];

        return self::searchStructure($value, $id, $basenames, $seenObjects, 0);
    }

    /**
     * The bounded walk behind structureContains().
     *
     * $value is taken by reference so a cyclic array can be marked in place:
     * a reference cycle is only visible if the array the cycle points back at
     * is the one carrying the mark, and a copy would not be. Marking costs a
     * copy-on-write separation per array, so it only starts at
     * CYCLE_WATCH_DEPTH — stored data nests shallowly, a cycle does not, so the
     * common path stays allocation-free and a cycle is still caught within a
     * few levels of entering it.
     *
     * @param string[]           $basenames
     * @param array<int, true>   $seenObjects Objects already walked, by identity.
     */
    private static function searchStructure(
        mixed &$value,
        int $id,
        array $basenames,
        array &$seenObjects,
        int $depth
    ): bool {
        // The only inexact bound, and the only one that can be reached by data
        // that is merely deep rather than cyclic. It answers "used": keeping a
        // file we cannot resolve is recoverable, deleting a used one is not.
        if ($depth > self::MAX_STRUCTURE_DEPTH) {
            return true;
        }

        if (is_int($value)) {
            return $value === $id;
        }

        if (is_string($value)) {
            if ($value === (string) $id || self::containsBasename($value, $basenames)) {
                return true;
            }

            $decoded = self::decodeJson($value);

            if ($decoded !== null) {
                return self::searchStructure($decoded, $id, $basenames, $seenObjects, $depth + 1);
            }

            // Not JSON, so the leaf is markup or prose. Block delimiters keep
            // their values as attribute JSON inside an HTML comment — opaque to
            // every verifier that wants a delimiter around the digits, and the
            // shape a `widget_block` widget, a theme mod or a stored template
            // arrives in.
            return self::searchBlockAttributes($value, $id, $basenames, $seenObjects, $depth + 1);
        }

        if (is_object($value)) {
            $handle = spl_object_id($value);

            // Walked already: its verdict was false, or it is an ancestor of
            // this frame and still being walked. Either way there is nothing
            // here that the caller is not already looking at.
            if (isset($seenObjects[$handle])) {
                return false;
            }

            $seenObjects[$handle] = true;
            $properties = get_object_vars($value);

            return self::searchStructure($properties, $id, $basenames, $seenObjects, $depth + 1);
        }

        if (!is_array($value)) {
            return false;
        }

        if ($depth < self::CYCLE_WATCH_DEPTH) {
            foreach ($value as $item) {
                if (self::searchStructure($item, $id, $basenames, $seenObjects, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($value[self::CYCLE_MARK])) {
            return false; // On the current path already — this branch is walked.
        }

        $value[self::CYCLE_MARK] = true;

        try {
            foreach (array_keys($value) as $key) {
                if ($key === self::CYCLE_MARK) {
                    continue;
                }

                if (self::searchStructure($value[$key], $id, $basenames, $seenObjects, $depth + 1)) {
                    return true;
                }
            }
        } finally {
            unset($value[self::CYCLE_MARK]);
        }

        return false;
    }

    /**
     * A stored value as a structure: PHP-serialized data is unserialized with
     * classes disabled (meta can be author-written), JSON text is decoded,
     * anything else is returned as the plain string.
     */
    public static function decodeStored(string $value): mixed
    {
        if (is_serialized($value)) {
            $data = @unserialize(trim($value), ['allowed_classes' => false]);

            return $data === false ? $value : $data;
        }

        return self::decodeJson($value) ?? $value;
    }

    /** Decodes a string that is a JSON object or array; null for anything else. */
    public static function decodeJson(string $text): ?array
    {
        $first = ltrim($text)[0] ?? '';

        if ($first !== '{' && $first !== '[') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : null;
    }
}

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

    /**
     * The `i:N;` token in a value the structure walk cannot rule on.
     *
     * **`i:7;` is how `serialize()` writes the array key 7 as well as the array
     * value 7, and the key is the common one.** Any serialized list with eight
     * or more members carries `i:7;` whatever is in it — a user's hidden-column
     * preferences, a list of metabox slugs, a settings array. Measured on
     * freshet-380's library, for attachment 7: of the nineteen usermeta rows
     * carrying the token, **seventeen hold no 7 anywhere at all** — they are
     * `metaboxhidden_*` and `manageedit-*columnshidden`, whose eighth entry is
     * what the token is. Every one of those was a reference.
     *
     * The walk over the decoded structure already answers this precisely — it
     * iterates values and ignores keys — so where a value decodes, the walk is
     * the authority and this token adds nothing but false positives. Where it
     * does not decode, there is no walk to defer to: truncated or hand-edited
     * serialized data is still worth reading as a reference, which is the safe
     * direction and the behaviour this always had.
     *
     * @param mixed $decoded What `decodeStored($text)` returned — passed in
     *                       rather than recomputed, because every caller needs
     *                       it for the walk two predicates later.
     */
    public static function hasUndecodedSerializedInt(string $text, mixed $decoded, int $id): bool
    {
        if (is_array($decoded) || is_object($decoded)) {
            return false;
        }

        return self::hasSerializedInt($text, $id);
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
     *
     * The bare key only. Any other key ending in those two letters is
     * hasJsonIdKey() below, and the two are separate so that a value which
     * decodes keeps the more specific verdict its decode earned — see the
     * placement note there.
     */
    public static function hasJsonId(string $text, int $id): bool
    {
        return (bool) preg_match('/"id":\s*"?' . $id . '(?!\d)/i', $text);
    }

    /**
     * A JSON key that ends in id but is not it — "mediaId", "backgroundId",
     * "posterId", "media_id" — with the same digit boundary.
     *
     * The shape this exists for is JSON written into a value that neither
     * decodes nor parses as block markup: an attribute holding a settings blob,
     * a page-builder fragment stored as HTML. Anything that decodes is resolved
     * by the structure walk, and anything inside a block delimiter by the
     * delimiter parse — both of which name the carrier they found it in, which
     * is the better evidence line. So every caller asks this one LAST, after
     * those two have declined: it is the fall-through, not the first guess.
     *
     * It only ever keeps a file, and it is paid for in precision: a key that
     * merely ends in those letters ("grid", "uid") reads as a reference when its
     * value happens to equal the id. That keeps one file too many, which the
     * user can still delete; the other direction deletes a file that is on a
     * page. Folded like hasJsonId(), for the same reason.
     */
    public static function hasJsonIdKey(string $text, int $id): bool
    {
        if (!preg_match_all('/"([A-Za-z0-9_]+id)"\s*:\s*"?' . $id . '(?!\d)/i', $text, $matches)) {
            return false;
        }

        // The key is captured so the one thing that can be *sourced* about it
        // gets asked: `{"formId":"7"}` is a row of Gravity Forms' own tables and
        // indexes nothing in wp_posts, and this predicate labels its caller's
        // verdict *confirmed* — so without this it reported a form id as a
        // confirmed use of attachment 7 (freshet-380). Everything else still
        // passes, loosely, on purpose: see the note above.
        foreach ($matches[1] as $key) {
            if (!NonMediaKeys::denies($key)) {
                return true;
            }
        }

        return false;
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
     * The id the classic caption carries — [caption id="attachment_123"], and
     * the <div id="attachment_123"> that shortcode renders into. Anchored on
     * the attachment_ prefix, digit-bounded on the right, so attachment_1234 is
     * not a match for 123. Case-folded like the markers above.
     *
     * No condition in idConditions() admits this shape: the id is written
     * inside a word, so the quoted needle wants "123" and gets "attachment_123",
     * and the attachment-page needles want an '=' or a 'wp-att-' before the
     * digits. So this verifier only ever rules on a row some OTHER needle
     * fetched — the same post naming the file a second way, or a sibling row's
     * id in a shared group pass. Admitting it in SQL is a twentieth condition
     * and freshet-D102 measures one of those on a production table first; the
     * reference index does it for nothing, which is where it belongs.
     */
    public static function hasCaptionAttachmentId(string $text, int $id): bool
    {
        return (bool) preg_match('/attachment_' . $id . '(?!\d)/i', $text);
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
     * Core blocks are searched too, but only under the keys that can name an
     * attachment. Their delimiter carries no namespace — `<!-- wp:media-text
     * {…} -->` — so for a long time none of them entered this parse at all, and
     * the rescue was that core's own render leaves the file URL or a
     * wp-image-N class in the markup beside it. That rescue holds until the
     * URLs are rewritten to a CDN or the class is edited away, and then a Cover
     * or a Media & Text block naming its image only in the delimiter reads as
     * nothing. Their schemas are core-defined and finite, though, so the keys
     * are known rather than guessed — see idKeyedValues() for why the rest of a
     * core block's attributes are left alone.
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

        // The namespace segment is optional, so `wp:media-text` is read as well
        // as `wp:acf/hero`. The block name is captured because the two answer a
        // different question below: a vendor's attribute keys are unknown, a
        // core block's are not.
        if (!preg_match_all(
            '/<!--\s+wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s+(\{[\s\S]+?\})\s+\/?-->/',
            $text,
            $matches,
            PREG_SET_ORDER
        )) {
            return false;
        }

        foreach ($matches as $match) {
            $namespaced = str_contains($match[1], '/');

            // A delimiter whose JSON holds neither the digits nor a filename
            // cannot answer, whichever kind of block it is: everything below
            // either compares the id (as an int or as a string of those digits)
            // or looks for a basename, and json_decode() changes the spelling of
            // neither — a \u-escaped or percent-encoded filename is already one
            // of the names AttachmentContext collects. So the cheap test on the
            // raw text decides, and the paragraph, heading and spacer blocks on
            // every page stay out of json_decode() entirely. A core block is
            // only ever read for the id (see idKeyedValues()), so it is not
            // asked about filenames.
            if (!str_contains($match[2], (string) $id)
                && !($namespaced && self::containsBasename($match[2], $basenames))) {
                continue;
            }

            $attrs = json_decode($match[2], true);

            if (!is_array($attrs)) {
                // Undecodable delimiter: a digit-bounded ID is enough to keep the file.
                if (preg_match('/(?<![\d.])' . $id . '(?![\d.])/', $match[2])) {
                    return true;
                }

                continue;
            }

            // A vendor block's schema belongs to the vendor, so every attribute
            // is searched at any depth: an id under a key we do not recognise
            // still keeps the file, and a dimension that happens to equal the id
            // keeps one file too many, which is the recoverable half of the
            // trade. The two exceptions are the ones that can be *sourced* —
            // see vendorSearchable(). A core block's schema is core's, so the
            // keys that can name an attachment are known and only those are
            // searched.
            $searchable = $namespaced ? self::vendorSearchable($attrs) : self::idKeyedValues($attrs);

            if (self::searchStructure($searchable, $id, $basenames, $seenObjects, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one thing a denied value is still read for: a link to another
     * attachment, under a key that says so.
     *
     * A bookkeeping record is mostly numbers about the plugin that wrote it, and
     * `NonMediaKeys` exists to stop those reading as references. But a record
     * about *this* attachment can perfectly well name *another* one, and W3 Total
     * Cache's does: `post_child => 58043` is the WebP copy it made, a reference
     * like any other. Denying the whole row dropped it, and the parity run that
     * caught it is written up in `NonMediaKeys`.
     *
     * So the walk is run over the link-keyed values alone. Everything else in the
     * value — the quota, the job id, the byte sizes, the array positions — is out
     * of reach, which is the whole point of the key being denied.
     *
     * @param mixed    $decoded What decodeStored() returned for the value.
     * @param string[] $basenames
     */
    public static function namesLinkedAttachment(mixed $decoded, int $id, array $basenames = []): bool
    {
        if (!is_array($decoded)) {
            return false;
        }

        $links = self::linkedValues($decoded);

        return $links !== [] && self::structureContains($links, $id, $basenames);
    }

    /**
     * The values of every link-naming key in a decoded value, at any depth.
     *
     * The peer of idKeyedValues() below, and the same shape of answer for the
     * same reason — a known schema is read under the keys that can hold a post,
     * never whole.
     *
     * @param array<array-key, mixed> $data
     * @return list<mixed>
     */
    private static function linkedValues(array $data): array
    {
        $values = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && NonMediaKeys::links($key)) {
                $values[] = $value;

                continue;
            }

            if (is_array($value)) {
                $nested = self::linkedValues($value);

                if ($nested !== []) {
                    $values[] = $nested;
                }
            }
        }

        return $values;
    }

    /**
     * A vendor block's attributes, less the two kinds of value that are
     * documented as not being an attachment id.
     *
     * Everything else is kept and searched at any depth, exactly as before: a
     * vendor's schema is the vendor's, and an id under a key nobody here
     * recognises still keeps the file. What this removes is only what can be
     * **sourced**, which is the same bar `NonMediaKeys` sets:
     *
     * - a key that class denies — `{"formId":"7"}` in a `gravityforms/block`
     *   delimiter, which is a row of the form plugin's own tables and indexes
     *   nothing in `wp_posts`;
     * - a value ACF itself describes as holding something other than an
     *   attachment. A field-framework block keeps its values in a `data` map
     *   with the field key of each one beside it under `_<name>`, so the
     *   question can be put to ACF per value. On freshet-380's library this is
     *   what three of the five *confirmed* references to attachment 7 were: the
     *   row count of a repeater (`"partners": 7`), reported as an ACF field use.
     *
     * A value ACF cannot be asked about — no ACF loaded, a group since deleted,
     * a relationship field with no post-type restriction — is kept. The whole
     * point is to drop what is known to be wrong, not what is unproven.
     *
     * @param array<array-key, mixed> $attrs
     * @return array<array-key, mixed>
     */
    private static function vendorSearchable(array $attrs): array
    {
        $searchable = [];

        foreach ($attrs as $key => $value) {
            if (is_string($key) && NonMediaKeys::denies($key)) {
                continue;
            }

            if ($key === 'data' && is_array($value)) {
                $searchable[$key] = self::acfFieldValues($value);

                continue;
            }

            $searchable[$key] = is_array($value) ? self::vendorSearchable($value) : $value;
        }

        return $searchable;
    }

    /**
     * A field-framework block's `data` map, less the values ACF says are not
     * attachments.
     *
     * The map is flat whatever the field structure — a repeater sub-field is
     * `cta_0_image` with its key under `_cta_0_image`, and the repeater's own row
     * count is `cta` with the repeater's key under `_cta` — so one pass over it
     * pairs every value with the field that wrote it. `AcfField::namesAttachment()`
     * is the only judge, and only its explicit *no* removes anything.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function acfFieldValues(array $data): array
    {
        $searchable = [];

        foreach ($data as $name => $value) {
            if (is_string($name) && NonMediaKeys::denies($name)) {
                continue;
            }

            if (is_string($name) && !str_starts_with($name, '_')) {
                $fieldKey = $data['_' . $name] ?? null;

                if (is_string($fieldKey) && AcfField::namesAttachment($fieldKey) === false) {
                    continue;
                }
            }

            $searchable[$name] = is_array($value) ? self::vendorSearchable($value) : $value;
        }

        return $searchable;
    }

    /**
     * The values of a core block's attachment-bearing attributes: every key
     * written `id` or `ids`, or ending in one — `mediaId`, `backgroundId`,
     * `posterId` — at any depth inside the decoded attributes.
     *
     * Core's attribute schemas are finite and published, and every one of them
     * that names an attachment names it under such a key. The rest are
     * dimensions, ratios, colours and slugs, and that is why they are left out
     * rather than walked like a vendor block's: `{"width":1024}` is on every
     * resized image on the site, so searching it would read attachment 1024 as
     * used from the first picture anybody scaled. A library's early ids sit
     * squarely in the range a width, a height or a minHeight occupies.
     *
     * @return list<mixed> The values worth searching, in no particular order.
     */
    private static function idKeyedValues(array $attrs): array
    {
        $values = [];

        foreach ($attrs as $key => $value) {
            if (is_string($key) && preg_match('/ids?$/i', $key)) {
                $values[] = $value;

                continue;
            }

            if (is_array($value)) {
                $values[] = self::idKeyedValues($value);
            }
        }

        return $values;
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
     * The last value decoded and what it decoded to — see decodeStored().
     *
     * Two fields and a flag rather than one nullable, because `null` is a value
     * a decode can legitimately produce (`'N;'` unserializes to it) and a memo
     * that cannot tell "nothing held" from "holds null" re-decodes the one row
     * where re-decoding is expensive.
     */
    private static string $decodedFrom = '';
    private static mixed $decodedTo = null;
    private static bool $holdsDecoded = false;

    /**
     * A stored value as a structure: PHP-serialized data is unserialized with
     * classes disabled (meta can be author-written), JSON text is decoded,
     * anything else is returned as the plain string.
     *
     * **The last decode is kept, and it is kept for one shape of caller**
     * (freshet-366). The reference index reads a row once and then asks every
     * attachment that row could be naming about it, so this is called with the
     * *same* string once per candidate. On one library a single 5.4 MB option
     * row (`itsec_file_change_latest`) has 2,388 candidates, and decoding it
     * 2,388 times is ~19 s of that library's 434 s pass. One slot removes all
     * but the first.
     *
     * It is a memo of one and the comparison is on the string, so a different
     * value simply replaces it — and because the candidate loop hands over the
     * identical `zend_string` each time, the hit is a pointer comparison rather
     * than a 5.4 MB memcmp. `ReferenceIndexer` drops it when a row's candidates
     * are done, so what is held between rows is at most one decoded value.
     *
     * **Only this decode.** OptionsDetector's `theme_mods_` and `widget_`
     * branches call `maybe_unserialize()`, which allows classes where this does
     * not; routing them through here to share the slot would change what they
     * decode to. Shortening the per-candidate *walk* over a big value is the
     * other half of that measurement and is deliberately not done here — it
     * would mean teaching the needles to search a set of ids, which moves
     * evidence labels.
     */
    public static function decodeStored(string $value): mixed
    {
        if (self::$holdsDecoded && self::$decodedFrom === $value) {
            return self::$decodedTo;
        }

        if (is_serialized($value)) {
            $data = @unserialize(trim($value), ['allowed_classes' => false]);
            $decoded = $data === false ? $value : $data;
        } else {
            $decoded = self::decodeJson($value) ?? $value;
        }

        self::$decodedFrom = $value;
        self::$decodedTo = $decoded;
        self::$holdsDecoded = true;

        return $decoded;
    }

    /**
     * Let go of the decoded value above.
     *
     * Called where the loop it exists for ends. A decoded 5.4 MB blob is tens
     * of megabytes of PHP arrays, and the one thing this plugin cannot afford
     * to hold is something that grows with a row rather than with the question
     * (freshet-365 is that failure on a different structure).
     */
    public static function forgetDecoded(): void
    {
        self::$decodedFrom = '';
        self::$decodedTo = null;
        self::$holdsDecoded = false;
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

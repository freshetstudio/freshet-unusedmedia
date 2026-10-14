<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

defined('ABSPATH') || exit;

/**
 * Keys whose value is an integer that is never an attachment id.
 *
 * **Why a list exists at all.** Detection is partly id-based, and a low
 * attachment id is a number the rest of a WordPress database is full of: a
 * reading time in minutes, a form id, a conversion counter, a ticket number. On
 * attachment **7** of a fifteen-year-old library the plugin read 212 references,
 * six of which were real — the sort of figure that makes a user stop believing
 * the screen (freshet-380, freshet-D340). Every one of the wrong ones errs in
 * the safe direction, so nothing was ever deleted; what was lost was the
 * answer's credibility, which on a tool whose whole job is to be trusted before
 * a deletion is the expensive half.
 *
 * **What may go on it, and what may not.** An entry has to be a key whose value
 * is documented — by the plugin that writes it, or by core — as something other
 * than an attachment id. "I have not seen this key hold an id" is not a source;
 * the comment beside each entry names where it comes from. Anything merely
 * *unlikely* stays off the list and is reported as a *possible* match instead,
 * which is what the confidence split is for: a key nobody can vouch for keeps
 * the file and says it is a guess.
 *
 * **It suppresses id matches only.** A denied key that holds the file's actual
 * URL or filename still reads as a confirmed reference — the callers ask this
 * after `containsBasename()`, never before. A filename is not ambiguous, and a
 * key being a counter does not stop a plugin storing a URL beside the count.
 *
 * **And a denied key may still name an attachment outright**, which cost a
 * near-miss worth recording. `w3tc_imageservice` went on this list for its API
 * quota — `usage_hourly`, `limit_monthly` and the rest, which really were 7 on
 * the library that produced freshet-380, in 138 rows. The same value also
 * carries `post_child => 58043`: the WebP copy W3TC made of the row's own file,
 * and a perfectly real attachment reference. Denying the whole row dropped it,
 * and a parity run over 49 attachments of that library turned up three WebP
 * copies that went from *used* to *unused* — three files newly offered for
 * deletion, which is the one direction this plugin may not be wrong in. So a
 * denied row is still read for a link: see LINK_KEYS and
 * `LikePatterns::namesLinkedAttachment()`.
 */
final class NonMediaKeys
{
    /**
     * Exact keys. Core's own counters and sequence numbers — a number stored by
     * core with a meaning core defines, none of which is a post id.
     *
     * `_edit_lock` and `_edit_last` are already dropped from the query entirely
     * (`PostmetaDetector::EXCLUDED_KEYS`) and are not repeated here.
     */
    private const KEYS = [
        // Core comment and view counters on a post.
        '_wp_page_template_count',
        'comment_count',
        'post_views_count',
        // wp-admin's own per-screen state. Serialized arrays of column and
        // metabox slugs, so the integers in them are list positions.
        'screen_layout_dashboard',
        'closedpostboxes_dashboard',
        // A form id written under its bare name — the `formId` attribute of a
        // `gravityforms/block` delimiter, and the `form_id` meta Ninja Forms and
        // Contact Form 7 write. The forms are rows of the form plugin's own
        // tables, not posts, so the number indexes nothing in wp_posts. This
        // class is asked about block attribute names as well as meta keys, which
        // is why the bare spellings are here and not only the suffixed ones.
        'formid',
        'form_id',
        'gf_form_id',
    ];

    /**
     * Key prefixes.
     *
     * - `_yoast_wpseo_` — Yoast SEO's post meta. Its numeric keys are a reading
     *   time in minutes, a word count, an internal-link tally and a content
     *   score (`wordpress-seo/inc/`, `WPSEO_Meta` and the indexable builders).
     *   Its image keys are the exception and are listed in MEDIA_KEYS below.
     * - `w3tc_` — W3 Total Cache. `w3tc_imageservice` is the WebP/AVIF
     *   converter's own record for the attachment the row belongs to: a job id,
     *   a status, byte sizes, and the plugin's own API quota — `usage_hourly`
     *   and `usage_monthly`, which on freshet-380's library really were **7**,
     *   in 138 rows. A number about W3TC's account, not about any file, and the
     *   single largest source of that file's 212 references
     *   (`w3-total-cache/Extension_ImageService_Plugin_Admin.php`). The same
     *   value's `post_child`/`post_children` is a real attachment and survives
     *   the deny — see LINK_KEYS and the class note.
     * - `_yoast_indexable_` / `wpseo_` — the same plugin's other spellings.
     * - `_wp_old_` — core's slug/date history; a date, not an id.
     * - `screen_layout_`, `closedpostboxes_`, `metaboxhidden_`, `manageedit-`,
     *   `meta-box-order_` — wp-admin per-user screen state. Column slugs and
     *   metabox ids, so every integer in one is an array position.
     */
    private const PREFIXES = [
        '_yoast_wpseo_',
        '_yoast_indexable_',
        'wpseo_',
        'w3tc_',
        '_wp_old_',
        'screen_layout_',
        'closedpostboxes_',
        'metaboxhidden_',
        'manageedit-',
        'meta-box-order_',
    ];

    /**
     * Key suffixes.
     *
     * - `_gf_form_id`, `gf_form_id`, `_gravityforms_form_id` — a Gravity Forms
     *   form id. The forms live in their own tables (`gf_form`), so the number
     *   indexes nothing in `wp_posts` (`gravityforms/forms_model.php`). This is
     *   the key that was being reported as a confirmed "ACF field" on
     *   freshet-380's library: an ACF select whose choices are form ids.
     * - `_form_id`, `_formid` — the same field under the spellings the form
     *   plugins and the blocks that embed them use (`formId` in a
     *   `gravityforms/block` delimiter, `form_id` in Ninja Forms' and Contact
     *   Form 7's meta).
     * - `_reading_time`, `_word_count` — written by several SEO and reading-time
     *   plugins under their own prefixes; the unit is minutes or words.
     */
    private const SUFFIXES = [
        '_gf_form_id',
        'gf_form_id',
        '_gravityforms_form_id',
        '_form_id',
        '_formid',
        '_reading_time',
        '_word_count',
    ];

    /**
     * Keys documented to hold an attachment id — the list's other half.
     *
     * It does two things. It carves these out of the `_yoast_wpseo_` prefix
     * above, which would otherwise deny them. And it lets the chains *confirm*
     * them: Yoast's social-image keys are what the plugin writes when an editor
     * picks an OpenGraph or Twitter image, so a bare 7 under one of them is a
     * reference to attachment 7 and not a number that happens to equal it. Both
     * answers come from the same source, which is the point of keeping them in
     * one file.
     *
     * Listed rather than pattern-matched, because "the ones that are images" is
     * exactly the sort of guess this class refuses elsewhere.
     *
     * @see https://developer.yoast.com/features/opengraph/functional-specification/
     */
    private const MEDIA_KEYS = [
        '_yoast_wpseo_opengraph-image-id',
        '_yoast_wpseo_twitter-image-id',
        '_yoast_wpseo_opengraph-image',
        '_yoast_wpseo_twitter-image',
        'wpseo_opengraph-image-id',
        'wpseo_twitter-image-id',
    ];

    /**
     * Is this key's integer value documented as something other than an
     * attachment id?
     *
     * Case-folded, because a meta key is compared case-insensitively by MySQL
     * under every collation WordPress ships: the query that fetched the row does
     * not distinguish `W3TC_imageservice` from `w3tc_imageservice`, so neither
     * does this.
     */
    public static function denies(string $key): bool
    {
        $key = strtolower($key);

        if (self::confirms($key)) {
            return false;
        }

        if (in_array($key, array_map('strtolower', self::KEYS), true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, strtolower($prefix))) {
                return true;
            }
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($key, strtolower($suffix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys that name another post inside an otherwise-denied value.
     *
     * The one thing a bookkeeping record is still allowed to say. `post_child`,
     * `post_children` and `post_parent` are W3 Total Cache's own
     * (`Extension_ImageService_Plugin_Admin::get_post_child()`): the attachment
     * row holding the converted WebP or AVIF, which is a reference like any
     * other. The `/ids?$/i` rule beside them is the one `idKeyedValues()`
     * already applies to core block attributes — "written id or ids, or ending
     * in one" — reused here rather than invented, and it widens what counts,
     * which is the safe direction.
     *
     * @see LikePatterns::namesLinkedAttachment()
     */
    public const LINK_KEYS = ['post_child', 'post_children', 'post_parent'];

    /** Does this key, inside a denied value, name another post? */
    public static function links(string $key): bool
    {
        return in_array(strtolower($key), self::LINK_KEYS, true)
            || (bool) preg_match('/ids?$/i', $key);
    }

    /**
     * Is this key documented to hold an attachment id?
     *
     * The complement of denies(), and used for the same reason: a bare integer
     * is ambiguous unless something says otherwise, and here something does. A
     * key on this list promotes its id match from *possible* to *confirmed* —
     * Yoast's OpenGraph image was reading as a guess, beside a reading time that
     * was reading as a guess, on the same prefix.
     */
    public static function confirms(string $key): bool
    {
        return in_array(strtolower($key), array_map('strtolower', self::MEDIA_KEYS), true);
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

defined('ABSPATH') || exit;

/**
 * What ACF says one of its fields holds.
 *
 * **The thing this replaces.** Five detector chains used to confirm a reference
 * on one fact: that the value's sibling meta — ACF's `_<key>` row — begins
 * `field_`. That proves the value is an ACF field's data. It does not prove the
 * field holds an attachment, and the two were being treated as the same
 * sentence. On freshet-380's library the result was an ACF **select** of Gravity
 * Forms form ids (`event_gf_form_id`) reported as a confirmed *ACF field* use of
 * attachment 7, and three repeater **row counts** (`partners: 7`,
 * `cta: 7`) reported the same way from inside block delimiters. Four of the five
 * confirmed references on that file were fields ACF itself describes as holding
 * something else.
 *
 * ACF can be asked, so it is asked. `acf_get_field()` resolves a field key to
 * its definition — including for a sub-field of a repeater or a flexible-content
 * layout, which is where the plugin's own id-shaped values live.
 *
 * **Three answers, not two.** `true` and `false` are ACF's; `null` means ACF
 * could not be consulted at all — the plugin is not loaded, or the field key
 * belongs to a group that no longer exists. A `null` keeps the file (the match
 * still stands) and reports it as *possible*, which is the honest reading: a
 * `field_` sibling is evidence that somebody's field wrote the value, and
 * nothing more.
 */
final class AcfField
{
    /**
     * Field types whose value is an attachment id, or a list of them.
     *
     * The four ACF ships that reference the media library, and that is the whole
     * list — an `oembed` holds a URL to somebody else's site, a `link` holds an
     * array with a URL in it, and neither is a library reference.
     *
     * @see https://www.advancedcustomfields.com/resources/#field-types
     */
    private const MEDIA_TYPES = ['image', 'file', 'gallery'];

    /**
     * Field types that hold a post id, which an attachment id is one kind of.
     *
     * Whether one of these can name an attachment depends on its own
     * `post_type` setting: restricted to `page`, it cannot; restricted to
     * `attachment`, it is as good as an image field; left unrestricted, it may
     * be either and the answer is a guess rather than a fact.
     */
    private const POST_TYPES = ['post_object', 'relationship'];

    /**
     * Does the field this key names hold an attachment id?
     *
     * @param string $fieldKey ACF's own field key — the `field_…` value stored
     *                         in the sibling meta row, or beside the value in a
     *                         block delimiter's `data` map.
     * @return bool|null true when ACF says the field references the media
     *                   library, false when ACF says it holds something else,
     *                   null when ACF could not answer (see the class note).
     */
    public static function namesAttachment(string $fieldKey): ?bool
    {
        $field = self::definition($fieldKey);

        if ($field === null) {
            return null;
        }

        $type = (string) ($field['type'] ?? '');

        if (in_array($type, self::MEDIA_TYPES, true)) {
            return true;
        }

        if (!in_array($type, self::POST_TYPES, true)) {
            return false;
        }

        $postTypes = array_filter(array_map('strval', (array) ($field['post_type'] ?? [])));

        if ($postTypes === []) {
            // Unrestricted: every post type, attachments included. ACF has not
            // said no, and it has not said yes either.
            return null;
        }

        return in_array('attachment', $postTypes, true) ? true : false;
    }

    /**
     * ACF's definition of one field key, or null if ACF cannot give one.
     *
     * `acf_get_field()` is ACF's own public resolver and reads from wherever the
     * group was registered — PHP, local JSON or the database — so this works on
     * a site whose fields are declared in code and on one whose fields are rows.
     * It is wrapped rather than called inline because the five chains that need
     * it must agree about what counts as an answer, and because a lookup per
     * candidate row is exactly the sort of thing the reference index runs
     * millions of: ACF caches a resolved field for the request, and the guard
     * below means a site without ACF never pays for a function call at all.
     *
     * @return array<string, mixed>|null
     */
    private static function definition(string $fieldKey): ?array
    {
        if (!str_starts_with($fieldKey, 'field_') || !function_exists('acf_get_field')) {
            return null;
        }

        $field = acf_get_field($fieldKey);

        return is_array($field) && isset($field['type']) ? $field : null;
    }
}

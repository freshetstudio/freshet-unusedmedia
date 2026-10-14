<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\PossibleOnly;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * "Usage" on the attachment edit screen (a meta box) and in the media modal (a
 * compat field): the status badge and the evidence list. Also the single
 * shared renderer for evidence fragments (reused by the AJAX single-check
 * response).
 */
final class AttachmentMetaBox
{
    private const CAP = 'upload_files';

    public function __construct(private readonly ResultStore $store)
    {
    }

    public function hooks(): void
    {
        add_action('add_meta_boxes_attachment', [$this, 'register']);
        add_filter('attachment_fields_to_edit', [$this, 'modalField'], 10, 2);
    }

    public function register(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        add_meta_box(
            'freshet-unusedmedia-usage',
            __('Usage', 'freshet-unused-media'),
            [$this, 'render'],
            'attachment',
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        echo $this->panelHtml($post->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in panelHtml().
    }

    /**
     * Adds the same panel to the media modal.
     *
     * The modal has no meta boxes at all: 'add_meta_boxes_attachment' fires
     * only from register_and_do_post_meta_boxes(), whose only callers are the
     * two post.php edit forms. The one place core lets a plugin put its own
     * markup on the modal's details panel is this field list — built by
     * get_compat_media_markup() with 'in_modal' => true and handed to the
     * modal inside wp_prepare_attachment_for_js()'s 'compat' key.
     *
     * 'show_in_edit' => false keeps it out of the same field list on the
     * attachment edit screen, where the meta box above already renders it.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function modalField(array $fields, \WP_Post $post): array
    {
        if ($post->post_type !== 'attachment' || !current_user_can(self::CAP)) {
            return $fields;
        }

        $fields['freshet-unusedmedia-usage'] = [
            'label' => __('Usage', 'freshet-unused-media'),
            'input' => 'html',
            'html' => $this->panelHtml($post->ID),
            'show_in_edit' => false,
        ];

        return $fields;
    }

    /** The whole panel — evidence plus the check button. Escaped HTML. */
    public function panelHtml(int $attachmentId): string
    {
        return '<div class="freshet-unusedmedia-box" data-attachment="' . esc_attr((string) $attachmentId) . '">'
            . wp_kses_post($this->renderEvidence($attachmentId))
            . sprintf(
                '<p><button type="button" class="button freshet-unusedmedia-check" data-id="%s">%s</button></p>',
                esc_attr((string) $attachmentId),
                esc_html($this->store->status($attachmentId) === null
                    ? __('Check usage', 'freshet-unused-media')
                    : __('Rescan', 'freshet-unused-media'))
            )
            . '</div>';
    }

    /** Full evidence fragment (status + list) for an attachment. Escaped HTML. */
    public function renderEvidence(int $attachmentId): string
    {
        try {
            $file = FileGroups::fileStatus($attachmentId);
        } catch (QueryFailed) {
            // Nothing below can be said without the group's verdict, and a
            // panel that fell back to the stored meta would print the answer of
            // a scan this read just failed to confirm (freshet-141). The badge
            // is the whole answer: the sentence under it said "this could not
            // be checked" beneath a badge reading "Couldn't check" (freshet-D287).
            return '<p>' . StatusBadge::unavailable() . '</p>';
        }

        $status = $this->store->status($attachmentId);
        $data = $this->store->refs($attachmentId);

        $html = '<p>' . StatusBadge::forRow($attachmentId, $this->store) . '</p>';

        // A row held back by a sibling may never have been scanned itself, and
        // an evidence list under a badge saying the file is in use is the blank
        // this whole panel exists to stop showing. A row in the trash with every
        // sibling is held by nothing, so it takes this branch when it was never
        // scanned and shows its own evidence when it was.
        //
        // The guard stays; its sentence does not. "Not scanned yet." sat under a
        // badge reading "Not scanned" and above a button reading "Check usage",
        // which is the same fact three times (freshet-D287).
        if ($status === null && $file['held'] === FileGroups::HELD_NONE) {
            return $html;
        }

        // The reason lives on another row, so this one's own evidence list is
        // empty and would read as "nothing refers to it" directly under a badge
        // saying the file is in use.
        if ($file['held'] === FileGroups::HELD_SIBLING) {
            return $html . $this->siblingEvidence($file['used'][0]);
        }

        // No sentence under the badge: it reads "Unused" or "In trash", which
        // is what an empty evidence list means (freshet-D287). The phrase now
        // renders in one place only, the Used view, where an empty list sits
        // under a heading that says the file IS used.
        if ($data['refs'] === []) {
            return $html;
        }

        // No "…and N more." line: the badge above the list already reads
        // "Used (34 references)" over the twenty that are stored, so the
        // remainder was the screen subtracting for the reader (freshet-D287).

        return $html . $this->referenceList($data);
    }

    /**
     * The evidence, with what the scan can state kept apart from what it can
     * only guess.
     *
     * **Two lists, because they answer two different questions.** A confirmed
     * reference is a place: a page, a term, a widget, with a link to it; the
     * upload record is beside it, marked as the record it is. A possible one is
     * a number somewhere that equals this file's id and might mean it — and on
     * a long-lived library there can be hundreds, which is how a file with six
     * real uses came to report 212 (freshet-380). Mixed into one
     * list they drown the evidence; counted into one figure they produce a
     * sentence nobody can check.
     *
     * So the possibles are collapsed behind their own count, open-on-click,
     * worded so a person can judge them — the same references, the same
     * renderer, and no number added to anything.
     *
     * @param array{count: int, confirmed: int, possible: int, refs: Reference[]} $data
     */
    private function referenceList(array $data): string
    {
        $stated = [];
        $possible = [];

        // Only a guess is collapsed. There are three confidences, not two, and
        // the third is a statement of fact: the upload record says where the
        // file was first attached and is shown *as a record*, not as a use. Put
        // behind the possibles' summary it is described as "a number or a
        // stored value that could be this file's ID", which it is not — it is
        // the one line on an unused file's box that was never in doubt
        // (freshet-367 row 6b (5), against the listing shots).
        foreach ($data['refs'] as $ref) {
            if ($ref->confidence === Reference::POSSIBLE) {
                $possible[] = $ref;
            } else {
                $stated[] = $ref;
            }
        }

        $html = $stated === [] ? '' : $this->list($stated);

        if ($possible === []) {
            return $html;
        }

        // The figure is the whole set's, not this stored slice's: a scan keeps
        // the first twenty references and the count of all of them, and the
        // honest sentence is the one about all of them.
        $shown = $data['possible'] > 0 ? $data['possible'] : count($possible);

        return $html
            . '<details class="freshet-unusedmedia-possible"><summary>'
            . esc_html(PossibleOnly::explanation($shown))
            . '</summary>'
            . $this->list($possible)
            . '</details>';
    }

    /**
     * One list of references. Escaped HTML.
     *
     * @param Reference[] $refs
     */
    private function list(array $refs): string
    {
        $html = '<ul class="freshet-unusedmedia-refs">';

        foreach ($refs as $ref) {
            $html .= '<li>' . $this->renderReference($ref) . '</li>';
        }

        return $html . '</ul>';
    }

    /**
     * The evidence for a row whose file is kept by another library entry.
     *
     * The choice this makes, and it is the point of the method: it names the
     * entry that holds the file and shows *that* entry's references, through
     * the same renderer this row's own would use. The alternatives were a bare
     * "the file is shared", which leaves the user to go looking, and the row's
     * own empty list, which says the opposite of the truth. The reason is one
     * click away instead of on another screen.
     *
     * Escaped HTML.
     */
    private function siblingEvidence(int $otherId): string
    {
        $title = get_the_title($otherId);
        $title = $title !== '' ? $title : sprintf('#%d', $otherId);
        $link = get_edit_post_link($otherId);

        $html = '<p class="description">' . sprintf(
            /* translators: %s: link to the other library entry that holds the file */
            esc_html__('The reference is on another library entry pointing at the same file: %s', 'freshet-unused-media'),
            $link !== null
                ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>'
                : '<strong>' . esc_html($title) . '</strong>'
        ) . '</p>';

        $data = $this->store->refs($otherId);

        if ($data['refs'] === []) {
            return $html;
        }

        return $html . $this->referenceList($data);
    }

    /** One reference, as a line of escaped HTML. Shared with the Used view. */
    public function renderReference(Reference $ref): string
    {
        $label = self::matchLabel($ref->match);
        $where = $this->whereHtml($ref);

        $confidence = match ($ref->confidence) {
            Reference::CONFIRMED => '',
            Reference::INFO => ' <em>(' . esc_html__('record only — not a content reference', 'freshet-unused-media') . ')</em>',
            default => ' <em>(' . esc_html__('possible', 'freshet-unused-media') . ')</em>',
        };

        $detail = '';

        if (in_array($ref->objectType, ['post', 'term', 'user', 'comment'], true) && $ref->detail !== '' && $ref->detail !== $ref->match) {
            $detail = ' <code>' . esc_html($ref->detail) . '</code>';
        }

        return '<strong>' . esc_html($label) . '</strong> — ' . $where . $detail . $confidence;
    }

    private function whereHtml(Reference $ref): string
    {
        switch ($ref->objectType) {
            case 'post':
                $title = get_the_title($ref->objectId);
                $title = $title !== '' ? $title : sprintf('#%d', $ref->objectId);
                $link = get_edit_post_link($ref->objectId);

                return $link !== null
                    ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>'
                    : esc_html($title);

            case 'term':
                $term = get_term($ref->objectId);

                if ($term instanceof \WP_Term) {
                    $link = get_edit_term_link($term);

                    return $link !== null
                        ? '<a href="' . esc_url($link) . '">' . esc_html($term->name) . '</a>'
                        : esc_html($term->name);
                }

                return esc_html(sprintf(
                    /* translators: %d: term ID */
                    __('term #%d', 'freshet-unused-media'),
                    $ref->objectId
                ));

            case 'user':
                $user = get_userdata($ref->objectId);
                $name = $user !== false ? $user->display_name : sprintf(
                    /* translators: %d: user ID */
                    __('user #%d', 'freshet-unused-media'),
                    $ref->objectId
                );

                return '<a href="' . esc_url(get_edit_user_link($ref->objectId)) . '">' . esc_html($name) . '</a>';

            case 'comment':
                $comment = get_comment($ref->objectId);
                $label = $comment instanceof \WP_Comment && $comment->comment_author !== ''
                    ? sprintf(
                        /* translators: %s: comment author name */
                        __('comment by %s', 'freshet-unused-media'),
                        $comment->comment_author
                    )
                    : sprintf(
                        /* translators: %d: comment ID */
                        __('comment #%d', 'freshet-unused-media'),
                        $ref->objectId
                    );
                $link = get_edit_comment_link($ref->objectId);

                return is_string($link) && $link !== ''
                    ? '<a href="' . esc_url($link) . '">' . esc_html($label) . '</a>'
                    : esc_html($label);

            case 'theme_mod':
                return '<a href="' . esc_url(admin_url('customize.php')) . '">' . esc_html__('Customizer', 'freshet-unused-media') . '</a>';

            default:
                return '<code>' . esc_html($ref->detail) . '</code>';
        }
    }

    public static function matchLabel(string $match): string
    {
        return match ($match) {
            'acf' => __('ACF field', 'freshet-unused-media'),
            'acf-block' => __('Block field', 'freshet-unused-media'),
            'shortcode' => __('Shortcode attribute', 'freshet-unused-media'),
            'id-attribute' => __('ID in markup', 'freshet-unused-media'),
            'attachment-page' => __('Attachment page link', 'freshet-unused-media'),
            'autosave' => __('Unsaved edit', 'freshet-unused-media'),
            'recent-upload' => __('Uploaded recently', 'freshet-unused-media'),
            'thumbnail' => __('Featured image', 'freshet-unused-media'),
            'woo-gallery' => __('Product gallery', 'freshet-unused-media'),
            'elementor' => __('Elementor content', 'freshet-unused-media'),
            'block-id' => __('Block attribute', 'freshet-unused-media'),
            'wp-image-class' => __('Image in content', 'freshet-unused-media'),
            'gallery' => __('Gallery', 'freshet-unused-media'),
            'url' => __('File URL', 'freshet-unused-media'),
            'widget' => __('Widget', 'freshet-unused-media'),
            'theme-mod' => __('Customizer setting', 'freshet-unused-media'),
            'site-option' => __('Site setting', 'freshet-unused-media'),
            'serialized' => __('Stored data', 'freshet-unused-media'),
            'comma-list' => __('ID list', 'freshet-unused-media'),
            'attached' => __('Uploaded to', 'freshet-unused-media'),
            'file-claim' => __('Shared file', 'freshet-unused-media'),
            default => __('ID value', 'freshet-unused-media'),
        };
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * "Usage" on the attachment edit screen (a meta box) and in the media modal (a
 * compat field): status, scan time and the evidence list. Also the single
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
        $file = FileGroups::fileStatus($attachmentId);
        $status = $this->store->status($attachmentId);
        $scannedAt = $this->store->scannedAt($attachmentId);
        $data = $this->store->refs($attachmentId);

        $html = '<p>' . StatusBadge::forRow($attachmentId, $this->store) . '</p>';

        // A row held back by a sibling may never have been scanned itself, and
        // "not scanned yet" under a badge saying the file is in use is the
        // blank this whole panel exists to stop showing.
        if ($status === null && $file['held'] === FileGroups::HELD_NONE) {
            return $html . '<p class="description">' . esc_html__('Not scanned yet.', 'freshet-unused-media') . '</p>';
        }

        if ($scannedAt > 0) {
            $note = sprintf(
                /* translators: %s: human time diff */
                __('Scanned %s ago.', 'freshet-unused-media'),
                human_time_diff($scannedAt)
            );

            $changedAt = (int) get_option('freshet_unusedmedia_content_changed_at', 0);

            if ($changedAt > $scannedAt) {
                $note .= ' ' . __('Content has changed since — result may be stale.', 'freshet-unused-media');
            }

            $html .= '<p class="description">' . esc_html($note) . '</p>';
        }

        // The reason lives on another row, so this one's own evidence list is
        // empty and would read as "nothing refers to it" directly under a badge
        // saying the file is in use.
        if ($file['held'] === FileGroups::HELD_SIBLING) {
            return $html . $this->siblingEvidence($file['used'][0]);
        }

        if ($data['refs'] === []) {
            return $html . '<p class="description">' . esc_html__('No references found anywhere.', 'freshet-unused-media') . '</p>';
        }

        $html .= '<ul class="freshet-unusedmedia-refs">';

        foreach ($data['refs'] as $ref) {
            $html .= '<li>' . $this->renderReference($ref) . '</li>';
        }

        $html .= '</ul>';

        if ($data['count'] > count($data['refs'])) {
            $html .= '<p class="description">' . esc_html(sprintf(
                /* translators: %d: number of additional references */
                __('…and %d more.', 'freshet-unused-media'),
                $data['count'] - count($data['refs'])
            )) . '</p>';
        }

        return $html;
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

        $html .= '<ul class="freshet-unusedmedia-refs">';

        foreach ($data['refs'] as $ref) {
            $html .= '<li>' . $this->renderReference($ref) . '</li>';
        }

        return $html . '</ul>';
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
            default => __('ID value', 'freshet-unused-media'),
        };
    }
}

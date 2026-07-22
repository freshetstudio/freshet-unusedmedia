<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * "Usage" meta box on the attachment edit screen: status, scan time and the
 * evidence list. Also the single shared renderer for evidence fragments
 * (reused by the AJAX single-check response).
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
    }

    public function register(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        add_meta_box(
            'freshet-unusedmedia-usage',
            __('Usage', 'freshet-unusedmedia'),
            [$this, 'render'],
            'attachment',
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        echo '<div class="freshet-unusedmedia-box" data-attachment="' . esc_attr((string) $post->ID) . '">';
        echo wp_kses_post($this->renderEvidence($post->ID));

        printf(
            '<p><button type="button" class="button freshet-unusedmedia-check" data-id="%s">%s</button></p>',
            esc_attr((string) $post->ID),
            esc_html($this->store->status($post->ID) === null
                ? __('Check usage', 'freshet-unusedmedia')
                : __('Rescan', 'freshet-unusedmedia'))
        );

        echo '</div>';
    }

    /** Full evidence fragment (status + list) for an attachment. Escaped HTML. */
    public function renderEvidence(int $attachmentId): string
    {
        $status = $this->store->status($attachmentId);
        $scannedAt = $this->store->scannedAt($attachmentId);
        $data = $this->store->refs($attachmentId);

        $html = '<p>' . StatusBadge::render($status, $data['count']) . '</p>';

        if ($status === null) {
            return $html . '<p class="description">' . esc_html__('Not scanned yet.', 'freshet-unusedmedia') . '</p>';
        }

        if ($scannedAt > 0) {
            $note = sprintf(
                /* translators: %s: human time diff */
                __('Scanned %s ago.', 'freshet-unusedmedia'),
                human_time_diff($scannedAt)
            );

            $changedAt = (int) get_option('freshet_unusedmedia_content_changed_at', 0);

            if ($changedAt > $scannedAt) {
                $note .= ' ' . __('Content has changed since — result may be stale.', 'freshet-unusedmedia');
            }

            $html .= '<p class="description">' . esc_html($note) . '</p>';
        }

        if ($data['refs'] === []) {
            return $html . '<p class="description">' . esc_html__('No references found anywhere.', 'freshet-unusedmedia') . '</p>';
        }

        $html .= '<ul class="freshet-unusedmedia-refs">';

        foreach ($data['refs'] as $ref) {
            $html .= '<li>' . $this->renderReference($ref) . '</li>';
        }

        $html .= '</ul>';

        if ($data['count'] > count($data['refs'])) {
            $html .= '<p class="description">' . esc_html(sprintf(
                /* translators: %d: number of additional references */
                __('…and %d more.', 'freshet-unusedmedia'),
                $data['count'] - count($data['refs'])
            )) . '</p>';
        }

        return $html;
    }

    private function renderReference(Reference $ref): string
    {
        $label = self::matchLabel($ref->match);
        $where = $this->whereHtml($ref);

        $confidence = match ($ref->confidence) {
            Reference::CONFIRMED => '',
            Reference::INFO => ' <em>(' . esc_html__('record only — not a content reference', 'freshet-unusedmedia') . ')</em>',
            default => ' <em>(' . esc_html__('possible', 'freshet-unusedmedia') . ')</em>',
        };

        $detail = '';

        if (in_array($ref->objectType, ['post', 'term', 'user'], true) && $ref->detail !== '' && $ref->detail !== $ref->match) {
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
                    __('term #%d', 'freshet-unusedmedia'),
                    $ref->objectId
                ));

            case 'user':
                $user = get_userdata($ref->objectId);
                $name = $user !== false ? $user->display_name : sprintf(
                    /* translators: %d: user ID */
                    __('user #%d', 'freshet-unusedmedia'),
                    $ref->objectId
                );

                return '<a href="' . esc_url(get_edit_user_link($ref->objectId)) . '">' . esc_html($name) . '</a>';

            case 'theme_mod':
                return '<a href="' . esc_url(admin_url('customize.php')) . '">' . esc_html__('Customizer', 'freshet-unusedmedia') . '</a>';

            default:
                return '<code>' . esc_html($ref->detail) . '</code>';
        }
    }

    public static function matchLabel(string $match): string
    {
        return match ($match) {
            'acf' => __('ACF field', 'freshet-unusedmedia'),
            'thumbnail' => __('Featured image', 'freshet-unusedmedia'),
            'woo-gallery' => __('Product gallery', 'freshet-unusedmedia'),
            'elementor' => __('Elementor content', 'freshet-unusedmedia'),
            'block-id' => __('Block attribute', 'freshet-unusedmedia'),
            'wp-image-class' => __('Image in content', 'freshet-unusedmedia'),
            'gallery' => __('Gallery', 'freshet-unusedmedia'),
            'url' => __('File URL', 'freshet-unusedmedia'),
            'widget' => __('Widget', 'freshet-unusedmedia'),
            'theme-mod' => __('Customizer setting', 'freshet-unusedmedia'),
            'site-option' => __('Site setting', 'freshet-unusedmedia'),
            'serialized' => __('Stored data', 'freshet-unusedmedia'),
            'comma-list' => __('ID list', 'freshet-unusedmedia'),
            'attached' => __('Uploaded to', 'freshet-unusedmedia'),
            default => __('ID value', 'freshet-unusedmedia'),
        };
    }
}

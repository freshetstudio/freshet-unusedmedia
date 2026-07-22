<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * Media library (list mode) integration: Usage column, status filter and a
 * "Check usage" row action.
 */
final class MediaColumn
{
    private const CAP = 'upload_files';

    public function __construct(private readonly ResultStore $store)
    {
    }

    public function hooks(): void
    {
        add_filter('manage_media_columns', [$this, 'addColumn']);
        add_action('manage_media_custom_column', [$this, 'renderColumn'], 10, 2);
        add_filter('media_row_actions', [$this, 'rowActions'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'filterDropdown'], 10, 2);
        add_action('pre_get_posts', [$this, 'applyFilter']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hookSuffix): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $isAttachmentEdit = $hookSuffix === 'post.php' && get_post_type((int) ($_GET['post'] ?? 0)) === 'attachment'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.

        if ($hookSuffix === 'upload.php' || $isAttachmentEdit) {
            Assets::enqueue();
        }
    }

    public function addColumn(array $columns): array
    {
        $columns['freshet_unusedmedia'] = __('Usage', 'freshet-unusedmedia');

        return $columns;
    }

    public function renderColumn(string $column, int $attachmentId): void
    {
        if ($column !== 'freshet_unusedmedia') {
            return;
        }

        $status = $this->store->status($attachmentId);
        $count = $status === ResultStore::STATUS_USED ? $this->store->refs($attachmentId)['count'] : 0;

        printf(
            '<span class="freshet-unusedmedia-cell" data-id="%d">%s</span>',
            (int) $attachmentId,
            StatusBadge::render($status, $count) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge HTML escaped in StatusBadge::render().
        );
    }

    public function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== 'attachment' || !current_user_can(self::CAP)) {
            return $actions;
        }

        $fallback = wp_nonce_url(
            add_query_arg(['action' => 'freshet_unusedmedia_check_single', 'attachment' => $post->ID], admin_url('admin-post.php')),
            'freshet_unusedmedia_check_single'
        );

        $actions['freshet_unusedmedia_check'] = sprintf(
            '<a href="%s" class="freshet-unusedmedia-check" data-id="%d">%s</a>',
            esc_url($fallback),
            (int) $post->ID,
            esc_html__('Check usage', 'freshet-unusedmedia')
        );

        return $actions;
    }

    public function filterDropdown(string $postType): void
    {
        if ($postType !== 'attachment') {
            return;
        }

        $current = sanitize_key(wp_unslash($_GET['freshet_unusedmedia_status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only list filter.

        $options = [
            '' => __('Any usage status', 'freshet-unusedmedia'),
            ResultStore::STATUS_USED => __('Used', 'freshet-unusedmedia'),
            ResultStore::STATUS_UNUSED => __('Unused', 'freshet-unusedmedia'),
            'unscanned' => __('Not scanned', 'freshet-unusedmedia'),
        ];

        echo '<select name="freshet_unusedmedia_status">';

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    public function applyFilter(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['freshet_unusedmedia_status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only list filter.

        if ($status === '') {
            return;
        }

        if ($status === 'unscanned') {
            $query->set('meta_query', [[
                'key' => ResultStore::META_STATUS,
                'compare' => 'NOT EXISTS',
            ]]);

            return;
        }

        if (in_array($status, [ResultStore::STATUS_USED, ResultStore::STATUS_UNUSED], true)) {
            $query->set('meta_query', [[
                'key' => ResultStore::META_STATUS,
                'value' => $status,
            ]]);
        }
    }
}

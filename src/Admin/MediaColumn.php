<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
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
        add_filter('posts_where', [$this, 'applyFilter'], 10, 2);
        add_filter('the_posts', [$this, 'primeGroups'], 10, 2);
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

    /**
     * Resolve every listed row's sibling group in one query, before the column
     * starts rendering.
     *
     * The Usage column judges a row by its **file**, so each cell has to know
     * which other rows stand on that path. Left to the column that is one
     * lookup per thumbnail, and it is the expensive shape: `wp_postmeta` is
     * indexed on `meta_key` and `post_id`, never on `meta_value`, so each one
     * scans every attached-file row in the library — twenty scans of a
     * five-figure key for one default page of a large library. Once here it is
     * one, and the column renders from the memo.
     *
     * A no-op on any other query, and never a precondition: FileGroups answers
     * an unprimed row by querying for it, so every verdict is the same whether
     * this ran or not.
     *
     * @param \WP_Post[] $posts
     * @return \WP_Post[]
     */
    public function primeGroups(array $posts, \WP_Query $query): array
    {
        if ($posts === [] || !is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
            return $posts;
        }

        FileGroups::prime(wp_list_pluck($posts, 'ID'));

        return $posts;
    }

    public function addColumn(array $columns): array
    {
        $columns['freshet_unusedmedia'] = __('Usage', 'freshet-unused-media');

        return $columns;
    }

    public function renderColumn(string $column, int $attachmentId): void
    {
        if ($column !== 'freshet_unusedmedia') {
            return;
        }

        printf(
            '<span class="freshet-unusedmedia-cell" data-id="%d">%s</span>',
            (int) $attachmentId,
            StatusBadge::forRow($attachmentId, $this->store) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge HTML escaped in StatusBadge.
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
            esc_html__('Check usage', 'freshet-unused-media')
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
            '' => __('Any usage status', 'freshet-unused-media'),
            ResultStore::STATUS_USED => __('Used', 'freshet-unused-media'),
            ResultStore::STATUS_UNUSED => __('Unused', 'freshet-unused-media'),
            FileGroups::STATUS_UNSCANNED => __('Not scanned', 'freshet-unused-media'),
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

    /**
     * Narrow the library to the rows whose **file** carries the chosen verdict.
     *
     * A meta_query on the row's own scan status is the defect the badges above
     * it just stopped having: it lists rows the Tools screen will never offer
     * for deletion, and hides rows whose badge beside it reads used. The set
     * comes from the same grouped subquery every count and every listing reads
     * — see FileGroups::rowsWithStatusSql(), which is also why this is a WHERE
     * fragment and not a meta_query.
     *
     * "Not scanned" changes meaning with it, and correctly: it now selects
     * files nothing has decided about — some rows scanned and some not, or a
     * copy in the trash — rather than rows missing a meta key. That is the set
     * the Tools screen counts under the same word.
     */
    public function applyFilter(string $where, \WP_Query $query): string
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'attachment') {
            return $where;
        }

        $status = sanitize_key(wp_unslash($_GET['freshet_unusedmedia_status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only list filter.

        if (!in_array($status, [ResultStore::STATUS_USED, ResultStore::STATUS_UNUSED, FileGroups::STATUS_UNSCANNED], true)) {
            return $where;
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL built from this plugin's own constants; $status is one of the three checked above.
        return $where . ' AND ' . $wpdb->posts . '.ID IN (' . FileGroups::rowsWithStatusSql($status) . ')';
    }
}

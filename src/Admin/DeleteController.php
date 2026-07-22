<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;

defined('ABSPATH') || exit;

/**
 * Deletion of verified-unused attachments. Every ID is re-scanned
 * immediately before deletion — anything that became used is skipped.
 */
final class DeleteController
{
    public function __construct(
        private readonly Scanner $scanner,
        private readonly ResultStore $store,
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_post_freshet_unusedmedia_delete_selected', [$this, 'deleteSelected']);
    }

    /** Handles the checkbox form on the tools page. */
    public function deleteSelected(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not allowed.', 'freshet-unusedmedia'));
        }

        check_admin_referer('freshet_unusedmedia_delete_selected');

        $ids = array_map('absint', (array) ($_POST['attachments'] ?? []));
        $ids = array_values(array_filter($ids));

        $result = $ids === []
            ? ['deleted' => 0, 'skipped' => 0, 'failed' => 0]
            : $this->deleteVerified($ids);

        wp_safe_redirect(add_query_arg([
            'page' => ToolsPage::SLUG,
            'freshet_unusedmedia_deleted' => $result['deleted'],
            'freshet_unusedmedia_skipped' => $result['skipped'],
            'freshet_unusedmedia_failed' => $result['failed'],
        ], admin_url('upload.php')));

        exit;
    }

    /**
     * Re-verify and delete. Trash vs permanent is core's call: with
     * MEDIA_TRASH enabled wp_delete_attachment() trashes, otherwise it
     * deletes permanently.
     *
     * @param int[] $ids
     * @return array{deleted: int, skipped: int, failed: int}
     */
    public function deleteVerified(array $ids): array
    {
        $deleted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if (get_post_type($id) !== 'attachment' || !current_user_can('delete_post', $id)) {
                ++$failed;
                $this->store->clear($id); // Drop from the unused pool so batch loops terminate.
                continue;
            }

            // Fresh scan right before deletion — the cached result may be stale.
            $result = $this->scanner->scan($id);

            if ($result['status'] === ResultStore::STATUS_USED) {
                ++$skipped; // Cache already updated by the scan; it leaves the unused pool.
                continue;
            }

            if (wp_delete_attachment($id, false) instanceof \WP_Post) {
                ++$deleted;
            } else {
                ++$failed;
                $this->store->clear($id);
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed];
    }
}

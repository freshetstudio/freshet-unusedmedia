<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;

defined('ABSPATH') || exit;

/**
 * AJAX endpoints: single attachment check, full-scan batches, scan reset and
 * the delete-all-unused batches. Plus the no-JS fallback for the row action.
 */
final class Ajax
{
    public function __construct(
        private readonly Scanner $scanner,
        private readonly ResultStore $store,
        private readonly ScanState $state,
        private readonly DeleteController $deleter,
        private readonly AttachmentMetaBox $metaBox,
    ) {
    }

    public function hooks(): void
    {
        add_action('wp_ajax_freshet_unusedmedia_check', [$this, 'check']);
        add_action('wp_ajax_freshet_unusedmedia_scan_batch', [$this, 'scanBatch']);
        add_action('wp_ajax_freshet_unusedmedia_scan_reset', [$this, 'scanReset']);
        add_action('wp_ajax_freshet_unusedmedia_delete_batch', [$this, 'deleteBatch']);
        add_action('admin_post_freshet_unusedmedia_check_single', [$this, 'checkSingleFallback']);
    }

    /** Single on-demand check (row action, meta box rescan). */
    public function check(): void
    {
        check_ajax_referer('freshet_unusedmedia_ajax');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unusedmedia')], 403);
        }

        $id = absint($_POST['id'] ?? 0);

        if ($id === 0 || get_post_type($id) !== 'attachment') {
            wp_send_json_error(['message' => __('Unknown attachment.', 'freshet-unusedmedia')], 400);
        }

        $result = $this->scanner->scan($id);
        $count = $this->store->refs($id)['count'];

        wp_send_json_success([
            'status' => $result['status'],
            'badge' => StatusBadge::render($result['status'], $result['status'] === ResultStore::STATUS_USED ? $count : 0),
            'evidence' => $this->metaBox->renderEvidence($id),
        ]);
    }

    /** No-JS fallback for the media row action: scan, then bounce back. */
    public function checkSingleFallback(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Not allowed.', 'freshet-unusedmedia'));
        }

        check_admin_referer('freshet_unusedmedia_check_single');

        $id = absint($_GET['attachment'] ?? 0);

        if ($id !== 0 && get_post_type($id) === 'attachment') {
            $this->scanner->scan($id);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('upload.php'));
        exit;
    }

    /** One batch of the full-library scan. */
    public function scanBatch(): void
    {
        check_ajax_referer('freshet_unusedmedia_manage');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unusedmedia')], 403);
        }

        global $wpdb;

        $state = $this->state->current();

        if ($state === null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simple count to size the scan.
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
            $state = $this->state->start($total);
        }

        $batchSize = max(1, (int) apply_filters('freshet_unusedmedia_batch_size', 10));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ID-ascending cursor batch; picks up mid-scan uploads.
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
            $state['cursor'],
            $batchSize
        )));

        if ($ids === []) {
            $unused = $this->store->counts()['unused'];
            $this->state->finish($unused);

            wp_send_json_success([
                'finished' => true,
                'done' => $state['done'],
                'total' => $state['total'],
                'unused' => $unused,
            ]);
        }

        foreach ($ids as $id) {
            $this->scanner->scan($id);
        }

        $state = $this->state->advance((int) end($ids), count($ids));

        wp_send_json_success([
            'finished' => false,
            'done' => $state['done'],
            'total' => max($state['total'], $state['done']),
        ]);
    }

    public function scanReset(): void
    {
        check_ajax_referer('freshet_unusedmedia_manage');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unusedmedia')], 403);
        }

        $this->state->reset();
        wp_send_json_success();
    }

    /** One batch of delete-all-unused: each ID is re-verified before deletion. */
    public function deleteBatch(): void
    {
        check_ajax_referer('freshet_unusedmedia_manage');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unusedmedia')], 403);
        }

        $ids = $this->store->unusedIds(5);

        if ($ids === []) {
            wp_send_json_success(['finished' => true, 'deleted' => 0, 'skipped' => 0, 'failed' => 0]);
        }

        $result = $this->deleter->deleteVerified($ids);

        wp_send_json_success([
            'finished' => false,
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
        ]);
    }
}

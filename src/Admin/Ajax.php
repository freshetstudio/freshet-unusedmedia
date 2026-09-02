<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultFilters;
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
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unused-media')], 403);
        }

        $id = absint($_POST['id'] ?? 0);

        if ($id === 0 || get_post_type($id) !== 'attachment') {
            wp_send_json_error(['message' => __('Unknown attachment.', 'freshet-unused-media')], 400);
        }

        $result = $this->scanner->scan($id);

        // The scan decides this row; the badge reports its file. Rendering what
        // the scan just returned would put "Unused" back on a row whose file a
        // sibling keeps, one click after the column stopped saying it.
        wp_send_json_success([
            'status' => $result['status'],
            'badge' => StatusBadge::forRow($id, $this->store),
            'evidence' => $this->metaBox->renderEvidence($id),
        ]);
    }

    /** No-JS fallback for the media row action: scan, then bounce back. */
    public function checkSingleFallback(): void
    {
        if (!current_user_can('upload_files')) {
            wp_die(esc_html__('Not allowed.', 'freshet-unused-media'));
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
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unused-media')], 403);
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

        // Time-box the batch: a request that hits max_execution_time never
        // advances the cursor, and the scan would retry the same IDs forever.
        // Stopping early keeps every batch making progress on large libraries.
        $limit = (int) ini_get('max_execution_time');
        $budget = (float) apply_filters('freshet_unusedmedia_batch_seconds', $limit > 0 ? min(20, $limit / 2) : 20);
        $started = microtime(true);
        $processed = 0;
        $last = $state['cursor'];

        foreach ($ids as $id) {
            $this->scanner->scan($id);
            ++$processed;
            $last = $id;

            if (microtime(true) - $started > $budget) {
                break;
            }
        }

        $state = $this->state->advance($last, $processed);

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
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unused-media')], 403);
        }

        $this->state->reset();
        wp_send_json_success();
    }

    /**
     * One batch of delete-all-unused: each ID is re-verified before deletion.
     *
     * The screen's filter travels with every batch. It has to: the button the
     * user pressed named a filtered subset and a count to go with it, so a loop
     * that walked the whole unused set would delete files that count never
     * covered. `after` is the cursor the store hands back — see unusedIds().
     */
    public function deleteBatch(): void
    {
        check_ajax_referer('freshet_unusedmedia_manage');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unused-media')], 403);
        }

        $filters = ResultFilters::fromRequest(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above; every value is validated in fromRequest().
        $after = absint($_POST['after'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.

        $batch = $this->store->unusedIds(5, $filters, $after);

        if ($batch['ids'] === []) {
            wp_send_json_success(array_merge(
                ['finished' => true, 'deleted' => 0, 'skipped' => 0, 'failed' => 0, 'cursor' => 0],
                $this->unusedFigure()
            ));
        }

        $result = $this->deleter->deleteVerified($batch['ids']);

        wp_send_json_success(array_merge([
            'finished' => false,
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'cursor' => $batch['cursor'],
        ], $this->unusedFigure()));
    }

    /**
     * How many files are unused *now*, on every delete reply.
     *
     * A batch loop never reloads the page, so a reply that says only what it
     * removed leaves the figure on screen saying what it said before the first
     * click — the screen has no other way to learn the number moved. Sending it
     * back is what makes it move without a second round trip to fetch it.
     *
     * Nothing is remembered between requests, and nothing may be: counts() is a
     * live aggregate over the grouped subquery, and the whole point is that this
     * answer is different every time it is asked. It counts *files*, because
     * counts() does — the same unit the listing and the delete loop work in.
     *
     * The formatted twin travels with the integer because the DOM needs the
     * number in the site's locale and number_format_i18n() has no JS half.
     *
     * @return array{unused: int, unused_display: string}
     */
    private function unusedFigure(): array
    {
        $unused = $this->store->counts()['unused'];

        return ['unused' => $unused, 'unused_display' => number_format_i18n($unused)];
    }
}

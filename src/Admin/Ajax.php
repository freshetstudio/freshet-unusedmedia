<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\Db;
use FreshetUnusedMedia\Scan\DeleteBudget;
use FreshetUnusedMedia\Scan\FileClaims;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\OrphanSizes;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;
use FreshetUnusedMedia\Scan\SizeSiblings;

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

        // A check that could not read the database has not found this file
        // unused — it has found nothing. Answering with a verdict-shaped reply
        // would put a badge on the row that no scan stands behind (freshet-141).
        if ($result['status'] === Scanner::STATUS_ERROR) {
            wp_send_json_error(['message' => QueryFailed::userMessage()], 500);
        }

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

        // Both of these are guarded for the same reason the detectors are: a
        // failed count sizes the scan at zero and a failed cursor query comes
        // back as an empty batch, which this method reads as "finished". A
        // scan that stops early and calls itself complete is the quiet short
        // answer freshet-141 is about, one level above the detectors.
        try {
            if ($state === null) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simple count to size the scan.
                $total = (int) Db::value('library size', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"));
                $state = $this->state->start($total);

                // A fresh scan describes the library as it is now, so last time's
                // orphaned sizes go with the old results rather than outliving them.
                OrphanSizes::reset();
            }

            $batchSize = max(1, (int) apply_filters('freshet_unusedmedia_batch_size', 10));

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ID-ascending cursor batch; picks up mid-scan uploads.
            $ids = array_map('intval', Db::rows('scan batch', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
                $state['cursor'],
                $batchSize
            ))));
        } catch (QueryFailed) {
            wp_send_json_error(['message' => QueryFailed::userMessage()], 500);
        }

        if ($ids === []) {
            try {
                $unused = $this->store->counts()['unused'];
            } catch (QueryFailed) {
                // The scan itself did finish — the cursor read past the end of
                // the library — but the tally it would be reported with did not
                // run. Marking it finished on a zero nobody counted is what
                // freshet-152 is about, so the state is left running and the
                // browser is told to ask again.
                wp_send_json_error(['message' => QueryFailed::userMessage()], 500);
            }

            $this->state->finish($unused);

            wp_send_json_success([
                'finished' => true,
                'done' => $state['done'],
                'total' => $state['total'],
                'unused' => $unused,
                'errors' => $state['errors'],
            ]);
        }

        // One query for the whole batch's sibling groups instead of one per
        // file: FileClaimDetector asks for them, and an unprimed lookup scans
        // every attached-file row in the library.
        FileGroups::prime($ids);

        // Time-box the batch: a request that hits max_execution_time never
        // advances the cursor, and the scan would retry the same IDs forever.
        // Stopping early keeps every batch making progress on large libraries.
        $limit = (int) ini_get('max_execution_time');
        $budget = (float) apply_filters('freshet_unusedmedia_batch_seconds', $limit > 0 ? min(20, $limit / 2) : 20);
        $started = microtime(true);
        $processed = 0;
        $errors = 0;
        $last = $state['cursor'];

        // The batch's own sibling groups, so the detectors' whole-table passes
        // are issued once per file in it rather than once per row standing on
        // that file (freshet-161). Each row still gets its own verdict.
        $scanned = [];

        foreach (FileGroups::groupsWithin($ids) as $group) {
            foreach ($this->scanner->scanGroup($group) as $rowId => $result) {
                $scanned[$rowId] = $result['status'];
                OrphanSizes::observeAttachment($rowId);
            }

            // The budget is spent between groups rather than between rows, which
            // is what makes a group's shared reads worth issuing at all: cutting
            // one in half would leave its remaining rows to ask the whole
            // question again next time. A group is a subset of the batch, so the
            // overrun is still bounded by one batch's work.
            if (microtime(true) - $started > $budget) {
                break;
            }
        }

        // The cursor advances over the rows this batch scanned *without a gap*.
        // A group can hold a row from further down the batch, so what a
        // cut-short batch scanned is not always a leading run of it — and a
        // cursor written past a row nobody scanned is a row skipped for the
        // whole run. Rows scanned past the gap keep their verdicts and are
        // simply scanned again in the next batch, which is also what stops
        // `done` counting them twice.
        foreach ($ids as $id) {
            if (!isset($scanned[$id])) {
                break;
            }

            if ($scanned[$id] === Scanner::STATUS_ERROR) {
                // Counted, and the cursor still advances: the attachment now
                // has no stored status at all, so it reads as unscanned and is
                // in neither list. The figure is what makes the run say so.
                ++$errors;
            }

            ++$processed;
            $last = $id;
        }

        // Nothing that was read from disk survives the batch: the directory
        // listing is the one structure here that grows with the library rather
        // than with the batch, and a request that keeps it has kept the wrong
        // thing (freshet-124).
        SizeSiblings::flush();
        OrphanSizes::flush();
        FileClaims::flush();

        $state = $this->state->advance($last, $processed, $errors, SizeSiblings::takeDirectoryReads());

        wp_send_json_success([
            'finished' => false,
            'done' => $state['done'],
            'total' => max($state['total'], $state['done']),
            'errors' => $state['errors'],
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
     *
     * The batch is a ceiling and not a promise. What a request actually gets
     * through is decided by DeleteBudget, because five files is a different
     * amount of work on a library where a file averages ten attachment rows;
     * a batch that stops early is a shorter batch, and the loop comes back for
     * what it left.
     */
    public function deleteBatch(): void
    {
        check_ajax_referer('freshet_unusedmedia_manage');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Not allowed.', 'freshet-unused-media')], 403);
        }

        $filters = ResultFilters::fromRequest(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above; every value is validated in fromRequest().
        $after = absint($_POST['after'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.

        try {
            $batch = $this->store->unusedIds(5, $filters, $after);
        } catch (QueryFailed) {
            // An empty batch is how this loop is told it has finished. A read
            // that did not answer must not be allowed to say that.
            wp_send_json_error(['message' => QueryFailed::userMessage()], 500);
        }

        if ($batch['ids'] === []) {
            wp_send_json_success(array_merge(
                ['finished' => true, 'deleted' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => 0, 'cursor' => 0],
                $this->unusedFigure()
            ));
        }

        $result = $this->deleter->deleteVerified($batch['ids'], DeleteBudget::forRequest());

        $cursor = $batch['cursor'];

        // A batch that ran out of time hands its untouched files to the next
        // request, and the cursor must not step over them. Only when one is in
        // play: without a size filter the cursor is 0 and unusedIds() does not
        // read it, because the files are still in the unused pool and its own
        // query finds them again. Winding an unused cursor forward there would
        // invent a position the next batch would then be bound by.
        if ($cursor > 0 && $result['remaining'] !== []) {
            $cursor = max(0, $result['remaining'][0] - 1);
        }

        wp_send_json_success(array_merge([
            // Never `true` on a short batch: `finished` is what stops the loop
            // and reports a completed pass, and a run cut short has not made
            // one (freshet-141).
            'finished' => false,
            'deleted' => $result['deleted'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'remaining' => count($result['remaining']),
            'cursor' => $cursor,
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
     * A count that did not run sends neither key rather than a zero (freshet-152).
     * The reply is about a deletion that did happen and must still be delivered
     * — what it removed is not in doubt — so this is the one caller that
     * degrades instead of refusing. Absent, updateCount() in admin.js returns
     * without touching the DOM, so the heading keeps the last number a database
     * actually produced rather than being rewritten to one it did not.
     *
     * @return array{unused?: int, unused_display?: string}
     */
    private function unusedFigure(): array
    {
        try {
            $unused = $this->store->counts()['unused'];
        } catch (QueryFailed) {
            return [];
        }

        return ['unused' => $unused, 'unused_display' => number_format_i18n($unused)];
    }
}

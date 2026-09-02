<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;

defined('ABSPATH') || exit;

/**
 * Deletion of verified-unused files. Every row on a file is re-scanned
 * immediately before deletion — one row still in use keeps the whole file.
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
            wp_die(esc_html__('Not allowed.', 'freshet-unused-media'));
        }

        check_admin_referer('freshet_unusedmedia_delete_selected');

        $ids = array_map('absint', (array) ($_POST['attachments'] ?? []));
        $ids = array_values(array_filter($ids));

        $result = $ids === []
            ? ['deleted' => 0, 'skipped' => 0, 'failed' => 0]
            : $this->deleteVerified($ids);

        wp_safe_redirect(add_query_arg([
            'page' => ToolsPage::SLUG,
            // Back to the list that was acted on, not to the scan.
            'tab' => ToolsPage::TAB_UNUSED,
            'freshet_unusedmedia_deleted' => $result['deleted'],
            'freshet_unusedmedia_skipped' => $result['skipped'],
            'freshet_unusedmedia_failed' => $result['failed'],
        ], admin_url('upload.php')));

        exit;
    }

    /**
     * Re-verify and delete, by file rather than by row. Every entry point comes
     * through here — the delete-all batches, the checkbox form, and a single
     * checkbox on its own, which is the same form with one box ticked.
     *
     * **Each ID names a file, not a row.** The listing hands back one
     * representative row per file, and the first thing this does is expand it
     * back to every attachment row pointing at the same `_wp_attached_file`.
     * That is the whole point: `wp_delete_attachment()` removes the file for the
     * row it is given, so deleting one row of three erases a file the other two
     * still reference. Rows on one path are one decision and one deletion.
     *
     * The verdict is default-closed and unanimous — one row still in use, or one
     * row already sitting in the trash, keeps the file. Skipped and failed files
     * leave the unused pool the same way they always did (re-scanned as used, or
     * cleared), which is what lets the delete-all loop terminate.
     *
     * Trash vs permanent is core's call: with MEDIA_TRASH enabled
     * wp_delete_attachment() trashes, otherwise it deletes permanently.
     *
     * The counts returned are files. So is the bookkeeping: each file is sized
     * once, before it goes, because afterwards there is nothing left to measure.
     *
     * @param int[] $ids Representative attachment IDs, one per file.
     * @return array{deleted: int, skipped: int, failed: int}
     */
    public function deleteVerified(array $ids): array
    {
        $deleted = 0;
        $skipped = 0;
        $failed = 0;

        $freedBytes = 0;
        $freedFiles = 0;
        $freedUnsized = 0;
        $trashed = 0;

        /** @var array<int, true> $handled Rows already decided this pass. */
        $handled = [];

        foreach ($ids as $id) {
            if (isset($handled[$id])) {
                // Two representatives of one file, or the same box ticked twice:
                // the file has had its decision, and a second pass over it would
                // count it again.
                continue;
            }

            if (get_post_type($id) !== 'attachment') {
                ++$failed;
                $handled[$id] = true;
                $this->store->clear($id); // Drop from the unused pool so batch loops terminate.
                continue;
            }

            $rows = FileGroups::siblings($id);

            foreach ($rows as $rowId) {
                $handled[$rowId] = true;
            }

            // One row this user may not delete stops the file, not just that row:
            // deleting the rest would take the file the undeletable row still
            // points at.
            foreach ($rows as $rowId) {
                if (!current_user_can('delete_post', $rowId)) {
                    ++$failed;
                    $this->clearAll($rows);

                    continue 2;
                }
            }

            // A trashed row is a deletion someone started and can still undo.
            // Erasing the file now would empty the trash out from under them.
            foreach ($rows as $rowId) {
                if (get_post_status($rowId) === 'trash') {
                    ++$skipped; // Already outside the unused pool; nothing to clear.

                    continue 2;
                }
            }

            // Fresh scan of every row right before deletion — the cached results
            // may be stale, and one stale row is enough to lose a live file.
            $used = 0;

            foreach ($rows as $rowId) {
                if ($this->scanner->scan($rowId)['status'] === ResultStore::STATUS_USED) {
                    ++$used;
                }
            }

            if (FileGroups::verdict(count($rows), 0, $used, count($rows) - $used) !== ResultStore::STATUS_UNUSED) {
                ++$skipped; // Cache already updated by the scans; the file leaves the unused pool.
                continue;
            }

            // Measured once, before the call: one file, one size, however many
            // rows point at it. Null means it could not be sized at all — an
            // offloaded file with no recorded size — which is unknown, not zero.
            $bytes = FileSize::bytes($id);
            $removed = true;

            foreach ($rows as $rowId) {
                if (!(wp_delete_attachment($rowId, false) instanceof \WP_Post)) {
                    $removed = false;
                }
            }

            if (!$removed) {
                // Some row survived, so the file may still be on disk. Clearing
                // what is left takes the file out of the unused pool rather than
                // leaving a half-deleted group to be offered again.
                ++$failed;
                $this->clearAll($rows);

                continue;
            }

            ++$deleted;

            if (get_post_status($id) === 'trash') {
                // MEDIA_TRASH turned the delete into a trash: the posts moved,
                // the file did not, and no disk was freed. Counting it as
                // reclaimed would be the inflated number this figure exists to
                // avoid.
                ++$trashed;
            } elseif ($bytes === null) {
                ++$freedUnsized;
            } else {
                $freedBytes += $bytes;
                ++$freedFiles;
            }
        }

        ReclaimedLedger::add($freedBytes, $freedFiles, $freedUnsized, $trashed);

        return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @param int[] $rows */
    private function clearAll(array $rows): void
    {
        foreach ($rows as $rowId) {
            $this->store->clear($rowId);
        }
    }
}

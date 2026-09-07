<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\DeleteBudget;
use FreshetUnusedMedia\Scan\FileClaims;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;

defined('ABSPATH') || exit;

/**
 * Deletion of verified-unused files. Every row on a file is re-scanned
 * immediately before deletion — one row still in use keeps the whole file.
 *
 * Two questions are asked before that re-scan, and they are different
 * questions. FileGroups answers *which rows stand on this path*, so the file
 * goes with all of them or not at all. FileClaims answers *whether the files
 * this would unlink belong to anyone else* — an attachment's original or one of
 * its sizes can be another attachment's own file, and those two rows are in
 * groups that never meet. A yes to the second is a skip, not a partial delete:
 * there is no half of a file worth taking.
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

        // This path has no loop behind it — the form posts every ticked box in
        // one request, and a page holds fifty of them. It gets the same budget
        // the batch loop gets, and says on the notice what it did not reach:
        // those files kept their stored verdict, so they are still listed and
        // ticking them again is the whole of the resume.
        $result = $ids === []
            ? ['deleted' => 0, 'skipped' => 0, 'failed' => 0, 'remaining' => []]
            : $this->deleteVerified($ids, DeleteBudget::forRequest());

        wp_safe_redirect(add_query_arg([
            'page' => ToolsPage::SLUG,
            // Back to the list that was acted on, not to the scan.
            'tab' => ToolsPage::TAB_UNUSED,
            'freshet_unusedmedia_deleted' => $result['deleted'],
            'freshet_unusedmedia_skipped' => $result['skipped'],
            'freshet_unusedmedia_failed' => $result['failed'],
            'freshet_unusedmedia_remaining' => count($result['remaining']),
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
     * The verdict is default-closed and unanimous — one row still in use, one
     * row already sitting in the trash, or one row whose re-scan could not read
     * the database, keeps the file. Skipped and failed files leave the unused
     * pool the same way they always did (re-scanned as used, or cleared), which
     * is what lets the delete-all loop terminate.
     *
     * Trash vs permanent is core's call: with MEDIA_TRASH enabled
     * wp_delete_attachment() trashes, otherwise it deletes permanently.
     *
     * The counts returned are files. So is the bookkeeping: each file is sized
     * once, before it goes, because afterwards there is nothing left to measure.
     *
     * **A budget stops this between files, never inside one** (freshet-150).
     * The decision on a file — expand to its rows, ask who else claims them,
     * re-scan every one, then delete or not — is indivisible, so the loop's
     * only stopping place is the gap between two files. What that buys is
     * stated in the returned `remaining`: the files this request did not reach.
     * They were not touched, they kept the stored verdict the screen was
     * rendered from, and they are still in the unused pool for the next
     * request — which is what makes a run that stops early a shorter run
     * rather than a partial one. With no budget passed, nothing stops.
     *
     * @param int[] $ids Representative attachment IDs, one per file.
     * @return array{deleted: int, skipped: int, failed: int, remaining: int[]}
     */
    public function deleteVerified(array $ids, ?DeleteBudget $budget = null): array
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

        /** @var int[] $remaining Files the budget stopped this request reaching. */
        $remaining = [];

        // A list, so the slice below names files rather than array keys.
        $ids = array_values($ids);

        $fileStarted = 0.0;

        foreach ($ids as $index => $id) {
            if ($budget !== null) {
                // Timed from the top of the previous iteration rather than at
                // each of the body's exits: every one of them is a `continue`,
                // and a file that was skipped cost what it cost.
                if ($fileStarted > 0.0) {
                    $budget->record(microtime(true) - $fileStarted);
                }

                if (!$budget->hasRoom()) {
                    $remaining = array_values(array_filter(
                        array_slice($ids, $index),
                        static fn(mixed $rest): bool => !isset($handled[$rest])
                    ));

                    break;
                }

                $fileStarted = microtime(true);
            }

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

            try {
                $rows = FileGroups::siblings($id);
            } catch (QueryFailed) {
                // The sibling lookup is what turns one id into every row
                // standing on the file. When it cannot answer, the group is
                // unknown — and deleting the single row we were handed unlinks
                // a file the rows we could not see may still be using.
                ++$failed;
                $handled[$id] = true;
                $this->store->clear($id); // Out of the unused pool so batch loops terminate.

                continue;
            }

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

            // An attachment owns more files than the one it is grouped on, and
            // one of those can be another attachment's own file: a `-scaled`
            // upload's `original_image`, or a size cut from a name that is
            // itself size-shaped. Those two rows key on different paths, so
            // they are in different groups and neither sibling lookup nor the
            // re-scan below can see the collision — while core unlinks every
            // size and original **by name** with no cross-attachment guard
            // beyond the legacy `$meta['thumb']` check. Asked before the
            // re-scan because it is the cheaper of the two and settles the file
            // outright (freshet-142).
            try {
                $claimed = FileClaims::claimants($rows);
            } catch (QueryFailed) {
                // Same reading as the sibling lookup above: a read that did not
                // answer is not an answer of "nobody else needs these files".
                ++$failed;
                $this->clearAll($rows); // Out of the unused pool so batch loops terminate.

                continue;
            }

            if ($claimed !== []) {
                // The file is in use — by a library entry standing on it rather
                // than by content pointing at it, which is the same sentence
                // this plugin says everywhere else about a shared file. Cleared
                // rather than left, because no scan wrote a status here and an
                // uncleared file would be offered to the next batch for ever.
                ++$skipped;
                $this->clearAll($rows);

                continue;
            }

            // Fresh scan of every row right before deletion — the cached results
            // may be stale, and one stale row is enough to lose a live file.
            $used = 0;
            $unresolved = 0;

            foreach ($rows as $rowId) {
                $status = $this->scanner->scan($rowId)['status'];

                if ($status === Scanner::STATUS_ERROR) {
                    ++$unresolved;
                } elseif ($status === ResultStore::STATUS_USED) {
                    ++$used;
                }
            }

            // This re-verification is the last thing standing between a stale
            // verdict and a file that is gone, and it runs in the request most
            // likely to be cut short. A row whose scan could not read the
            // database has not been found unused — it has not been judged at
            // all, and an unjudged row is not one this may delete (freshet-141).
            if ($unresolved > 0) {
                ++$failed;
                $this->clearAll($rows); // The scans wrote nothing; this takes the file out of the pool.

                continue;
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

            // The rows just removed are a whole sibling group, so anything this
            // request remembered about the library's grouping now describes rows
            // that are gone. A stale sibling set is a wrong verdict.
            FileGroups::flush();

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

        // After the break as well as after the last file: a run that stopped
        // early still freed what it freed, and a ledger written only by a run
        // that finished would lose every short one.
        ReclaimedLedger::add($freedBytes, $freedFiles, $freedUnsized, $trashed);

        return ['deleted' => $deleted, 'skipped' => $skipped, 'failed' => $failed, 'remaining' => $remaining];
    }

    /** @param int[] $rows */
    private function clearAll(array $rows): void
    {
        foreach ($rows as $rowId) {
            $this->store->clear($rowId);
        }
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

use FreshetUnusedMedia\Detector\AttachedDetector;
use FreshetUnusedMedia\Detector\CommentDetector;
use FreshetUnusedMedia\Detector\DetectorInterface;
use FreshetUnusedMedia\Detector\FileClaimDetector;
use FreshetUnusedMedia\Detector\OptionsDetector;
use FreshetUnusedMedia\Detector\PostContentDetector;
use FreshetUnusedMedia\Detector\PostmetaDetector;
use FreshetUnusedMedia\Detector\RecentUploadDetector;
use FreshetUnusedMedia\Detector\TermDescriptionDetector;
use FreshetUnusedMedia\Detector\TermMetaDetector;
use FreshetUnusedMedia\Detector\UserMetaDetector;

defined('ABSPATH') || exit;

/**
 * Runs all detectors against one attachment, computes the status
 * (any confirmed/possible reference = used) and persists the result.
 */
final class Scanner
{
    /**
     * The scan could not be completed, so the attachment has no verdict.
     *
     * Deliberately not a ResultStore status and never written to postmeta:
     * a failure is the absence of an answer, not a third kind of answer, and
     * the moment it became storable every listing, count, badge and filter
     * would have to learn a word for it. What is stored instead is nothing —
     * see scan().
     */
    public const STATUS_ERROR = 'error';

    public function __construct(private readonly ResultStore $store)
    {
    }

    /**
     * Scan every row of one sibling group, sharing the broad passes across them.
     *
     * The delete path re-verifies by file: it expands one id into every row
     * standing on the same `_wp_attached_file` and scans all of them, and those
     * rows name the same file. Scanned one at a time, each of them re-issued the
     * detectors' whole-table `LIKE` pass for needles that were largely identical
     * — on a library where a file averages ten rows, the same expensive question
     * ten times over, and 94% of what a delete request spends (freshet-155).
     *
     * SharedReads is what makes it one pass. It is opened here and closed in the
     * `finally`, so the sharing lasts exactly as long as this group and a later
     * scan cannot read rows fetched for an earlier one. Nothing about a row's
     * verdict is shared: each row runs every detector against its own id and its
     * own basenames, so two rows of a group still reach different statuses —
     * which they must, because FileGroups::verdict() counts them individually.
     *
     * **The caller decides how much of the group it holds.** The delete path
     * holds all of it, because it is about to remove the file; the library scan
     * holds the rows of it inside the batch it is on, and the rest of the group
     * is scanned as its own group in the batch that reaches it
     * (FileGroups::groupsWithin(), freshet-161). Sharing part of a group is as
     * safe as sharing the whole of one — the union of a subset is narrower, and
     * every row still verifies against its own id and its own basenames — but it
     * is never *more* than a group: rows of two different files would widen the
     * pass with needles neither of them asked about.
     *
     * @param int[] $rowIds Attachment rows standing on one file — every one of
     *                      them, or the ones this caller holds.
     * @return array<int, array{status: string, refs: Reference[], error?: string}>
     *         Keyed by row id, in the order given.
     */
    public function scanGroup(array $rowIds): array
    {
        SharedReads::open($rowIds);

        try {
            $results = [];

            foreach ($rowIds as $rowId) {
                $results[(int) $rowId] = $this->scan((int) $rowId);
            }

            return $results;
        } finally {
            SharedReads::close();
        }
    }

    /**
     * Run every detector and record the verdict — unless one of them could not
     * read the database, in which case there is no verdict to record.
     *
     * **A scan that cannot answer refuses to answer** (freshet-141). A detector
     * whose query errored hands back the same empty array as one that genuinely
     * found nothing, and the difference between those two is the difference
     * between a file nobody uses and a file about to be deleted by mistake. So
     * QueryFailed is not caught and turned into "no references": it clears
     * whatever this attachment's stored result was and returns STATUS_ERROR.
     *
     * Clearing rather than keeping is the safe half. A stale `unused` left
     * behind would go on offering the file for deletion on the strength of a
     * scan that has since failed; with the meta gone the file reads as not yet
     * scanned, which puts it in neither list and in no delete batch.
     *
     * @return array{status: string, refs: Reference[], error?: string}
     */
    public function scan(int $attachmentId): array
    {
        // A failure of ours has to still be there when we look for it, so the
        // slate is wiped once, here — never between two of this scan's own
        // reads. Without this, an error another plugin's query left on $wpdb
        // earlier in the request would fail every scan in it.
        Db::forget();

        $ctx = AttachmentContext::forAttachment($attachmentId);

        $detectors = [
            new PostmetaDetector(),
            new PostContentDetector(),
            new OptionsDetector(),
            new TermMetaDetector(),
            new TermDescriptionDetector(),
            new UserMetaDetector(),
            new CommentDetector(),
            new RecentUploadDetector(),
            new AttachedDetector(),
            new FileClaimDetector(),
        ];

        /** @var DetectorInterface[] $detectors */
        $detectors = apply_filters('freshet_unusedmedia_detectors', $detectors, $ctx);

        $refs = [];

        try {
            foreach ($detectors as $detector) {
                // The only measurement in the scan, and it is a subtraction
                // around a call that was going to happen anyway: nothing here
                // reads a clock per row, per query or per reference, and no
                // verdict, count or reference is computed from it. What it
                // buys is the run being able to say what it is doing and where
                // its time went (freshet-286). In the `finally` so a detector
                // that raises QueryFailed still reports the seconds it burned
                // before failing — the slowest check on a struggling database
                // is exactly the one worth seeing.
                $startedAt = microtime(true);

                try {
                    foreach ($detector->find($ctx) as $ref) {
                        $refs[] = $ref;
                    }
                } finally {
                    DetectorTiming::add($detector->id(), microtime(true) - $startedAt);
                }
            }
        } catch (QueryFailed $e) {
            $this->store->clear($attachmentId);

            return ['status' => self::STATUS_ERROR, 'refs' => [], 'error' => $e->getMessage()];
        }

        $used = false;

        foreach ($refs as $ref) {
            if ($ref->countsAsUsed()) {
                $used = true;
                break;
            }
        }

        /** Final say on the computed status for site-specific edge cases. */
        $used = (bool) apply_filters('freshet_unusedmedia_is_used', $used, $refs, $ctx);

        $status = $used ? ResultStore::STATUS_USED : ResultStore::STATUS_UNUSED;

        $this->store->save($attachmentId, $status, $refs);

        return ['status' => $status, 'refs' => $refs];
    }
}

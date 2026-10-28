<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Whether a stored verdict still stands on the reading of the site that is in
 * force, and what the screen says about one that does not.
 *
 * **The gap this closes (freshet-415).** A scan is a reading of the site taken
 * at a moment, and every verdict is an answer computed from that reading.
 * Discarding the reading does not discard the verdicts: they are per-attachment
 * post meta and the listing reads them (ResultStore), so between the discard
 * and the new scan reaching a given file, that file is still offered for
 * deletion on the strength of a reading nothing stands behind any more.
 * freshet-D116 is explicit that what decides whether a wrong answer is
 * tolerable is its direction, and that one points at deleting a file something
 * may have started using since.
 *
 * **So the verdict is dated rather than deleted.** freshet-413 took the other
 * road for a reading that went stale under a resume — it threw the verdicts away
 * with it — and that is right *there*, where the plugin decided for itself and
 * the alternative was finishing a listing that was part stale answer. Here the
 * person asked for the new reading and may still be reading the old answers, so
 * nothing is destroyed: the row stays, the count does not move, and what closes
 * is the delete offer, which is where the harm would be.
 *
 * ScanState::resetIndex() is the one place a reading is discarded, so it is the
 * one place that dates the discard — and every path through it is covered with
 * no second mechanism to keep in step: the Reset button (Ajax::scanReset()), a
 * fresh scan start (Ajax::scanBatch()), `wp unusedmedia scan --reset`, and
 * StaleIndex::rebuild(), which has nothing left to hold back because it wipes
 * the verdicts itself.
 */
final class ReadingInForce
{
    /**
     * The moment a verdict has to be no older than to be offered for deletion,
     * or 0 when there is nothing to check.
     *
     * Zero on the overwhelming majority of page loads, because the mark is
     * written when a reading is discarded and dropped again by the next scan
     * that *finishes* — one that read the library to the end having begun after
     * this mark, so every verdict in it is that scan's own and there is nothing
     * left to hold back (ScanState::finish(), where the second half of that is
     * freshet-422). A zero watermark is the fast path through holds(),
     * and the screen then behaves exactly as it did before this class existed.
     *
     * Deliberately not derived from dates — "has a scan finished since the
     * discard?" is the same question and would be answered by comparing two
     * clocks, which gets a library that reads and scans inside one second
     * wrong in the direction of withholding a control for ever.
     */
    public static function watermark(ScanState $state): int
    {
        return max(0, $state->readingDroppedAt());
    }

    /**
     * Whether one file's verdict was computed from the reading in force.
     *
     * **The oldest row decides it, not the representative.** The listing shows
     * one row per file and a file is unused only when every row on its path is
     * (FileGroups::verdict()), so the verdict on screen is as old as the oldest
     * row behind it — and a scan walks the library by id, which cuts groups in
     * half: a file can have one row re-judged by the new scan and another still
     * carrying the answer the discarded reading produced.
     *
     * Default-closed in both directions it can fail. A row nothing dates reads
     * as not in force — a result written by a version that did not stamp
     * META_SCANNED_AT is exactly a verdict whose reading cannot be vouched for
     * — and so does a sibling lookup that could not answer, because an unknown
     * group is an unknown oldest row. What being wrong here costs is a checkbox
     * that is not drawn.
     */
    public static function holds(int $attachmentId, ResultStore $store, int $watermark): bool
    {
        if ($watermark <= 0) {
            return true;
        }

        try {
            $rows = FileGroups::siblings($attachmentId);
        } catch (QueryFailed) {
            return false;
        }

        // The same priming fileStatus() does, for the same reason: one group's
        // dates are one cache fill rather than one query per row.
        if (count($rows) > 1) {
            update_postmeta_cache($rows);
        }

        foreach ($rows as $rowId) {
            if ($store->scannedAt($rowId) < $watermark) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the row says where the checkbox would have been.
     *
     * Short, because it is a table cell repeated down a page of fifty, and the
     * sentence that explains it is printed once above the table
     * (statement()). Deliberately not "Not scanned": that is a different state,
     * it is what the In-trash listing already says for a file nobody has
     * judged, and this file *has* been judged — on a reading that has since
     * been replaced.
     */
    public static function label(): string
    {
        return __('Awaiting re-check', 'freshet-unused-media');
    }

    /**
     * The one sentence above the listing: what happened, what it means, and
     * what to do about it (aitodo-D104).
     *
     * A statement rather than a prompt, for the reason StaleIndex::statement()
     * is one: nobody reading it can judge which of 27,000 files changed while
     * the old reading was in force, so there is no decision here to offer them.
     */
    public static function statement(): string
    {
        return __('The reading of your site these verdicts were computed from has been replaced, and the new scan has not reached every file yet.', 'freshet-unused-media')
            . ' ' . __('A file still carrying the earlier reading\'s answer is marked "Awaiting re-check" and is not offered for deletion, because something may have started using it since.', 'freshet-unused-media')
            . ' ' . __('Nothing has been lost: let the scan finish and every file is offered again on an answer taken from your site as it is now.', 'freshet-unused-media');
    }
}

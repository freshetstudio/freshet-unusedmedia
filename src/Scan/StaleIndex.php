<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * How old the reading of the site a scan stands on may be, and what happens to
 * a resume that stands on an older one.
 *
 * **The gap this closes (freshet-413).** A scan is a reading of a site taken at
 * a moment: phase one records which of the site's entries name which
 * attachment, and every verdict after it is answered from that reading. The
 * cursor made that resumable and nothing ever asked how old the reading was —
 * `built_at` was written by ScanState::finishIndex() and read by nobody. So a
 * scan started on 2 October could still be resumed on 14 October, and finishing
 * it produced one listing that was part twelve-day-old answer: a file something
 * had started using in those twelve days still read *unused*. freshet-D116 is
 * explicit that what decides whether a wrong answer is tolerable is its
 * direction, and that one points at deleting a file somebody wants.
 *
 * **So a stale reading is re-taken rather than resumed, and the verdicts
 * standing on it go with it.** That is freshet-290's rule — results do not
 * outlive the reading of the site they were computed from — extended from
 * *reset* to *age*. It is the cheap option as well as the safe one: phase one
 * on a 27,000-attachment library is one nine-second request since 1.2.1
 * (freshet-411 measured it), which is what makes starting over affordable
 * enough to do without asking. Nobody is prompted; the person is told what
 * already happened (see statement()).
 *
 * **Age is counted in idle seconds, not wall clock, and that is what keeps a
 * long scan finishing.** A scan on a slow host can legitimately spend hours
 * scanning, and a rule that re-read the site every 24 wall-clock hours would
 * restart such a library for ever, one request short of the end, every time.
 * What the hazard actually is is a scan somebody *put down* — the tab left
 * open, resumed a fortnight later — so the measure is the time the reading has
 * sat still: the wall clock since it was taken, less the seconds the scan has
 * actually spent working against it. A scan running flat out is never stale by
 * this measure however long it takes; a scan nobody has touched for a day is,
 * whatever it has got through. The one case this leaves open is a run that
 * genuinely scans for longer than the threshold without a pause, whose answers
 * then span longer than the threshold — said here rather than defended,
 * because the defence is the restart loop above.
 */
final class StaleIndex
{
    /**
     * How old the reading may be, in idle seconds, filtered.
     *
     * A day by default: a scan is a reading of a site, a site changes daily,
     * and same-day is the honest claim to make for one. Zero or less switches
     * the check off entirely, the way `freshet_unusedmedia_upload_grace` does
     * — a long-running import on a library nobody is editing can say so.
     *
     * The plugin's one read of `freshet_unusedmedia_index_max_age`.
     */
    public static function maxAge(): int
    {
        return (int) apply_filters('freshet_unusedmedia_index_max_age', DAY_IN_SECONDS);
    }

    /**
     * Whether what a resume would stand on is too old to stand on.
     *
     * False when there is nothing to resume — no scan cursor or no index — so
     * a fresh start is never affected by this, and false when the filter has
     * switched the check off.
     */
    public static function isStale(ScanState $state): bool
    {
        $scan = $state->current();
        $index = $state->indexCursor();

        if ($scan === null || $index === null) {
            return false;
        }

        $maxAge = self::maxAge();

        if ($maxAge <= 0) {
            return false;
        }

        $takenAt = self::takenAt($state);

        // A reading with no date on it cannot be vouched for, and the safe
        // direction is to take it again: it costs one request and the
        // alternative is answering a deletion from it. Only a hand-edited or
        // half-written option gets here — finishIndex() has stamped `built_at`
        // since the index existed.
        if ($takenAt <= 0) {
            return true;
        }

        // Which clock belongs to which phase: a finished reading has been
        // sitting still since it was built, bar the file batches that have run
        // against it, and an unfinished one since the scan that is building it
        // began, bar its own pages.
        $working = $index['complete'] ? $scan['elapsed'] : $index['elapsed'];
        $idle = (time() - $takenAt) - max(0.0, $working);

        return $idle > $maxAge;
    }

    /**
     * When the reading a resume would stand on was taken, or 0 when nothing
     * dates it.
     *
     * `built_at` once the pass has finished — the reading is complete and its
     * date is the moment it was completed. Before that, the scan's own
     * `started_at`: phase one begins immediately after start(), so that is when
     * the reading now in the table was begun.
     */
    public static function takenAt(ScanState $state): int
    {
        $index = $state->indexCursor();

        if ($index === null) {
            return 0;
        }

        if ($index['complete']) {
            return $index['built_at'];
        }

        return (int) ($state->current()['started_at'] ?? 0);
    }

    /**
     * Whether the reading in force is one this attachment was in the site for.
     *
     * **The gap this closes (freshet-423).** The reading is a snapshot of which
     * of the site's entries name which attachment, and its id universe is built
     * once, from the library as it stood (ReferenceIndexer::attachmentIds()).
     * The file phase then answers out of it — for hours on a large library,
     * because the two phases are one scan and only the first of them is quick.
     * A file uploaded while the file phase is still running was not in the site
     * when the reading was taken, so the reading holds nothing for it, and
     * seven of the ten detectors read that silence as *no references*. Past the
     * 24-hour upload grace that is a verdict of `unused` on a file somebody
     * uploaded today, and it reaches the listing, the export and the "Delete
     * all unused" count.
     *
     * **So the reading does not get to answer for it, and the detectors are
     * asked instead.** Which is not a new path — it is the one every library
     * with an incomplete index already takes (Scanner::scan()), same detectors,
     * same verifiers — so the cost is one live pass for the handful of files
     * newer than the reading, and never an answer.
     *
     * Default-closed in both directions it can fail. An undated reading cannot
     * be said to cover anything, the way isStale() will not vouch for one, and
     * an attachment whose own date could not be read is an attachment whose
     * place in the reading is unknown. Being wrong here costs a live pass.
     *
     * One window stays open and is cheaper to say than to close: the id
     * universe is read when the pass *starts* and `built_at` is stamped when it
     * ends, so a file uploaded between those two moments reads as covered while
     * the reading holds nothing for it. That window is the length of phase one
     * — nine seconds on a 27,000-attachment library (freshet-411) — and a file
     * inside it is held by the upload grace for the next 24 hours anyway.
     */
    public static function covers(int $attachmentId, ScanState $state): bool
    {
        $takenAt = self::takenAt($state);

        if ($takenAt <= 0) {
            return false;
        }

        $uploaded = get_post_timestamp($attachmentId);

        if ($uploaded === false) {
            return false;
        }

        // Strictly older, so a file uploaded in the same second as the reading
        // was taken is asked rather than assumed. The pessimistic reading of a
        // tie is free here — unlike ScanState::finish(), where it would hold a
        // control back for ever — because all it buys is one live pass.
        return $uploaded < $takenAt;
    }

    /**
     * What the screens say about every verdict on them: which moment they
     * describe (freshet-423).
     *
     * The honest shape of a two-phase scan is that the answers are *as of* the
     * reading, not as of now, and until this sentence existed no surface said
     * so — not the Scan tab, not the listing, not the export. It is one line
     * rather than a notice: nothing has gone wrong, and there is nothing to
     * click.
     *
     * The second half is the limit, said rather than implied, because the two
     * halves are genuinely different (freshet-426 is the standing rule about
     * not promising more than the plugin does): a file newer than the reading
     * is checked live, so it cannot read unused for want of being in the
     * snapshot — but a post edited since the reading that now names an *older*
     * file is invisible until the next scan.
     *
     * @param int $takenAt When the reading was taken; callers render nothing
     *                     at or below zero, where no date can be quoted.
     */
    public static function asReadAt(int $takenAt): string
    {
        return sprintf(
            /* translators: %s: how long ago, already worded ("3 hours") */
            __('Verdicts describe your site as it was read %s ago.', 'freshet-unused-media'),
            human_time_diff($takenAt)
        )
            . ' ' . __('A file uploaded since then is checked against your site as it is now; a change to an older file is picked up by the next scan.', 'freshet-unused-media');
    }

    /**
     * Throw the stale reading and everything standing on it away, and say when
     * the reading had been taken so the caller can tell the person.
     *
     * The cursor, the index and last reading's orphaned sizes go the way a
     * Reset sends them (Ajax::scanReset(), freshet-290), and the stored
     * verdicts go with them, which a Reset does not do: a Reset is a person
     * deciding to scan again with the old answers still on screen, while this
     * is the plugin deciding it, and leaving a library's worth of twelve-day-old
     * `unused` badges up under a scan that is back at zero is the thing this
     * exists to stop.
     *
     * The caller sizes and starts the fresh scan — the count that sizes it is a
     * read that can fail, and the two paths that resume a scan each already
     * guard it. Hand the returned value to ScanState::start() as `$staleFrom`
     * so the new cursor carries why it exists.
     *
     * @return int The date of the reading that was thrown away, or -1 when
     *         nothing dated it. Never 0: zero is how a cursor says the scan it
     *         belongs to is one somebody asked for, so a rebuild of an undated
     *         reading has to be distinguishable from no rebuild at all.
     */
    public static function rebuild(ScanState $state, ResultStore $store): int
    {
        $takenAt = self::takenAt($state);

        $state->reset();
        $state->resetIndex();
        OrphanSizes::reset();
        $store->forgetAll();

        return $takenAt > 0 ? $takenAt : -1;
    }

    /**
     * What the person is told, once, as a statement of what already happened.
     *
     * Not a warning to click through: a prompt would leave the forbidden
     * direction live behind an OK button, and nobody reading it can judge which
     * of 27,000 files changed while the tab was shut. So this says what
     * happened, what it would otherwise have meant, and that there is nothing
     * to do about it.
     *
     * @param int $takenAt When the reading that was dropped had been taken; 0
     *                     when nothing dated it.
     */
    public static function statement(int $takenAt): string
    {
        $happened = $takenAt > 0
            ? sprintf(
                /* translators: %s: how long ago, already worded ("12 days") */
                __('This scan had read your site\'s content %s ago, so it read it again and started over.', 'freshet-unused-media'),
                human_time_diff($takenAt)
            )
            : __('This scan stood on a reading of your site with no date on it, so it read your site again and started over.', 'freshet-unused-media');

        return $happened
            . ' ' . __('Carrying on would have listed files as unused on what your site looked like then, and anything that started using one of them since would still have read unused.', 'freshet-unused-media')
            . ' ' . __('Nothing was deleted and there is nothing to put right — let the scan finish and the list will describe your library as it is now.', 'freshet-unused-media');
    }
}

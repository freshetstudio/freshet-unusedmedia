<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * What a running scan says about itself, in words (freshet-286).
 *
 * The browser scan and `wp freshet-unusedmedia scan` are the same scan — same
 * Scanner, same detectors, same cursor — so they say the same four things
 * about it: how far along it is, how long it has spent, roughly how long is
 * left, and what it is checking. That is this class, and both surfaces call it
 * rather than each phrasing its own version of the same numbers.
 *
 * **Every sentence is composed here, not in the browser.** The delete loop
 * builds its line in admin.js because its counts exist only there — nothing on
 * the server saw them. A scan's figures are the opposite: they come off the
 * cursor option, they need the site's locale for the numbers and the site's
 * language for the detector names, and JavaScript has neither
 * `number_format_i18n()` nor the translations. So the batch reply carries the
 * finished strings and admin.js writes them where they go — the line under the
 * bar, and the Resume button's count.
 */
final class ScanProgress
{
    /**
     * Roughly how much longer, from what the run has actually spent.
     *
     * **Deliberately not wall clock since `started_at`.** A browser scan is a
     * chain of AJAX requests a person can stop, navigate away from and resume
     * tomorrow; the seconds between those requests are not seconds the scan
     * spent, and an estimate built on them would tell someone resuming an
     * overnight scan that a thousand files will take a week. ScanState
     * accumulates the time inside batches for exactly this, and nothing else
     * reads it.
     *
     * Null rather than zero where there is nothing to divide by: a scan that
     * has not finished a batch has not earned an estimate, and the label has a
     * shape for that.
     */
    public static function etaSeconds(int $done, int $total, float $elapsed): ?int
    {
        if ($done <= 0 || $elapsed <= 0.0 || $total <= $done) {
            return null;
        }

        return (int) ceil(($total - $done) * ($elapsed / $done));
    }

    /**
     * How wide the bar is drawn, as a whole percent.
     *
     * Composed here rather than in the browser for the reason everything else
     * on this class is: a scan now has two phases with two different pairs of
     * figures behind them, and the server is the only side that knows which
     * pair this reply carries. admin.js draws the number it is handed
     * (freshet-390).
     *
     * **Clamped at both ends, and not by `round()` alone, because both
     * roundings are the defect this exists for.** A first page of 2,000 corpus
     * rows in a million is 0.2 % and rounds to *nothing* — a bar still reading
     * zero while the sentence beside it counts up, which is read as a scan that
     * died rather than one that started. The mirror is worse: 99.6 % rounds to
     * 100 on a pass with rows still to read, so the bar says finished and then
     * carries on. So a pass that has done something shows at least 1, and a
     * pass that has not finished shows at most 99.
     *
     * Zero where there is nothing to divide: a pass resumed from a cursor
     * written by a version that recorded no total has no denominator, and a bar
     * at zero is the honest answer for it. The line underneath still counts.
     */
    public static function percent(int $done, int $total): int
    {
        if ($done <= 0 || $total <= 0) {
            return 0;
        }

        if ($done >= $total) {
            return 100;
        }

        return max(1, min(99, (int) round(($done / $total) * 100)));
    }

    /**
     * A duration in the words a person uses for one.
     *
     * Deliberately the same msgids as UploadGrace::window() — "%s minute" /
     * "%s minutes" and its two neighbours — so a site that has translated the
     * grace window has translated this too. What differs is the arithmetic and
     * not the vocabulary: that one states an exact configured window, this one
     * rounds a measured elapsed time and carries sixty minutes into an hour.
     *
     * Zero gets words rather than a figure. Every caller rounds a float to
     * whole seconds before it arrives here, so a scan of a small library lands
     * on 0 — and "It took 0 seconds" reads as a clock that failed rather than
     * as a scan that was quick. There is no arithmetic for it: a measured
     * duration below the smallest unit this speaks is said, not counted.
     */
    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds === 0) {
            return __('less than a second', 'freshet-unused-media');
        }

        if ($seconds < 60) {
            /* translators: %s: number of seconds */
            return sprintf(_n('%s second', '%s seconds', $seconds, 'freshet-unused-media'), number_format_i18n($seconds));
        }

        if ($seconds < 3600) {
            $minutes = (int) round($seconds / 60);

            if ($minutes < 60) {
                /* translators: %s: number of minutes */
                return sprintf(_n('%s minute', '%s minutes', $minutes, 'freshet-unused-media'), number_format_i18n($minutes));
            }

            // 59 minutes 40 seconds rounds to sixty minutes, which is an hour
            // and has to be said as one: "60 minutes" is a clock nobody reads.
            $seconds = 3600;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = (int) round(($seconds % 3600) / 60);

        // And the same carry one level up: 2 hours 59 minutes 40 seconds is
        // three hours, not "2 hours 60 minutes".
        if ($minutes === 60) {
            ++$hours;
            $minutes = 0;
        }

        /* translators: %s: number of hours */
        $hoursText = sprintf(_n('%s hour', '%s hours', $hours, 'freshet-unused-media'), number_format_i18n($hours));

        if ($minutes === 0) {
            return $hoursText;
        }

        return sprintf(
            /* translators: 1: hours, already worded ("2 hours"), 2: minutes, already worded ("10 minutes") */
            __('%1$s %2$s', 'freshet-unused-media'),
            $hoursText,
            /* translators: %s: number of minutes */
            sprintf(_n('%s minute', '%s minutes', $minutes, 'freshet-unused-media'), number_format_i18n($minutes))
        );
    }

    /**
     * The line under the progress bar while a scan runs.
     *
     * Four shapes rather than one sentence stitched from fragments: a
     * translator needs whole sentences, and the two things that can be missing
     * — the estimate before the first batch lands, and the detector name when
     * a batch measured nothing — change where the punctuation goes.
     *
     * @param array<string, float> $batchTiming This batch's per-detector
     *        seconds, from DetectorTiming::take().
     */
    public static function label(int $done, int $total, float $elapsed, array $batchTiming): string
    {
        $eta = self::etaSeconds($done, $total, $elapsed);
        $busiest = self::busiest($batchTiming);
        $doing = $busiest === null ? '' : DetectorTiming::label($busiest);

        $counts = [number_format_i18n($done), number_format_i18n($total), self::human((int) round($elapsed))];

        if ($eta !== null && $doing !== '') {
            return sprintf(
                /* translators: 1: attachments scanned, 2: attachments in total, 3: time spent so far, 4: estimated time left, 5: what the scan is checking right now */
                __('%1$s / %2$s attachments — %3$s so far, about %4$s left · checking %5$s', 'freshet-unused-media'),
                $counts[0],
                $counts[1],
                $counts[2],
                self::human($eta),
                $doing
            );
        }

        if ($eta !== null) {
            return sprintf(
                /* translators: 1: attachments scanned, 2: attachments in total, 3: time spent so far, 4: estimated time left */
                __('%1$s / %2$s attachments — %3$s so far, about %4$s left', 'freshet-unused-media'),
                $counts[0],
                $counts[1],
                $counts[2],
                self::human($eta)
            );
        }

        if ($doing !== '') {
            return sprintf(
                /* translators: 1: attachments scanned, 2: attachments in total, 3: time spent so far, 4: what the scan is checking right now */
                __('%1$s / %2$s attachments — %3$s so far · checking %4$s', 'freshet-unused-media'),
                $counts[0],
                $counts[1],
                $counts[2],
                $doing
            );
        }

        return sprintf(
            /* translators: 1: attachments scanned, 2: attachments in total, 3: time spent so far */
            __('%1$s / %2$s attachments — %3$s so far', 'freshet-unused-media'),
            $counts[0],
            $counts[1],
            $counts[2]
        );
    }

    /**
     * The line under the bar while the reference index is being built.
     *
     * A scan has two phases, and this is the first: the content of the site read
     * once — posts, custom fields, options, terms, users, comments — before any
     * file is judged. It says which phase it is in so many words, because on a
     * large library it is the longer half and nothing about it looks like
     * checking files (freshet-290).
     *
     * **It has a denominator now, and the sentence changed shape to use it
     * (freshet-390).** The note that used to be here said counting the eight
     * reads would cost more than the phase it describes — which is true per
     * request and false once per pass. The count is taken at startIndex() and
     * kept on the cursor, so the figure here is read, not re-measured, and the
     * bar above the line runs off the same pair.
     *
     * The countless shape is kept for the one case that still has no total: a
     * pass resumed from a cursor a version before this one wrote. It reports
     * what it has read rather than claiming a fraction of nothing.
     *
     * `$total` is widened to `$rows` rather than trusted, for the same reason
     * the file phase widens its own: the corpus is counted once and then
     * written to while the pass runs, so a busy site can read more rows than
     * were there at the start — and "212,000 of 210,210 entries" reads as a
     * defect in the plugin rather than as a site with traffic.
     *
     * Its own sentence rather than a prefix on label(): that one carries an
     * estimate and a detector name, and this phase has neither.
     */
    public static function indexLabel(int $rows, int $references, int $total = 0): string
    {
        if ($total > 0) {
            return sprintf(
                /* translators: 1: entries of site content read so far, 2: entries of site content altogether, 3: references found so far */
                __('Reading your site content to find where media is used: %1$s of %2$s entries read, %3$s references found. The per-file results come next.', 'freshet-unused-media'),
                number_format_i18n($rows),
                number_format_i18n(max($total, $rows)),
                number_format_i18n($references)
            );
        }

        return sprintf(
            /* translators: 1: entries of site content read so far, 2: references found so far */
            __('Reading your site content to find where media is used: %1$s entries read, %2$s references found. The per-file results come next.', 'freshet-unused-media'),
            number_format_i18n($rows),
            number_format_i18n($references)
        );
    }

    /**
     * What the button that restarts a stopped scan says it will resume.
     *
     * The same two figures as the bar, in the same locale, and here for the
     * same reason the line under the bar is: the control is server-rendered at
     * page load and then has to be rewritten from a batch reply, so its text
     * has to exist in one place both surfaces can ask for rather than as a
     * `sprintf` in the screen and a second one in the browser (freshet-304).
     * Without that, stopping a scan at 170 leaves a button still offering to
     * resume from 130 until someone reloads the page.
     *
     * `%1$s / %2$s attachments` and not the bar's fuller sentence: a button is
     * a label and not a report, and the count is the only part of it that can
     * go stale. The unit is in it because the figures above the button count
     * files, and on a library where several rows share one file the two totals
     * are not close (freshet-308) — the same word the bar uses for the same
     * pair, so the button reads as the bar's first clause.
     */
    public static function resumeLabel(int $done, int $total): string
    {
        return sprintf(
            /* translators: 1: attachments scanned so far, 2: attachments in total */
            __('Resume scan (%1$s / %2$s attachments)', 'freshet-unused-media'),
            number_format_i18n($done),
            number_format_i18n($total)
        );
    }

    /**
     * Where a finished run's time went: "post content 41%, custom fields 33%".
     *
     * The shares are of the time *inside the detectors*, which is not the whole
     * of a scan — the cursor queries, the sibling lookups and the disk reads
     * are outside them — so this is read as a ranking of the checks against one
     * another, and the screen says so in the sentence that carries it.
     *
     * Empty string where there is nothing to rank: a run too short to measure,
     * or a cursor written by a version that did not record this. The callers
     * print nothing at all rather than a heading over an empty list.
     *
     * @param array<string, float> $timing Seconds per detector id.
     */
    public static function breakdown(array $timing, int $max = 5): string
    {
        $shares = self::shares($timing);

        if ($shares === []) {
            return '';
        }

        $listed = array_slice($shares, 0, max(1, $max), true);
        $items = [];

        foreach ($listed as $detectorId => $percent) {
            $items[] = sprintf(
                /* translators: 1: what the scan was checking ("post content"), 2: its share of the scan's time as a percentage */
                __('%1$s %2$s%%', 'freshet-unused-media'),
                DetectorTiming::label((string) $detectorId),
                number_format_i18n($percent)
            );
        }

        $text = implode(', ', $items);

        // The tail is said rather than dropped: a reader who sees five checks
        // and no ellipsis is entitled to believe those were all of them.
        if (count($shares) > count($listed)) {
            $text .= ', …';
        }

        return $text;
    }

    /**
     * Percentage of the measured time per detector, largest first.
     *
     * Whole percents, and anything that rounds to nothing is dropped: a list
     * ending "…, comments 0%" reads as a defect rather than as a detector that
     * cost nothing.
     *
     * @param array<string, float> $timing Seconds per detector id.
     * @return array<string, int>
     */
    public static function shares(array $timing): array
    {
        $total = 0.0;

        foreach ($timing as $seconds) {
            $total += max(0.0, (float) $seconds);
        }

        if ($total <= 0.0) {
            return [];
        }

        arsort($timing);

        $shares = [];

        foreach ($timing as $detectorId => $seconds) {
            $percent = (int) round((max(0.0, (float) $seconds) / $total) * 100);

            if ($percent > 0) {
                $shares[(string) $detectorId] = $percent;
            }
        }

        return $shares;
    }

    /**
     * The detector this batch spent most of its time in.
     *
     * What "checking …" names. A batch is ten attachments run through all ten
     * detectors, so no single detector is running at the instant the reply is
     * written — the honest answer to "what is it doing" is where the time in
     * the slice just finished actually went, and on a real library that is
     * stable enough from batch to batch to read as a phase rather than as a
     * flicker.
     *
     * @param array<string, float> $timing Seconds per detector id.
     */
    public static function busiest(array $timing): ?string
    {
        $busiest = null;
        $best = 0.0;

        foreach ($timing as $detectorId => $seconds) {
            if ((float) $seconds > $best) {
                $best = (float) $seconds;
                $busiest = (string) $detectorId;
            }
        }

        return $busiest;
    }
}

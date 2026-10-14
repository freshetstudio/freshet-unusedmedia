<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Cursor state of the full-library scan, stored in one option so a scan
 * survives page reloads and can be resumed.
 */
final class ScanState
{
    private const OPTION = 'freshet_unusedmedia_scan';
    private const OPTION_LAST = 'freshet_unusedmedia_last_scan';

    /**
     * The reference index's own cursor (freshet-290).
     *
     * A separate option from the attachment cursor above because it is a
     * different kind of position: that one is an attachment id, this one is
     * *(which of the eight reads, how far into it)*. Keeping them apart is what
     * lets a pass be resumed, reset or found incomplete without touching the
     * scan that reads it.
     */
    private const OPTION_INDEX = 'freshet_unusedmedia_index';

    /**
     * `errors` is how many attachments the run could not judge because a query
     * failed. It rides in the cursor rather than being recounted, because there
     * is nothing left on the attachment to count: a scan that could not answer
     * writes no status (see Scanner). Without this the run would finish with a
     * quietly short answer and no way to say so (freshet-141).
     *
     * `dirs_read` / `dirs_unread` are the same idea one layer down: whether the
     * upload directories the run looked in could be read at all. Nothing on the
     * attachment records that either — an unreadable directory yields no
     * siblings and no error, exactly as an empty one does — so a library whose
     * files are not on this server loses the disk half of detection silently.
     * These are what the screen says it with (freshet-144). Read as zero /
     * non-zero and never quoted as a figure: they count directory *reads*, and
     * SizeSiblings::takeDirectoryReads() says why the two are not the same.
     *
     * `elapsed` is the seconds the scan actually spent scanning — the batches
     * added up, not the wall clock since `started_at`. The two are the same
     * number only for a run nobody paused, and a browser scan is exactly the
     * kind a person stops, navigates away from and resumes tomorrow. It is what
     * the estimate on screen is built from, so it has to be the honest one
     * (freshet-286; ScanProgress::etaSeconds() says why).
     *
     * `timing` is seconds per detector id, accumulated the same way, so a
     * finished run can say where its time went. Ten small floats in a cursor
     * that already carries seven integers.
     *
     * Both read through `??` like everything else here: a scan started under
     * 1.0.7 and resumed under this version has neither key, and the run picks
     * up from zero rather than refusing to resume.
     *
     * @return array{cursor: int, done: int, total: int, started_at: int, errors: int, dirs_read: int, dirs_unread: int, elapsed: float, timing: array<string, float>}|null
     */
    public function current(): ?array
    {
        $state = get_option(self::OPTION);

        if (!is_array($state)) {
            return null;
        }

        return [
            'cursor' => (int) ($state['cursor'] ?? 0),
            'done' => (int) ($state['done'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'started_at' => (int) ($state['started_at'] ?? 0),
            'errors' => (int) ($state['errors'] ?? 0),
            'dirs_read' => (int) ($state['dirs_read'] ?? 0),
            'dirs_unread' => (int) ($state['dirs_unread'] ?? 0),
            'elapsed' => (float) ($state['elapsed'] ?? 0.0),
            'timing' => self::timings($state['timing'] ?? []),
        ];
    }

    /**
     * Seconds per detector id, as floats, from whatever the option held.
     *
     * The keys are detector ids — `freshet_unusedmedia_detectors` means a
     * third party's can be among them — and the values come back out of an
     * option that anything on the site can have written. Cast both rather than
     * trust either: this array is summed, divided and printed.
     *
     * @param mixed $timing
     * @return array<string, float>
     */
    private static function timings(mixed $timing): array
    {
        if (!is_array($timing)) {
            return [];
        }

        $clean = [];

        foreach ($timing as $detectorId => $seconds) {
            if (is_scalar($seconds)) {
                $clean[(string) $detectorId] = max(0.0, (float) $seconds);
            }
        }

        return $clean;
    }

    public function start(int $total): array
    {
        $state = [
            'cursor' => 0,
            'done' => 0,
            'total' => $total,
            'started_at' => time(),
            'errors' => 0,
            'dirs_read' => 0,
            'dirs_unread' => 0,
            'elapsed' => 0.0,
            'timing' => [],
        ];
        update_option(self::OPTION, $state, false);

        return $state;
    }

    /**
     * @param array{read: int, unread: int} $dirs This batch's directory tally,
     *        from SizeSiblings::takeDirectoryReads().
     * @param float $elapsed Seconds this batch spent scanning.
     * @param array<string, float> $timing This batch's per-detector seconds,
     *        from DetectorTiming::take().
     */
    public function advance(
        int $cursor,
        int $processed,
        int $errors = 0,
        array $dirs = ['read' => 0, 'unread' => 0],
        float $elapsed = 0.0,
        array $timing = []
    ): array {
        $state = $this->current() ?? $this->start(0);
        $state['cursor'] = $cursor;
        $state['done'] += $processed;
        $state['errors'] += $errors;
        $state['dirs_read'] += (int) ($dirs['read'] ?? 0);
        $state['dirs_unread'] += (int) ($dirs['unread'] ?? 0);
        $state['elapsed'] += max(0.0, $elapsed);

        foreach (self::timings($timing) as $detectorId => $seconds) {
            $state['timing'][$detectorId] = ($state['timing'][$detectorId] ?? 0.0) + $seconds;
        }

        update_option(self::OPTION, $state, false);

        return $state;
    }

    public function finish(int $unusedCount): void
    {
        $state = $this->current();

        update_option(self::OPTION_LAST, [
            'finished_at' => time(),
            'scanned' => (int) ($state['done'] ?? 0),
            'unused' => $unusedCount,
            'errors' => (int) ($state['errors'] ?? 0),
            'dirs_read' => (int) ($state['dirs_read'] ?? 0),
            'dirs_unread' => (int) ($state['dirs_unread'] ?? 0),
            // The cursor is about to be deleted, and with it the only record of
            // how long the run took and where the time went. Both move here, so
            // the screen the scan reloads into can still say it (freshet-286).
            'elapsed' => (float) ($state['elapsed'] ?? 0.0),
            'timing' => self::timings($state['timing'] ?? []),
        ], false);

        delete_option(self::OPTION);
    }

    /**
     * Where the reference-index pass is, and whether it finished.
     *
     * `read` is which of the eight reads the cursor is on — empty before the
     * first one starts — and `pk` is the last primary key that read completed.
     * Together they are the *(table, last primary key)* of freshet-290's
     * Acceptance 4, which is what makes an interrupted pass resumable rather
     * than restartable.
     *
     * `offset` divides a single row, and it is there because one row can be more
     * work than a request has. Measured: `itsec_file_change_latest` on one real
     * library is a 5.4 MB option holding 811,897 digit runs, 2,388 of which are
     * attachment ids — and each of those costs the detector chain about 0.15 s,
     * so that one row is **370 seconds**. A cursor that could only point between
     * rows would hand a browser batch an indivisible unit it cannot finish,
     * time out, and retry the same row for ever. So the cursor also records how
     * many of a row's candidates are already indexed, and a resume skips exactly
     * those. Candidates are extracted in a deterministic order, which is what
     * makes "the first N of them" a position rather than a guess.
     *
     * `corpus` is how many rows the eight reads hold altogether, counted once
     * when the pass starts and then only read. It is the denominator the first
     * phase had no way to say before: `rows / corpus` is what the bar is drawn
     * from while the index is being built, and the line under it reads "40,000
     * of 210,210 entries" rather than counting up against nothing
     * (freshet-390). Counted once and never again — re-counting per request
     * would put eight `COUNT(*)`s back inside the batch budget that freshet-389
     * is defending — which is also why it is read through `??` like the rest: a
     * pass resumed from a cursor written before this version has no total, and
     * the label has a shape for that rather than refusing to resume.
     *
     * `complete` is the one flag a verdict depends on. Nothing answers a file
     * from the index until a pass has run the whole corpus and set it: a
     * half-built index is a library where most rows have not been read yet, and
     * answering off one would read every unvisited file as *unused*. That is
     * freshet-141's refusal one level up, and it is why the flag is written
     * once, at the end, by finishIndex() — never by advance().
     *
     * @return array{read: string, pk: int, offset: int, rows: int, entries: int, corpus: int, elapsed: float, complete: bool, built_at: int}|null
     */
    public function indexCursor(): ?array
    {
        $state = get_option(self::OPTION_INDEX);

        if (!is_array($state)) {
            return null;
        }

        return [
            'read' => (string) ($state['read'] ?? ''),
            'pk' => (int) ($state['pk'] ?? 0),
            'offset' => (int) ($state['offset'] ?? 0),
            'rows' => (int) ($state['rows'] ?? 0),
            'entries' => (int) ($state['entries'] ?? 0),
            'corpus' => (int) ($state['corpus'] ?? 0),
            'elapsed' => (float) ($state['elapsed'] ?? 0.0),
            'complete' => (bool) ($state['complete'] ?? false),
            'built_at' => (int) ($state['built_at'] ?? 0),
        ];
    }

    /** Whether a whole pass has finished and may be answered from. */
    public function indexIsComplete(): bool
    {
        return (bool) ($this->indexCursor()['complete'] ?? false);
    }

    /**
     * Begin a pass from the first read of the first table.
     *
     * `$corpusRows` is the whole corpus, counted by the caller that is about to
     * read it — ReferenceIndexer, which owns the eight reads and is the only
     * thing that can count them. Optional because a pass can run without a
     * denominator and would rather do that than not run: a count that could not
     * be taken leaves a bar at zero and a line that still says what it has read
     * (see indexCursor()).
     */
    public function startIndex(int $corpusRows = 0): array
    {
        $state = [
            'read' => '',
            'pk' => 0,
            'offset' => 0,
            'rows' => 0,
            'entries' => 0,
            'corpus' => max(0, $corpusRows),
            'elapsed' => 0.0,
            'complete' => false,
            'built_at' => 0,
        ];
        update_option(self::OPTION_INDEX, $state, false);

        return $state;
    }

    /**
     * Move the cursor to the end of a page that was fully read and written.
     *
     * Called after the page's rows are committed, never before: a cursor past a
     * row whose references are not on disk is a row skipped for the whole pass,
     * which is the same gap the attachment cursor is careful about.
     */
    public function advanceIndex(string $read, int $pk, int $rows, int $entries, float $elapsed, int $offset = 0): array
    {
        $state = $this->indexCursor() ?? $this->startIndex();
        $state['read'] = $read;
        $state['pk'] = $pk;
        $state['offset'] = max(0, $offset);
        $state['rows'] += $rows;
        $state['entries'] += $entries;
        $state['elapsed'] += max(0.0, $elapsed);
        $state['complete'] = false;

        update_option(self::OPTION_INDEX, $state, false);

        return $state;
    }

    /** The whole corpus has been read: the index may now be answered from. */
    public function finishIndex(): array
    {
        $state = $this->indexCursor() ?? $this->startIndex();
        $state['complete'] = true;
        $state['built_at'] = time();

        update_option(self::OPTION_INDEX, $state, false);

        return $state;
    }

    /**
     * Forget the pass.
     *
     * Deliberately only the cursor: the rows stay until something truncates
     * them. With `complete` gone they are unreadable anyway, and keeping them
     * means a re-index overwrites rather than runs against an empty table.
     */
    public function resetIndex(): void
    {
        delete_option(self::OPTION_INDEX);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array{finished_at: int, scanned: int, unused: int, errors: int, dirs_read: int, dirs_unread: int, elapsed: float, timing: array<string, float>}|null */
    public function lastScan(): ?array
    {
        $last = get_option(self::OPTION_LAST);

        if (!is_array($last)) {
            return null;
        }

        return [
            'finished_at' => (int) ($last['finished_at'] ?? 0),
            'scanned' => (int) ($last['scanned'] ?? 0),
            'unused' => (int) ($last['unused'] ?? 0),
            'errors' => (int) ($last['errors'] ?? 0),
            'dirs_read' => (int) ($last['dirs_read'] ?? 0),
            'dirs_unread' => (int) ($last['dirs_unread'] ?? 0),
            'elapsed' => (float) ($last['elapsed'] ?? 0.0),
            'timing' => self::timings($last['timing'] ?? []),
        ];
    }
}

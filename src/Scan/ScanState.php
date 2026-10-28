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
     * When a reading of the site was last discarded while the verdicts computed
     * from it were left in place (freshet-415).
     *
     * One integer, written by resetIndex(), dropped by finish() — a scan that
     * reaches the end of the library *having started after this mark* has
     * re-judged all of it, and only then (freshet-422) — and read by
     * ReadingInForce, which is where what it means is written out. It is a
     * *date* rather than a flag
     * because the verdicts it holds back are dated too: the new scan brings
     * files back into the delete offer one by one as it re-judges them, rather
     * than the whole listing being trustworthy or not.
     */
    private const OPTION_DROPPED = 'freshet_unusedmedia_reading_dropped';

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
     * `stale_from` is why this scan exists, and it is only ever non-zero on one
     * kind of scan: one the plugin started itself because the reading the last
     * one stood on had gone stale (StaleIndex). It holds the date of the
     * reading that was dropped — or -1 where nothing dated it, since zero has
     * to go on meaning *nobody dropped anything* — and that date is what the
     * sentence on screen quotes. The cursor is where it lives because it is a
     * fact about *this* run, so it is forgotten exactly when the run is, with
     * no record left to clean up after.
     *
     * `started_at` has a reader as of freshet-413, and it is about age rather
     * than duration: it dates a reading the index pass has not finished
     * stamping yet (StaleIndex::takenAt()), since phase one begins immediately
     * after start(). Nothing subtracts it from the wall clock to time a run —
     * `elapsed` is what an estimate is built from, for the reason above — and
     * it was written and read by nobody for four versions, which is how the
     * gap freshet-413 closes stayed invisible. Two readers as of freshet-422,
     * and the second asks the same question at the other end of the run:
     * finish() weighs it against readingDroppedAt() to decide whether the run
     * that reached the end of the library is one entitled to release the
     * verdicts that mark is holding back.
     *
     * Both read through `??` like everything else here: a scan started under
     * 1.0.7 and resumed under this version has neither key, and the run picks
     * up from zero rather than refusing to resume.
     *
     * @return array{cursor: int, done: int, total: int, started_at: int, errors: int, dirs_read: int, dirs_unread: int, elapsed: float, timing: array<string, float>, stale_from: int}|null
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
            'stale_from' => (int) ($state['stale_from'] ?? 0),
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

    /**
     * @param int $staleFrom The date of the reading a stale resume dropped to
     *        make way for this scan, from StaleIndex::rebuild(). Zero — the
     *        default, and what every ordinary start passes — means this scan is
     *        one somebody asked for. See current().
     */
    public function start(int $total, int $staleFrom = 0): array
    {
        $state = self::fresh($total, $staleFrom);
        update_option(self::OPTION, $state, false);

        return $state;
    }

    /**
     * The cursor a run begins on, built but not stored.
     *
     * Separate from start() because advance() needs the same shape for a batch
     * whose run has been reset out from under it — one it describes and does not
     * store. One definition, so the two cannot drift.
     *
     * @return array{cursor: int, done: int, total: int, started_at: int, errors: int, dirs_read: int, dirs_unread: int, elapsed: float, timing: array<string, float>, stale_from: int}
     */
    private static function fresh(int $total, int $staleFrom = 0): array
    {
        return [
            'cursor' => 0,
            'done' => 0,
            'total' => $total,
            'started_at' => time(),
            'errors' => 0,
            'dirs_read' => 0,
            'dirs_unread' => 0,
            'elapsed' => 0.0,
            'timing' => [],
            'stale_from' => $staleFrom,
        ];
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
        $running = $this->current();
        $state = $running ?? self::fresh(0);
        $state['cursor'] = $cursor;
        $state['done'] += $processed;
        $state['errors'] += $errors;
        $state['dirs_read'] += (int) ($dirs['read'] ?? 0);
        $state['dirs_unread'] += (int) ($dirs['unread'] ?? 0);
        $state['elapsed'] += max(0.0, $elapsed);

        foreach (self::timings($timing) as $detectorId => $seconds) {
            $state['timing'][$detectorId] = ($state['timing'][$detectorId] ?? 0.0) + $seconds;
        }

        // A reset that landed while this batch was in flight took the cursor
        // with it, and the position this batch earned belongs to a run that no
        // longer exists. Storing it would not resume that run — it would
        // *recreate* a cursor already past the rows the reset asked to have
        // re-judged, and the next request, finding a cursor where it should find
        // nothing, would carry on from the middle of the library instead of
        // starting over. Every file below that point would keep the verdict the
        // discarded reading produced, and the run would then reach the end and
        // release the whole library for deletion on the strength of it
        // (freshet-422; finish() guards the other half).
        //
        // So the batch is described and not stored: the figures go back for the
        // line on screen, and nothing is written. The next request reads no
        // cursor, which is what a reset means, and sizes and starts the scan
        // over exactly as Ajax::scanBatch() does for a scan somebody asked for.
        if ($running === null) {
            return $state;
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

        // A run that got here read the library to the end, so every verdict in
        // it is this run's own and no discarded reading is holding any of them
        // back any more (freshet-415). The mark goes where the cursor goes: the
        // one place a scan is complete is the one place it can be dropped, and
        // a run that was stopped half-way leaves it exactly where it was.
        //
        // **Only a run that began after the discard it would be releasing**
        // (freshet-422). "Read the library to the end" is a claim about this
        // run, and it is only a claim about the *current* reading if the run
        // started under it. A run older than the mark read part of the library
        // before the reading was thrown away, so some of the verdicts the mark
        // is holding back are ones it never re-judged — and dropping it would
        // offer those files for deletion on an answer nothing stands behind.
        // A run with no cursor left at all is that case at its starkest: it was
        // reset out from under itself, and answers 0 here.
        //
        // This does compare two clocks, which ReadingInForce::watermark() says
        // the *hold itself* must never be derived from — and it still must not
        // be: the mark is a stored fact, and what is weighed here is only
        // whether this run is the one allowed to clear it. The failure that
        // paragraph names is a library that reads and scans inside one second
        // being held back for ever, and both halves of this keep it off that
        // edge: `>=` rather than `>`, and every caller that begins a fresh scan
        // dates the discard *before* it starts the run (Ajax::scanBatch() is
        // the one on screen), so the two marks are never out of order by so
        // much as a second.
        if ((int) ($state['started_at'] ?? 0) >= $this->readingDroppedAt()) {
            delete_option(self::OPTION_DROPPED);
        }
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
     * `run` names the pass. Minted by startIndex() and carried unchanged by
     * every advance of that pass, so a request can tell the pass it is driving
     * from a *different* pass that replaced it while it was reading — a second
     * Start, or a Reset followed by one. It is half of indexToken(), which is
     * the thing the three dangerous writes are actually guarded by; see there
     * for the other half and for why position has to be in it too
     * (freshet-424). Read through `??` like the rest: a pass begun under 1.2.1
     * and resumed under this version has no id, which costs it nothing —
     * everything in that cursor is still compared, so the guard holds on
     * position alone until the next pass mints one.
     *
     * @return array{run: string, read: string, pk: int, offset: int, rows: int, entries: int, corpus: int, elapsed: float, complete: bool, built_at: int}|null
     */
    public function indexCursor(): ?array
    {
        $state = get_option(self::OPTION_INDEX);

        if (!is_array($state)) {
            return null;
        }

        return [
            'run' => (string) ($state['run'] ?? ''),
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

    /**
     * The cursor as it is on disk *now*, not as this request last read it.
     *
     * The whole of freshet-424 rests on this one line, and the guard below is
     * decoration without it. A non-autoloaded option is cached per request in
     * the `options` group, so the second `get_option()` of a request answers
     * out of that cache and **cannot see a write another request made in
     * between** — which is precisely the write the guard exists to notice. The
     * cache is dropped first, so the read goes to the database.
     *
     * Costed where it is called rather than everywhere: this is the read
     * behind the three writes that can damage the index, which is once per
     * read entered, once per page of 2,000 rows, and once at the end. The
     * screen, StaleIndex and the per-file lookups keep the ordinary cached
     * indexCursor(), because a request that has only just started is reading
     * the database anyway.
     *
     * @return array{run: string, read: string, pk: int, offset: int, rows: int, entries: int, corpus: int, elapsed: float, complete: bool, built_at: int}|null
     */
    private function storedIndexCursor(): ?array
    {
        wp_cache_delete(self::OPTION_INDEX, 'options');

        return $this->indexCursor();
    }

    /**
     * What a request holds while it drives the index pass, as one comparable
     * string. Empty for no pass at all.
     *
     * **Run *and* position, and the second half is not belt-and-braces.** A run
     * id alone catches the second Start — a new pass, a new id, and the older
     * request's writes are refused. It does not catch the other half of
     * freshet-424, which is two requests driving *the same* run: both read the
     * same cursor, both walk forward from it, and the one that gets to the end
     * first stamps `complete` while the slower one is still rewinding a read it
     * is about to re-read. Same run id throughout, and an index with a hole in
     * it that every lookup trusts.
     *
     * So the token is the cursor itself, and a write is a compare-and-swap: it
     * lands only if the stored cursor is still the one this request last saw.
     * Of two concurrent requests the one that writes second is refused, stops,
     * and leaves the first driving alone. Totals (`rows`, `entries`, `elapsed`)
     * are deliberately out of it — they are accumulators, and two requests
     * disagreeing about them is not two requests disagreeing about *where the
     * pass is*.
     *
     * No lease and no expiry, which is the reason this shape was taken over a
     * lock: a request killed by `max_execution_time` holds nothing, so the next
     * one reads the cursor and carries on immediately. A lock long enough to be
     * a lock is a lock long enough to wedge the resume that freshet-389 and
     * freshet-414 exist to keep fast.
     *
     * @param array<string, mixed>|null $cursor
     */
    public static function indexToken(?array $cursor): string
    {
        if ($cursor === null) {
            return '';
        }

        return sprintf(
            '%s|%s|%d|%d|%d',
            (string) ($cursor['run'] ?? ''),
            (string) ($cursor['read'] ?? ''),
            (int) ($cursor['pk'] ?? 0),
            (int) ($cursor['offset'] ?? 0),
            (bool) ($cursor['complete'] ?? false) ? 1 : 0
        );
    }

    /**
     * Refuse unless this request is still the one driving the pass.
     *
     * Called by the two cursor writes below, and by ReferenceIndexer before
     * each of the three `forgetAfter()` rewinds — the one damaging write that
     * is not a cursor write and so cannot guard itself.
     *
     * No cursor at all is refused too, and that is the Reset case rather than
     * a technicality: an empty token would otherwise match an empty option and
     * let a request that has had its pass deleted out from under it write a
     * cursor back.
     *
     * @return array{run: string, read: string, pk: int, offset: int, rows: int, entries: int, corpus: int, elapsed: float, complete: bool, built_at: int} The live cursor, for a caller about to amend it.
     * @throws ScanSuperseded Somebody else is driving; see the class note.
     */
    public function assertIndexToken(string $token): array
    {
        $cursor = $this->storedIndexCursor();

        if ($cursor === null || self::indexToken($cursor) !== $token) {
            throw new ScanSuperseded(ScanSuperseded::userMessage()); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never rendered: callers catch it and send ScanSuperseded::userMessage() as JSON or CLI text.
        }

        return $cursor;
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
     *
     * **It mints the run id**, and that is the only place one is made: a pass
     * starting is the one event that makes a *new* pass, so every other write
     * carries the id it finds rather than issuing one. `random_bytes()` rather
     * than `uniqid()` because two admins pressing Start together is the case
     * this exists for and that is two requests in the same microsecond on the
     * same host — the one input `uniqid()` is built from.
     */
    public function startIndex(int $corpusRows = 0): array
    {
        $state = [
            'run' => bin2hex(random_bytes(8)),
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
     *
     * `$token` is what the caller last saw, and the write lands only if the
     * stored cursor is still that — see indexToken(). The `?? startIndex()`
     * this used to open with is gone with it: a cursor that is no longer there
     * was reset out from under this request, and putting one back is the
     * resurrection freshet-422 took out of the attachment cursor for the same
     * reason. Here the guard refuses both that and the request that was merely
     * overtaken, because they are the same question.
     *
     * @throws ScanSuperseded This request is no longer driving the pass.
     */
    public function advanceIndex(string $token, string $read, int $pk, int $rows, int $entries, float $elapsed, int $offset = 0): array
    {
        $state = $this->assertIndexToken($token);
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

    /**
     * The whole corpus has been read: the index may now be answered from.
     *
     * The single most dangerous write in the plugin, and the one freshet-424
     * is named after: it is what turns a table of rows into an answer every
     * lookup trusts, so a request that stamps it while *another* request is
     * rewinding a read publishes the hole. Guarded like the advance above — it
     * is this request's own traversal that reached the end, and that says
     * nothing about the corpus if the cursor moved under it.
     *
     * @throws ScanSuperseded This request is no longer driving the pass.
     */
    public function finishIndex(string $token): array
    {
        $state = $this->assertIndexToken($token);
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
        // The discard is dated on the way out, because the verdicts computed
        // from this reading outlive it: they are post meta on every attachment
        // in the library and nothing here can walk that in one request. A
        // verdict older than this mark is one the delete offer is closed for
        // until the new scan reaches its file — ReadingInForce says why, and
        // this is the only place that writes the mark, so every path that
        // throws a reading away is covered by the one line (freshet-415).
        //
        // Only where there was a reading to throw away. A resetIndex() over
        // nothing discarded nothing, and dating that would close the offer on
        // verdicts whose own reading is still the one in force.
        if ($this->indexCursor() !== null) {
            update_option(self::OPTION_DROPPED, time(), false);
        }

        delete_option(self::OPTION_INDEX);
    }

    /**
     * When a reading was last discarded with its verdicts left behind, or 0 if
     * never. See OPTION_DROPPED; the rule it feeds is ReadingInForce.
     */
    public function readingDroppedAt(): int
    {
        return (int) get_option(self::OPTION_DROPPED);
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

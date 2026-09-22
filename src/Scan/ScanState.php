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

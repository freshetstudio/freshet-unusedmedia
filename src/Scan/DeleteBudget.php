<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * How much of a request the delete loop may spend before it hands the rest on.
 *
 * The scan already stops on the clock, and for a smaller reason: a scan batch
 * that runs long is a progress bar moving slowly. **A delete request has no
 * progress bar and no second half.** It is one POST, and one killed at
 * `max_execution_time` answers nothing at all — the browser is told the batch
 * failed, and what the run did or did not remove is never reported.
 *
 * On a large library that is not a corner case, because of what re-verifying
 * costs. A deletion is by file, and every attachment row standing on that file
 * is re-scanned before it goes; on a library where a file averages ten rows,
 * one deletion is ten scans. Multiply by the batch and the request is spending
 * minutes in queries. **The work is bounded here rather than made cheaper** —
 * the re-verification is the last thing between a stale verdict and a file that
 * is gone, and nothing about it is skipped to fit a clock.
 *
 * Two things make that bound hold, and they are different mechanisms:
 *
 * - **The ceiling is raised first, where the host allows it.** One file is
 *   indivisible — its rows are re-scanned and then it is deleted, or it is not
 *   touched — so on a library whose files cost more than the whole limit, a
 *   budget carved out of that limit could never start one. Asking for room is
 *   what makes the bound achievable rather than merely honest.
 * - **What is left is spent by measurement, not by hope.** A file is only
 *   started when the slowest file this request has already decided would still
 *   fit in what remains. The first file of a request is the exception and has
 *   to be: nothing has been measured yet, and a request that started no file
 *   would hand the same work to the next one for ever.
 *
 * So the honest statement of the bound: **a delete request starts no file it
 * has reason to believe will not fit, and never leaves a file half-decided.**
 * What it cannot promise is a host that also enforces a limit above PHP's own
 * — a proxy or FastCGI read timeout is outside anything this can raise. That
 * failure is a request that never answers, which the loop reports rather than
 * mistaking for a completed pass: the files it did not reach kept their stored
 * verdict and are still listed.
 */
final class DeleteBudget
{
    /** Seconds of work a delete request will start, before the host trims it. */
    private const DEFAULT_SECONDS = 20.0;

    private float $started;

    /** The slowest file decided in this request — the cost the next one is measured against. */
    private float $slowest = 0.0;

    private int $decided = 0;

    public function __construct(private readonly float $seconds)
    {
        $this->started = microtime(true);
    }

    /**
     * The budget for the request now running.
     *
     * Half of whatever ceiling this ends up under, for the reason the scan
     * takes half: the file in flight when the budget runs out is not
     * interrupted, so the other half is the room it finishes in.
     */
    public static function forRequest(): self
    {
        $want = max(0.0, (float) apply_filters('freshet_unusedmedia_delete_seconds', self::DEFAULT_SECONDS));

        $limit = (int) ini_get('max_execution_time');

        // Ask for the room, then read what was actually given. A host that
        // refuses is not an error — the budget simply comes out of what it
        // does allow, and the loop takes more requests to get through the same
        // files. `set_time_limit()` restarts the clock as well as raising it,
        // which is why this happens before any work rather than mid-loop.
        if ($limit > 0 && $limit < $want * 2 && function_exists('set_time_limit')) {
            set_time_limit((int) ceil($want * 2));
            $limit = (int) ini_get('max_execution_time');
        }

        return new self($limit > 0 ? min($want, $limit / 2) : $want);
    }

    /**
     * May another file be started?
     *
     * Asked before a file rather than after one, because after is too late: the
     * decision on a file cannot be interrupted half way, so the only place the
     * loop can stop is between two of them.
     */
    public function hasRoom(): bool
    {
        // Nothing measured yet. Refusing here would mean a request that
        // deleted nothing, and a loop that never finishes.
        if ($this->decided === 0) {
            return true;
        }

        return ($this->elapsed() + $this->slowest) <= $this->seconds;
    }

    /**
     * What one file cost, whatever the decision was.
     *
     * A skipped file is not a cheap one: it paid for the sibling lookup, the
     * claims read and — unless the claims read settled it — the full re-scan.
     * Recording only deletions would size the next file against the wrong
     * number.
     */
    public function record(float $seconds): void
    {
        ++$this->decided;
        $this->slowest = max($this->slowest, $seconds);
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->started;
    }
}

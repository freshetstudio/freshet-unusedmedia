<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * A database read that did not answer.
 *
 * `$wpdb` makes silence indistinguishable from emptiness: `get_results()` hands
 * back `last_result`, which is emptied before every query and stays empty when
 * one fails, so **a query that errored is byte-identical at the call site to a
 * query that matched no rows**. Core retries exactly one error (the server
 * going away mid-connection); an execution-time kill, a lost connection or a
 * packet the server refuses all come back as an empty array.
 *
 * Every detector reads that array as "nothing references this file". So a
 * timed-out query — and the heaviest of these queries is one of the slowest
 * things this plugin does — became a file offered for deletion, and the
 * re-verification that is supposed to catch exactly that ran the same queries
 * in the same request and agreed with itself.
 *
 * The answer is this exception. A read whose result decides a verdict raises it
 * instead of returning silence, and every caller that could act on the answer
 * refuses to act: no status is written, nothing is deleted, and the screen says
 * so.
 */
final class QueryFailed extends \RuntimeException
{
    /**
     * @param string $context Which read failed, in this plugin's own words.
     * @param string $dbError The driver's message, for the log rather than the screen.
     */
    public function __construct(public readonly string $context, string $dbError)
    {
        parent::__construct(sprintf('%s: %s', $context, $dbError));
    }

    /**
     * What a person is told. Deliberately not the driver's text: it names a
     * table nobody asked about, and the only thing the reader can act on is
     * that the answer is missing rather than empty.
     */
    public static function userMessage(): string
    {
        return __('The database did not answer one of the usage queries, so this could not be checked.', 'freshet-unused-media');
    }
}

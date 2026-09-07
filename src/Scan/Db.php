<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * The one place a `$wpdb` read is asked whether it actually answered.
 *
 * `$wpdb->last_error` is empty after a query that succeeded — `wpdb::query()`
 * flushes it before running — and carries the driver's message after one that
 * did not. Reading it *immediately* after the call, before the result is
 * interpreted, is the only thing that separates "no rows matched" from "the
 * query never ran". See QueryFailed for why that distinction decides whether a
 * file is deleted.
 *
 * A stale error left by someone else's query makes a read here refuse rather
 * than answer, which is the safe direction; `forget()` exists so a scan can
 * start from a clean slate and not inherit another plugin's failure for the
 * rest of the request.
 */
final class Db
{
    /**
     * A row set, or nothing at all.
     *
     * Two failures look the same from outside and both raise: an error the
     * driver reported, and a query that never ran — `get_results()` returns
     * null for an empty query string, which is what a `prepare()` that rejected
     * its placeholders leaves behind.
     *
     * @param string $context Which read this is, for the message.
     * @param mixed  $result  Whatever the $wpdb call returned.
     * @return array<int|string, mixed>
     */
    public static function rows(string $context, mixed $result): array
    {
        self::assertAnswered($context);

        if ($result === null) {
            throw new QueryFailed($context, 'the query was not run');
        }

        return (array) $result;
    }

    /**
     * A single value, or nothing at all. `null` is a legitimate answer here
     * (`get_var()` on a set with no rows), so only the error is a failure.
     */
    public static function value(string $context, mixed $result): mixed
    {
        self::assertAnswered($context);

        return $result;
    }

    /** @throws QueryFailed if the last query left an error behind. */
    public static function assertAnswered(string $context): void
    {
        global $wpdb;

        $error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';

        if ($error !== '') {
            throw new QueryFailed($context, $error);
        }
    }

    /**
     * Drop an error left by a query this plugin did not make.
     *
     * Called once at the top of a scan, never between two of its own reads: the
     * point is that a failure of ours is still there when we look for it.
     */
    public static function forget(): void
    {
        global $wpdb;

        if (isset($wpdb->last_error)) {
            $wpdb->last_error = '';
        }
    }
}

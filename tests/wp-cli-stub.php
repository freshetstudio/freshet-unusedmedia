<?php

/**
 * WP-CLI, stubbed — not a suite, and not runnable on its own.
 *
 * `src/Cli/ScanCommand.php` is the one class in this plugin that talks to
 * something other than WordPress, and the two things it says about a run
 * — WP_CLI::error() and WP_CLI::success() — are exactly what a test of "a run
 * that stopped early must not report as complete" has to read. So they are
 * recorded here rather than printed, in $GLOBALS['cli'].
 *
 * error() throws. That is not a convenience: the real WP_CLI::error() prints
 * and stops the command, so a test that let it return would go on to execute
 * lines the command never reaches.
 *
 * Bracketed namespaces because WP-CLI puts its helpers in WP_CLI\Utils and its
 * entry points on a global class, and a single file can only declare one
 * unbracketed namespace.
 */

declare(strict_types=1);

namespace {
    // This file ships inside the plugin, so it must not be executable over
    // HTTP — the same guard the suites that include it carry.
    if (PHP_SAPI !== 'cli') {
        exit;
    }

    /** What WP_CLI::error() does for real: it says so, and the command stops. */
    final class CliHalt extends \Exception
    {
    }

    final class WP_CLI
    {
        /** @throws CliHalt always — the real one exits. */
        public static function error(string $message): void
        {
            $GLOBALS['cli']['errors'][] = $message;

            throw new CliHalt($message);
        }

        public static function warning(string $message): void
        {
            $GLOBALS['cli']['warnings'][] = $message;
        }

        public static function success(string $message): void
        {
            $GLOBALS['cli']['success'][] = $message;
        }

        public static function log(string $message): void
        {
            $GLOBALS['cli']['log'][] = $message;
        }
    }
}

namespace WP_CLI\Utils {
    /** The progress bar, which the scan only builds in its human format. */
    function make_progress_bar(string $message, int $count, int $interval = 100): object
    {
        $GLOBALS['cli']['progress'][] = [$message, $count];

        return new class {
            public function tick(): void
            {
            }

            public function finish(): void
            {
            }
        };
    }

    /**
     * The formatted output — the summary table of a finished scan, or the list.
     * Recorded rather than printed: whether this was reached at all is half of
     * what the CLI tests assert.
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $fields
     */
    function format_items(string $format, array $items, array $fields): void
    {
        $GLOBALS['cli']['items'][] = ['format' => $format, 'items' => $items, 'fields' => $fields];
    }
}

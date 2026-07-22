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

    /** @return array{cursor: int, done: int, total: int, started_at: int}|null */
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
        ];
    }

    public function start(int $total): array
    {
        $state = ['cursor' => 0, 'done' => 0, 'total' => $total, 'started_at' => time()];
        update_option(self::OPTION, $state, false);

        return $state;
    }

    public function advance(int $cursor, int $processed): array
    {
        $state = $this->current() ?? $this->start(0);
        $state['cursor'] = $cursor;
        $state['done'] += $processed;
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
        ], false);

        delete_option(self::OPTION);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array{finished_at: int, scanned: int, unused: int}|null */
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
        ];
    }
}

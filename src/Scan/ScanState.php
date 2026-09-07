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
     * @return array{cursor: int, done: int, total: int, started_at: int, errors: int, dirs_read: int, dirs_unread: int}|null
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
        ];
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
        ];
        update_option(self::OPTION, $state, false);

        return $state;
    }

    /**
     * @param array{read: int, unread: int} $dirs This batch's directory tally,
     *        from SizeSiblings::takeDirectoryReads().
     */
    public function advance(int $cursor, int $processed, int $errors = 0, array $dirs = ['read' => 0, 'unread' => 0]): array
    {
        $state = $this->current() ?? $this->start(0);
        $state['cursor'] = $cursor;
        $state['done'] += $processed;
        $state['errors'] += $errors;
        $state['dirs_read'] += (int) ($dirs['read'] ?? 0);
        $state['dirs_unread'] += (int) ($dirs['unread'] ?? 0);
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
        ], false);

        delete_option(self::OPTION);
    }

    public function reset(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array{finished_at: int, scanned: int, unused: int, errors: int, dirs_read: int, dirs_unread: int}|null */
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
        ];
    }
}

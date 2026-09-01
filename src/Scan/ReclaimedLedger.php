<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * What this plugin has actually freed — a running total, written as deletions
 * happen.
 *
 * It has to be recorded at deletion time because afterwards there is nothing
 * left to measure: the difference between "these files hold 1.2 GB" and "1.2 GB
 * was freed" is the whole reason the second number cannot be derived from a
 * scan. A total that quietly re-reported the first as the second would be an
 * inflated claim in the one plugin whose selling argument is that its evidence
 * can be checked.
 *
 * Two things it deliberately does not count. A file sent to the media trash
 * frees no disk at all — the attachment moves, the file stays — so it is
 * tallied separately as pending rather than as reclaimed. And a file that could
 * not be sized (offloaded, no recorded size) is counted as unknown rather than
 * folded into the byte figure as zero.
 *
 * Recording lives outside the paid tier on purpose: it is four integers in one
 * option, invisible in the free build, and it never consults the license — a
 * free deletion path must not pay for a licensed screen. A site that buys a key
 * later then has a real history behind the number instead of a zero.
 */
final class ReclaimedLedger
{
    public const OPTION = 'freshet_unusedmedia_reclaimed';

    /**
     * Add one deletion pass to the running totals. One write per pass rather
     * than per file: fewer option updates, and a narrower window for two
     * overlapping batches to lose each other's increment.
     */
    public static function add(int $bytes, int $files, int $unsized, int $trashed): void
    {
        if ($files === 0 && $unsized === 0 && $trashed === 0) {
            return;
        }

        $current = self::read();

        update_option(self::OPTION, [
            'bytes' => $current['bytes'] + max(0, $bytes),
            'files' => $current['files'] + max(0, $files),
            'unsized' => $current['unsized'] + max(0, $unsized),
            'trashed' => $current['trashed'] + max(0, $trashed),
            'last_at' => time(),
        ], false);
    }

    /** @return array{bytes: int, files: int, unsized: int, trashed: int, last_at: int} */
    public static function read(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'bytes' => (int) ($stored['bytes'] ?? 0),
            'files' => (int) ($stored['files'] ?? 0),
            'unsized' => (int) ($stored['unsized'] ?? 0),
            'trashed' => (int) ($stored['trashed'] ?? 0),
            'last_at' => (int) ($stored['last_at'] ?? 0),
        ];
    }
}

<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\ReclaimedLedger;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * Space — the paid tier's fourth surface, and the number a licence is argued
 * from.
 *
 * It says two things and keeps them apart, because they answer different
 * questions and running them together is how a total becomes a lie:
 *
 * - **Reclaimable** — bytes held right now by the files on the unused list. The
 *   figure to quote before touching anything. It moves with every scan and
 *   nothing has been freed.
 * - **Reclaimed** — bytes this plugin has actually freed by deleting files. The
 *   figure that proves the clean-up happened. It comes from ReclaimedLedger,
 *   written at deletion time, and is never the reclaimable number wearing a
 *   different label.
 *
 * Both are aggregates of FileSize::bytes() — the same computation the unused
 * table, the WP-CLI list and the evidence report show per file, so the total
 * reconciles against the rows a customer can see. There is no scan here, no
 * store of its own and nothing scheduled: it adds up results that already
 * exist, when someone opens the page.
 */
final class SpaceTotals
{
    private const CAP = 'manage_options';

    /** Unused IDs pulled per page while summing; keeps a large library out of memory. */
    private const BATCH = 200;

    public function __construct(
        private readonly ResultStore $store,
        private readonly LicenseInterface $license,
    ) {
    }

    /**
     * The section on Media → Usage. Renders its own wrapper and nothing at all
     * without a valid key, matching EvidenceReport: an empty heading where a
     * feature is not entitled reads as a broken screen.
     */
    public function render(): void
    {
        if (!current_user_can(self::CAP) || !$this->license->isPro()) {
            return;
        }

        $reclaimable = $this->reclaimable();
        $reclaimed = ReclaimedLedger::read();

        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . esc_html__('Space', 'freshet-unused-media') . '</h2>';

        echo '<p class="description">' . esc_html__('Two figures, kept apart on purpose: what the unused files are holding now, and what deleting them has actually freed so far. Both are measured from the files themselves — the same size shown against each file in the table above — and count the original file only, so the disk a deletion really frees is larger than the number here, never smaller.', 'freshet-unused-media') . '</p>';

        echo '<p class="freshet-unusedmedia-counts">';
        printf(
            '<strong>%s</strong> &nbsp;•&nbsp; <strong>%s</strong>',
            esc_html(sprintf(
                /* translators: 1: formatted file size, 2: number of files */
                __('Reclaimable now: %1$s across %2$s file(s)', 'freshet-unused-media'),
                self::format($reclaimable['bytes']),
                number_format_i18n($reclaimable['files'])
            )),
            esc_html(sprintf(
                /* translators: 1: formatted file size, 2: number of files */
                __('Reclaimed so far: %1$s across %2$s file(s)', 'freshet-unused-media'),
                self::format($reclaimed['bytes']),
                number_format_i18n($reclaimed['files'])
            ))
        );
        echo '</p>';

        echo '<p class="description">' . esc_html__('Reclaimable is what the files on the unused list are holding — nothing has been freed yet. Reclaimed counts only files this plugin has deleted, recorded as each deletion happened; deletions made anywhere else on the site are not in it.', 'freshet-unused-media') . '</p>';

        $this->renderCaveats($reclaimable, $reclaimed);

        echo '</div>';
    }

    /**
     * Two decimals on a total, because "3 MB" and "3.24 MB" are a different
     * argument at library scale — but plain units below a kilobyte, where
     * "0.00 B" only looks like a bug.
     */
    private static function format(int $bytes): string
    {
        return (string) ($bytes >= KB_IN_BYTES ? size_format($bytes, 2) : size_format($bytes));
    }

    /**
     * The things that would otherwise make a total quietly wrong. Each line
     * appears only when it applies, so a straightforward library shows two
     * figures and no small print.
     *
     * @param array{bytes: int, files: int, unsized: int} $reclaimable
     * @param array{bytes: int, files: int, unsized: int, trashed: int, last_at: int} $reclaimed
     */
    private function renderCaveats(array $reclaimable, array $reclaimed): void
    {
        if ($reclaimable['unsized'] > 0) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: number of files */
                __('%s unused file(s) could not be sized — no local file and no recorded size, which is what an offloaded library looks like. They are left out of the reclaimable figure rather than counted as zero, so the real saving is higher than shown.', 'freshet-unused-media'),
                number_format_i18n($reclaimable['unsized'])
            )) . '</p>';
        }

        if ($reclaimed['trashed'] > 0) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: 1: number of files, 2: MEDIA_TRASH constant name */
                __('%1$s deleted file(s) went to the media trash instead of being erased, because %2$s is enabled. Nothing is freed until the trash is emptied, so they are not counted as reclaimed.', 'freshet-unused-media'),
                number_format_i18n($reclaimed['trashed']),
                'MEDIA_TRASH'
            )) . '</p>';
        }

        if ($reclaimed['unsized'] > 0) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: number of files */
                __('%s erased file(s) could not be sized before deletion and are counted in the file count but not in the bytes.', 'freshet-unused-media'),
                number_format_i18n($reclaimed['unsized'])
            )) . '</p>';
        }

        if ($reclaimed['last_at'] > 0) {
            echo '<p class="description">' . esc_html(sprintf(
                /* translators: %s: human time diff */
                __('Last deletion %s ago.', 'freshet-unused-media'),
                human_time_diff($reclaimed['last_at'])
            )) . '</p>';
        }
    }

    /**
     * Bytes held by everything currently on the unused list.
     *
     * One size per file, because ResultStore hands back one row per file. Sizing
     * per attachment row instead is what inflated this figure by the duplication
     * factor — on a library with two rows per file, twice the real saving.
     *
     * Read in pages rather than one list, the way the evidence report streams,
     * so the memory cost is the batch and not the library. The disk cost is one
     * stat per unused file — the same work the table already does for the fifty
     * rows it shows, over the whole unused set — which is why this is computed
     * when the page is opened and not cached: a stale saving figure is worth
     * less than the second it takes to be right.
     *
     * @return array{bytes: int, files: int, unsized: int}
     */
    private function reclaimable(): array
    {
        $bytes = 0;
        $files = 0;
        $unsized = 0;
        $page = 1;

        do {
            $batch = $this->store->byStatus(ResultStore::STATUS_UNUSED, $page, self::BATCH);

            foreach ($batch['ids'] as $id) {
                $size = FileSize::bytes($id);

                if ($size === null) {
                    ++$unsized;

                    continue;
                }

                $bytes += $size;
                ++$files;
            }

            ++$page;
        } while ($batch['ids'] !== []);

        return ['bytes' => $bytes, 'files' => $files, 'unsized' => $unsized];
    }
}

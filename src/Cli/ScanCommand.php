<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Cli;

use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\Scanner;
use FreshetUnusedMedia\Scan\ScanState;
use WP_CLI;
use WP_CLI\Utils;

defined('ABSPATH') || exit;

/**
 * Scan a media library and report what is unused, without opening a browser.
 *
 * wp freshet-unusedmedia scan [--resume] [--format=<format>]
 * wp freshet-unusedmedia list [--fields=<fields>] [--format=<format>] [--limit=<n>]
 *
 * The paid tier's second surface: the same scan the Media → Usage screen runs,
 * without a browser, across as many installs as you have. Same Scanner, same
 * detectors, same ResultStore, same ScanState cursor — so a site scanned here
 * reads identically in wp-admin, and the two can never disagree.
 *
 * It reports. There is no delete verb here and that is deliberate: a
 * non-interactive delete across many sites is exactly the shape this plugin
 * refuses to take. Deletion stays in the admin, behind a confirmation, with its
 * re-verification pass.
 *
 * Nothing here schedules anything either. A scan happens when someone runs one.
 */
final class ScanCommand
{
    /** Default columns, mirroring the unused table on Media → Usage. */
    private const LIST_FIELDS = ['id', 'file', 'title', 'mime', 'uploaded', 'bytes', 'scanned_at'];

    public function __construct(
        private readonly Scanner $scanner,
        private readonly ResultStore $store,
        private readonly ScanState $state,
        private readonly LicenseInterface $license,
    ) {
    }

    /**
     * Scan the whole media library and report what is used.
     *
     * Runs to completion in one invocation — the browser scan batches because a
     * request has a time limit, and this does not. Attachment IDs are still
     * fetched in chunks (freshet_unusedmedia_batch_size, 100 here) so a large
     * library never lands in memory at once, and the cursor is written after
     * every chunk, so an interrupted run resumes with --resume.
     *
     * ## OPTIONS
     *
     * [--resume]
     * : Continue an interrupted scan from its cursor instead of starting over.
     *
     * [--format=<format>]
     * : Render the summary in this format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp freshet-unusedmedia scan
     *     wp freshet-unusedmedia scan --format=json
     *     wp freshet-unusedmedia scan --resume
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function scan(array $args, array $assocArgs): void
    {
        $this->requireLicense();

        global $wpdb;

        $format = (string) ($assocArgs['format'] ?? 'table');
        $quiet = $format !== 'table';

        if (!isset($assocArgs['resume'])) {
            $this->state->reset();
        }

        $state = $this->state->current();

        if ($state === null) {
            // Same count the browser scan sizes itself with, trash included.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simple count to size the scan.
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'");
            $state = $this->state->start($total);
        }

        $progress = $quiet
            ? null
            : Utils\make_progress_bar('Scanning media', max(0, $state['total'] - $state['done']));

        $batchSize = max(1, (int) apply_filters('freshet_unusedmedia_batch_size', 100));

        while (true) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- ID-ascending cursor batch; picks up mid-scan uploads.
            $ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
                $state['cursor'],
                $batchSize
            )));

            if ($ids === []) {
                break;
            }

            $processed = 0;
            $last = $state['cursor'];

            foreach ($ids as $id) {
                $this->scanner->scan($id);
                ++$processed;
                $last = $id;
                $progress?->tick();
            }

            $state = $this->state->advance($last, $processed);
        }

        $progress?->finish();

        $counts = $this->store->counts();
        $this->state->finish($counts['unused']);

        $summary = [
            'total' => $counts['total'],
            'scanned' => $state['done'],
            'used' => $counts['used'],
            'unused' => $counts['unused'],
            'unscanned' => $counts['unscanned'],
        ];

        Utils\format_items($format, [$summary], array_keys($summary));

        if (!$quiet) {
            WP_CLI::success(sprintf(
                '%d file(s) scanned, %d unused. Nothing was deleted — review them with `wp freshet-unusedmedia list`.',
                $summary['scanned'],
                $summary['unused']
            ));
        }
    }

    /**
     * List the files the last scan found unused.
     *
     * Reads the stored results; it does not re-scan. Files in the trash are
     * excluded, exactly as they are on Media → Usage.
     *
     * ## OPTIONS
     *
     * [--fields=<fields>]
     * : Comma-separated columns. Defaults to id,file,title,mime,uploaded,bytes,scanned_at.
     *
     * [--limit=<number>]
     * : Stop after this many files. 0 lists them all.
     * ---
     * default: 0
     * ---
     *
     * [--format=<format>]
     * : Render the list in this format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - ids
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp freshet-unusedmedia list
     *     wp freshet-unusedmedia list --format=json
     *     wp freshet-unusedmedia list --format=count
     *
     * @subcommand list
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function list_(array $args, array $assocArgs): void
    {
        $this->requireLicense();

        $format = (string) ($assocArgs['format'] ?? 'table');
        $limit = max(0, (int) ($assocArgs['limit'] ?? 0));
        $fields = isset($assocArgs['fields'])
            ? array_values(array_filter(array_map('trim', explode(',', (string) $assocArgs['fields']))))
            : self::LIST_FIELDS;

        $rows = [];
        $page = 1;

        do {
            $batch = $this->store->unused($page, 200);

            foreach ($batch['ids'] as $id) {
                $rows[] = $this->row($id);

                if ($limit > 0 && count($rows) >= $limit) {
                    break 2;
                }
            }

            ++$page;
        } while ($batch['ids'] !== []);

        // Formatter::display_items() expects flat values for these two, so they
        // are answered here rather than handed to it as rows.
        if ($format === 'ids') {
            WP_CLI::log(implode(' ', array_column($rows, 'id')));

            return;
        }

        if ($format === 'count') {
            WP_CLI::log((string) count($rows));

            return;
        }

        // Empty is a result, not a message: only the human format says it in
        // words. Anything being piped gets an empty list of the shape it asked
        // for, so a site with nothing unused parses like every other site.
        if ($rows === [] && $format === 'table') {
            WP_CLI::success('No attachments are currently marked unused. Run a scan first, or enjoy the tidy library.');

            return;
        }

        Utils\format_items($format, $rows, $fields);
    }

    /** One unused file, in the columns the admin table shows. */
    private function row(int $id): array
    {
        $file = get_attached_file($id);
        $bytes = FileSize::bytes($id) ?? 0;

        $filename = $file !== false ? wp_basename($file) : sprintf('#%d', $id);
        $title = get_the_title($id);
        $scannedAt = $this->store->scannedAt($id);

        return [
            'id' => $id,
            'file' => $filename,
            'title' => $title !== '' ? $title : $filename,
            'mime' => (string) get_post_mime_type($id),
            'uploaded' => (string) (get_the_date('Y-m-d', $id) ?: ''),
            'bytes' => $bytes,
            'scanned_at' => $scannedAt > 0 ? gmdate('Y-m-d H:i:s', $scannedAt) : '',
        ];
    }

    /**
     * With no key this returns without the license ever reaching the network:
     * RemoteLicense short-circuits on an empty key, and the wordpress.org build
     * has neither this file nor a license client at all.
     */
    private function requireLicense(): void
    {
        if ($this->license->isPro()) {
            return;
        }

        WP_CLI::error(
            'This command needs a license key. Scanning, the unused list and deletion are free on Media → Usage; '
            . 'a license adds the Used view, this command, the exportable evidence report and the space totals. '
            . 'Add a key on Media → Usage.'
        );
    }
}

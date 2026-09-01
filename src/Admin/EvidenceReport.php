<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * The evidence report — the paid tier's third surface, and the one that leaves
 * the site. It exports what the last scan found, per file, with the references
 * behind each verdict, so the answer can be handed to somebody who has never
 * opened the plugin and checked before anything is deleted.
 *
 * It is a serialisation of results that already exist. Every value comes from
 * ResultStore — status(), scannedAt(), refs(), byStatus(), counts() — and the
 * byte figure is the same local-file-then-metadata computation the unused table
 * and the CLI use. There is no detector here, no second store, and deliberately
 * no re-scan: a report that re-ran detection would be a second pass over the
 * library and could disagree with the screen it claims to evidence.
 *
 * It exports. There is no delete verb on this screen and no "export and clean
 * up" affordance — deletion stays where it is, behind its confirmation and its
 * re-verification pass. Nothing here is scheduled and nothing is mailed: an
 * export happens when someone clicks the button.
 */
final class EvidenceReport
{
    public const ACTION = 'freshet_unusedmedia_export_report';

    private const CAP = 'manage_options';

    /** Attachments pulled per page while streaming; keeps a large library out of memory. */
    private const BATCH = 200;

    /**
     * Stable, English column keys rather than translated headings: the point of
     * a CSV is that two exports of the same library diff, and these line up with
     * the WP-CLI list fields so the two outputs read the same. The values are
     * localised; the keys are not.
     */
    private const COLUMNS = [
        'id',
        'file',
        'title',
        'url',
        'mime',
        'uploaded',
        'status',
        'bytes',
        'size',
        'scanned_at',
        'references',
        'evidence',
    ];

    public function __construct(
        private readonly ResultStore $store,
        private readonly LicenseInterface $license,
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'export']);
    }

    // ------------------------------------------------------------------- UI

    /**
     * The card on Media → Usage. Renders its own wrapper rather than being
     * wrapped by ToolsPage the way the license card is: without a valid key
     * there is no card at all here, and an empty bordered box is worse than
     * nothing.
     */
    public function render(): void
    {
        if (!current_user_can(self::CAP) || !$this->license->isPro()) {
            return;
        }

        $counts = $this->store->counts();

        echo '<div class="freshet-unusedmedia-card">';
        echo '<h2>' . esc_html__('Evidence report', 'freshet-unused-media') . '</h2>';

        echo '<p class="description">' . esc_html__('Export what the last scan found: every file with its status, its size, and the places it was found referenced — the same evidence the attachment screen shows, for the whole library at once. It is the file to send before anything is deleted, so the reasoning can be checked by somebody who does not have access here.', 'freshet-unused-media') . '</p>';

        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: number of unused files, 2: number of used files, 3: number of files never scanned */
            __('The report covers files that have been scanned — %1$s unused and %2$s used. %3$s file(s) have never been scanned and are not in it; run a scan first if that number should be zero.', 'freshet-unused-media'),
            number_format_i18n($counts['unused']),
            number_format_i18n($counts['used']),
            number_format_i18n($counts['unscanned'])
        )) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="freshet-unusedmedia-report">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';

        echo '<p><label for="freshet-unusedmedia-report-scope">' . esc_html__('Include:', 'freshet-unused-media') . '</label> ';
        echo '<select name="scope" id="freshet-unusedmedia-report-scope">';
        printf(
            '<option value="all">%s</option><option value="unused">%s</option>',
            esc_html__('Everything scanned — unused files first, then the files that were kept', 'freshet-unused-media'),
            esc_html__('Unused files only', 'freshet-unused-media')
        );
        echo '</select></p>';

        printf(
            '<p><button type="submit" class="button button-primary" name="format" value="csv">%s</button>
             <button type="submit" class="button" name="format" value="json">%s</button></p>',
            esc_html__('Download CSV', 'freshet-unused-media'),
            esc_html__('Download JSON', 'freshet-unused-media')
        );

        echo '<p class="description">' . esc_html__('CSV opens in a spreadsheet; JSON carries the references as structured data and adds the library totals. Nothing is deleted, changed or sent anywhere — the file downloads to this browser.', 'freshet-unused-media') . '</p>';

        echo '</form>';
        echo '</div>';
    }

    // --------------------------------------------------------------- export

    /**
     * Streams the report as a download. Rows are pulled a page at a time and
     * written as they are built, so a library of any size costs one page of
     * memory rather than the whole report.
     */
    public function export(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('You are not allowed to export the report.', 'freshet-unused-media'));
        }

        check_admin_referer(self::ACTION);

        // The license is consulted here and in render(), nowhere else. With no
        // key stored RemoteLicense short-circuits before any request is made,
        // and the wordpress.org build has neither this file nor a client.
        if (!$this->license->isPro()) {
            wp_die(esc_html__('This export needs a license key. Add one on Media → Usage.', 'freshet-unused-media'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
        $format = sanitize_key(wp_unslash($_POST['format'] ?? 'csv'));
        $scope = sanitize_key(wp_unslash($_POST['scope'] ?? 'all'));
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $format = $format === 'json' ? 'json' : 'csv';

        $statuses = $scope === 'unused'
            ? [ResultStore::STATUS_UNUSED]
            : [ResultStore::STATUS_UNUSED, ResultStore::STATUS_USED];

        nocache_headers();
        header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/csv') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $this->filename($format) . '"');

        if ($format === 'json') {
            $this->streamJson($statuses, $scope);
        } else {
            $this->streamCsv($statuses);
        }

        exit;
    }

    /** @param string[] $statuses */
    private function streamCsv(array $statuses): void
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing the response body, not a file.
        $out = fopen('php://output', 'w');

        if ($out === false) {
            return;
        }

        // Byte-order mark: without it a spreadsheet opening a UTF-8 CSV renders
        // non-ASCII filenames as mojibake, and filenames are half the report.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, self::COLUMNS, ',', '"', '');

        $this->eachRow($statuses, static function (array $row) use ($out): void {
            fputcsv($out, array_map(
                static fn(string $key): string => (string) ($row[$key] ?? ''),
                self::COLUMNS
            ), ',', '"', '');
        });

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- response body.
        fclose($out);
    }

    /**
     * Written incrementally for the same reason the CSV is: the rows never all
     * exist at once. The envelope carries what a flat table has nowhere to put
     * — when it was made, which library, and the totals it was made from.
     *
     * @param string[] $statuses
     */
    private function streamJson(array $statuses, string $scope): void
    {
        $counts = $this->store->counts();

        echo '{"generated_at":' . wp_json_encode(gmdate('c'))
            . ',"site":' . wp_json_encode(home_url())
            . ',"plugin":' . wp_json_encode('freshet-unused-media ' . FRESHET_UNUSEDMEDIA_VERSION)
            . ',"scope":' . wp_json_encode($scope === 'unused' ? 'unused' : 'all')
            . ',"summary":' . wp_json_encode($counts)
            . ',"files":[';

        $first = true;

        $this->eachRow($statuses, static function (array $row, array $refs) use (&$first): void {
            unset($row['evidence']);
            $row['references_listed'] = $refs;

            echo ($first ? '' : ',') . wp_json_encode($row);
            $first = false;
        });

        echo ']}';
    }

    /**
     * Walks the stored results one page at a time and hands each built row to
     * $write, which receives the flat row and the structured references.
     *
     * This is the only place the report reads the library, and it reads results
     * rather than files: ResultStore::byStatus() is the same paged query the
     * unused table runs, with the other status value for the used half.
     *
     * @param string[] $statuses
     * @param callable(array<string, string|int>, array<int, array<string, string|int>>): void $write
     */
    private function eachRow(array $statuses, callable $write): void
    {
        foreach ($statuses as $status) {
            $page = 1;

            do {
                $batch = $this->store->byStatus($status, $page, self::BATCH);

                foreach ($batch['ids'] as $id) {
                    [$row, $refs] = $this->row($id, $status);
                    $write($row, $refs);
                }

                ++$page;
            } while ($batch['ids'] !== []);
        }
    }

    /**
     * One file, and the references behind its verdict.
     *
     * @return array{0: array<string, string|int>, 1: array<int, array<string, string|int>>}
     */
    private function row(int $id, string $status): array
    {
        $file = get_attached_file($id);
        $bytes = FileSize::bytes($id) ?? 0;

        $filename = $file !== false ? wp_basename($file) : sprintf('#%d', $id);
        $title = get_the_title($id);
        $scannedAt = $this->store->scannedAt($id);
        $stored = $this->store->refs($id);

        $refs = [];

        foreach ($stored['refs'] as $ref) {
            $refs[] = [
                'detector' => $ref->detector,
                'type' => AttachmentMetaBox::matchLabel($ref->match),
                'match' => $ref->match,
                'confidence' => $ref->confidence,
                'object_type' => $ref->objectType,
                'object_id' => $ref->objectId,
                'source' => $this->sourceLabel($ref),
                'detail' => $ref->detail,
            ];
        }

        $row = [
            'id' => $id,
            'file' => $filename,
            'title' => $title !== '' ? $title : $filename,
            'url' => (string) wp_get_attachment_url($id),
            'mime' => (string) get_post_mime_type($id),
            'uploaded' => (string) (get_the_date('Y-m-d', $id) ?: ''),
            'status' => $status,
            'bytes' => $bytes,
            'size' => $bytes > 0 ? size_format($bytes) : '',
            'scanned_at' => $scannedAt > 0 ? gmdate('Y-m-d H:i:s', $scannedAt) : '',
            // The true total, which may exceed what was stored.
            'references' => $stored['count'],
            'evidence' => $this->evidenceText($stored['count'], $refs),
        ];

        return [$row, $refs];
    }

    /**
     * The references as one readable sentence per cell — the CSV's whole reason
     * for existing. A reader who has never seen the plugin should be able to
     * read a row and say why the file is on the list.
     *
     * @param array<int, array<string, string|int>> $refs
     */
    private function evidenceText(int $total, array $refs): string
    {
        if ($refs === []) {
            return (string) __('No references found anywhere.', 'freshet-unused-media');
        }

        $lines = [];

        foreach ($refs as $ref) {
            $confidence = match ((string) $ref['confidence']) {
                Reference::CONFIRMED => (string) __('confirmed', 'freshet-unused-media'),
                Reference::INFO => (string) __('record only — not a content reference', 'freshet-unused-media'),
                default => (string) __('possible', 'freshet-unused-media'),
            };

            // Same rule as the meta box: the field or meta key is worth naming
            // on an object that has other keys, and is noise on an option,
            // whose source label already *is* the key.
            $detail = in_array($ref['object_type'], ['post', 'term', 'user', 'comment'], true)
                && $ref['detail'] !== ''
                && $ref['detail'] !== $ref['match']
                ? sprintf(' (%s)', $ref['detail'])
                : '';

            $lines[] = sprintf('%s — %s%s [%s]', $ref['type'], $ref['source'], $detail, $confidence);
        }

        // MAX_STORED_REFS caps what a scan persists. Say so rather than let the
        // cell imply the list is complete — the meta box says the same thing.
        if ($total > count($refs)) {
            $lines[] = sprintf(
                /* translators: %d: number of references beyond those stored */
                (string) __('…and %d more (not stored)', 'freshet-unused-media'),
                $total - count($refs)
            );
        }

        return implode(' | ', $lines);
    }

    /**
     * Where one reference lives, in plain text. The peer of
     * AttachmentMetaBox::whereHtml() — same objects, same resolution, no links,
     * because a spreadsheet cell cannot hold one. Labels are resolved here from
     * objectType + objectId exactly as they are on screen; nothing is read from
     * storage that was not stored.
     */
    private function sourceLabel(Reference $ref): string
    {
        switch ($ref->objectType) {
            case 'post':
                $title = get_the_title($ref->objectId);

                return sprintf(
                    /* translators: 1: post type label, 2: post ID, 3: post title */
                    (string) __('%1$s #%2$d "%3$s"', 'freshet-unused-media'),
                    $this->postTypeLabel($ref->objectId),
                    $ref->objectId,
                    $title !== '' ? $title : (string) __('(no title)', 'freshet-unused-media')
                );

            case 'term':
                $term = get_term($ref->objectId);

                return sprintf(
                    /* translators: 1: term ID, 2: term name */
                    (string) __('term #%1$d "%2$s"', 'freshet-unused-media'),
                    $ref->objectId,
                    $term instanceof \WP_Term ? $term->name : ''
                );

            case 'user':
                $user = get_userdata($ref->objectId);

                return sprintf(
                    /* translators: 1: user ID, 2: display name */
                    (string) __('user #%1$d "%2$s"', 'freshet-unused-media'),
                    $ref->objectId,
                    $user !== false ? $user->display_name : ''
                );

            case 'comment':
                $comment = get_comment($ref->objectId);
                $author = $comment instanceof \WP_Comment ? $comment->comment_author : '';

                return $author !== ''
                    ? sprintf(
                        /* translators: 1: comment ID, 2: comment author name */
                        (string) __('comment #%1$d by %2$s', 'freshet-unused-media'),
                        $ref->objectId,
                        $author
                    )
                    : sprintf(
                        /* translators: %d: comment ID */
                        (string) __('comment #%d', 'freshet-unused-media'),
                        $ref->objectId
                    );

            case 'theme_mod':
                return (string) __('Customizer', 'freshet-unused-media');

            default:
                return $ref->detail;
        }
    }

    private function postTypeLabel(int $postId): string
    {
        $type = get_post_type($postId);

        if ($type === false) {
            return (string) __('post', 'freshet-unused-media');
        }

        $object = get_post_type_object($type);

        return $object !== null ? $object->labels->singular_name : $type;
    }

    /** Named for the site and the day, so two exports never overwrite each other. */
    private function filename(string $format): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return sanitize_file_name(sprintf(
            'media-usage-report-%s-%s.%s',
            $host !== '' ? $host : 'site',
            gmdate('Y-m-d'),
            $format
        ));
    }
}

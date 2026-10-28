<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\OrphanStore;
use FreshetUnusedMedia\Scan\QueryFailed;

defined('ABSPATH') || exit;

/**
 * The CSV of every size file the last scan found with no original.
 *
 * The screen prints figures — how many, how large, how many post content still
 * names — and this is the list behind them, all of it: one row per file, read a
 * page at a time from OrphanStore and written as it is read, so a library of any
 * size costs one page of memory. The row count equals the figure on the screen
 * because both are read off the same table.
 *
 * The conventions are the plugin's CSV conventions: stable English column keys
 * (two exports of the same library should diff), a UTF-8 byte-order mark,
 * `fputcsv()` with an empty escape character, a download named for the site and
 * the day, `manage_options` and a nonce, and a last line that says so if a read
 * failed halfway.
 *
 * It exports. Nothing here deletes anything.
 */
final class OrphanExport
{
    public const ACTION = 'freshet_unusedmedia_export_orphans';

    private const CAP = 'manage_options';

    /**
     * Stable column keys. `referenced` is `yes` or `no`, and empty for a row the
     * content check has not reached — which only a scan still running leaves.
     */
    public const COLUMNS = ['path', 'bytes', 'referenced', 'post_ids'];

    public function hooks(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'export']);
    }

    /** The button, for the screen's summary to print. */
    public static function renderButton(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="freshet-unusedmedia-orphan-export">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<p><button type="submit" class="button">' . esc_html__('Export CSV', 'freshet-unused-media') . '</button></p>';
        echo '</form>';
    }

    public function export(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(
                esc_html__('Only an administrator can export this list. Ask someone who manages the site to download it.', 'freshet-unused-media'),
                esc_html__('Export not allowed', 'freshet-unused-media'),
                ['response' => 403, 'back_link' => true]
            );
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the nonce check.

        if (!wp_verify_nonce($nonce, self::ACTION)) {
            wp_die(
                esc_html__('This export link has expired, so nothing was downloaded. Go back, reload the page and press Export CSV again.', 'freshet-unused-media'),
                esc_html__('Export link expired', 'freshet-unused-media'),
                ['response' => 403, 'back_link' => true]
            );
        }

        // Asked before the headers go out, because after them there is no way
        // left to answer with an error page.
        try {
            $ready = OrphanStore::exists();
        } catch (QueryFailed) {
            wp_die(esc_html(QueryFailed::userMessage()), '', ['response' => 500, 'back_link' => true]);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . self::filename() . '"');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing the response body, not a file.
        $out = fopen('php://output', 'w');

        if ($out !== false) {
            self::write($out, $ready);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- response body.
            fclose($out);
        }

        exit;
    }

    /**
     * The whole CSV, onto $out. Public so a test can read it without a request.
     *
     * @param resource $out
     * @param bool     $ready Whether the table exists; a site no scan has made
     *                        it on exports the header row and nothing else.
     */
    public static function write($out, bool $ready = true): void
    {
        // Byte-order mark: without it a spreadsheet opening a UTF-8 CSV renders
        // non-ASCII filenames as mojibake.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body.
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, self::COLUMNS, ',', '"', '');

        if (!$ready) {
            return;
        }

        // A read that fails halfway would end the file exactly where a complete
        // one ends. It cannot become an error page now, so the last line says it
        // stopped early instead (freshet-152).
        try {
            OrphanStore::each(static function (array $row) use ($out): void {
                fputcsv($out, [
                    $row['path'],
                    (string) $row['bytes'],
                    $row['referenced'] === null ? '' : ($row['referenced'] ? 'yes' : 'no'),
                    implode(' ', $row['post_ids']),
                ], ',', '"', '');
            });
        } catch (QueryFailed) {
            fputcsv($out, [
                QueryFailed::userMessage() . ' ' . __('This export stopped early and is incomplete — do not read it as the whole list.', 'freshet-unused-media'),
                '',
                '',
                '',
            ], ',', '"', '');
        }
    }

    /** Named for the site and the day, so two exports never overwrite each other. */
    private static function filename(): string
    {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);

        return sanitize_file_name(sprintf(
            'sizes-with-no-original-%s-%s.csv',
            $host !== '' ? $host : 'site',
            gmdate('Y-m-d')
        ));
    }
}

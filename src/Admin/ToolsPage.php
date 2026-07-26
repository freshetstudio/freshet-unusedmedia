<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\ScanState;

defined('ABSPATH') || exit;

/**
 * The Media → Usage screen: scan controls with progress, usage counts and
 * the unused-files table with delete actions.
 */
final class ToolsPage
{
    public const SLUG = 'freshet-unusedmedia';
    private const CAP = 'manage_options';
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ResultStore $store,
        private readonly ScanState $state,
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    public function registerMenu(): void
    {
        add_media_page(
            __('Media Usage', 'freshet-unusedmedia'),
            __('Usage', 'freshet-unusedmedia'),
            self::CAP,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueue(string $hookSuffix): void
    {
        if ($hookSuffix === 'media_page_' . self::SLUG) {
            Assets::enqueue();
        }
    }

    public function renderNotice(): void
    {
        if (!current_user_can(self::CAP) || !isset($_GET['freshet_unusedmedia_deleted'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only notice from redirect.
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notice from redirect.
        $deleted = absint($_GET['freshet_unusedmedia_deleted'] ?? 0);
        $skipped = absint($_GET['freshet_unusedmedia_skipped'] ?? 0);
        $failed = absint($_GET['freshet_unusedmedia_failed'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            $failed > 0 ? 'notice-warning' : 'notice-success',
            esc_html(sprintf(
                /* translators: 1: deleted count, 2: skipped count, 3: failed count */
                __('Deleted %1$d, skipped %2$d (found in use on re-check), failed %3$d.', 'freshet-unusedmedia'),
                $deleted,
                $skipped,
                $failed
            ))
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $this->renderHeader();

        echo '<div class="wrap freshet-unusedmedia-wrap">';

        $this->renderScanCard();
        $this->renderUnusedTable();

        echo '</div>';
    }

    /**
     * Slim brand strip, matching the Freshet Feeds header. Scoped to this
     * page only — the rest of wp-admin is never touched.
     */
    private function renderHeader(): void
    {
        ?>
        <div class="frst-header">
            <div class="frst-header__row">
                <svg class="frst-header__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <rect width="32" height="32" rx="7" fill="#1122ff"/>
                    <rect x="7.5" y="9.5" width="17" height="13" rx="2.5" stroke="#fff" stroke-width="2.5"/>
                    <circle cx="12.25" cy="13.75" r="1.75" fill="#fff"/>
                    <path d="M10.5 19.75l4-3.75 3.25 3 2.25-2 3.5 3.25" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h1 class="frst-header__title"><?php esc_html_e('Freshet Unused Media', 'freshet-unusedmedia'); ?></h1>
                <span class="frst-header__version"><?php echo esc_html('v' . FRESHET_UNUSEDMEDIA_VERSION); ?></span>
                <div class="frst-header__meta">
                    <a href="https://freshet.studio/docs" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Docs', 'freshet-unusedmedia'); ?></a>
                    <a href="mailto:email@freshet.studio"><?php esc_html_e('Support', 'freshet-unusedmedia'); ?></a>
                </div>
            </div>
        </div>
        <hr class="wp-header-end">
        <?php
    }

    // ------------------------------------------------------------------ scan

    private function renderScanCard(): void
    {
        $counts = $this->store->counts();
        $running = $this->state->current();
        $last = $this->state->lastScan();

        echo '<div class="freshet-unusedmedia-card">';
        echo '<h2>' . esc_html__('Scan', 'freshet-unusedmedia') . '</h2>';

        echo '<p class="freshet-unusedmedia-counts">';
        printf(
            '%s &nbsp;•&nbsp; %s &nbsp;•&nbsp; %s',
            esc_html(sprintf(
                /* translators: %s: number of used attachments */
                __('Used: %s', 'freshet-unusedmedia'),
                number_format_i18n($counts['used'])
            )),
            esc_html(sprintf(
                /* translators: %s: number of unused attachments */
                __('Unused: %s', 'freshet-unusedmedia'),
                number_format_i18n($counts['unused'])
            )),
            esc_html(sprintf(
                /* translators: %s: number of unscanned attachments */
                __('Not scanned: %s', 'freshet-unusedmedia'),
                number_format_i18n($counts['unscanned'])
            ))
        );
        echo '</p>';

        if ($last !== null) {
            $note = sprintf(
                /* translators: 1: human time diff, 2: number of attachments scanned */
                __('Last full scan finished %1$s ago (%2$s files).', 'freshet-unusedmedia'),
                human_time_diff($last['finished_at']),
                number_format_i18n($last['scanned'])
            );

            $changedAt = (int) get_option('freshet_unusedmedia_content_changed_at', 0);

            if ($changedAt > $last['finished_at']) {
                $note .= ' ' . __('Content has changed since — results may be stale.', 'freshet-unusedmedia');
            }

            echo '<p class="description">' . esc_html($note) . '</p>';
        }

        printf(
            '<p class="freshet-unusedmedia-scan-controls">
                <button type="button" class="button button-primary" id="freshet-unusedmedia-scan-start" data-resume="%s">%s</button>
                <button type="button" class="button" id="freshet-unusedmedia-scan-stop" hidden>%s</button>
                %s
            </p>',
            esc_attr($running !== null ? '1' : ''),
            esc_html($running !== null
                ? sprintf(
                    /* translators: 1: scanned count, 2: total count */
                    __('Resume scan (%1$s / %2$s)', 'freshet-unusedmedia'),
                    number_format_i18n($running['done']),
                    number_format_i18n($running['total'])
                )
                : __('Start full scan', 'freshet-unusedmedia')),
            esc_html__('Stop', 'freshet-unusedmedia'),
            $running !== null
                ? '<button type="button" class="button-link-delete" id="freshet-unusedmedia-scan-reset">' . esc_html__('Reset scan', 'freshet-unusedmedia') . '</button>'
                : ''
        );

        echo '<div class="freshet-unusedmedia-progress" id="freshet-unusedmedia-progress" hidden>
                <div class="freshet-unusedmedia-progress__bar"><span></span></div>
                <span class="freshet-unusedmedia-progress__label"></span>
              </div>';

        echo '</div>';
    }

    // ---------------------------------------------------------------- unused

    private function renderUnusedTable(): void
    {
        $page = max(1, absint($_GET['unused_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $list = $this->store->unused($page, self::PER_PAGE);

        echo '<div class="freshet-unusedmedia-card">';
        echo '<h2>' . esc_html(sprintf(
            /* translators: %s: number of unused attachments */
            __('Unused files (%s)', 'freshet-unusedmedia'),
            number_format_i18n($list['total'])
        )) . '</h2>';

        if (!(defined('MEDIA_TRASH') && MEDIA_TRASH)) {
            echo '<p class="freshet-unusedmedia-warning">' . esc_html(sprintf(
                /* translators: 1: MEDIA_TRASH constant name, 2: the PHP line to add to wp-config.php */
                __('%1$s is not enabled — deletions are permanent. Add %2$s to wp-config.php to get a trash safety net.', 'freshet-unusedmedia'),
                'MEDIA_TRASH',
                "define( 'MEDIA_TRASH', true );"
            )) . '</p>';
        }

        if ($list['ids'] === []) {
            echo '<p>' . esc_html__('No attachments are currently marked unused. Run a scan first, or enjoy the tidy library.', 'freshet-unusedmedia') . '</p></div>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="freshet-unusedmedia-delete-form">';
        wp_nonce_field('freshet_unusedmedia_delete_selected');
        echo '<input type="hidden" name="action" value="freshet_unusedmedia_delete_selected">';

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" id="freshet-unusedmedia-select-all"></td>';

        foreach ([__('File', 'freshet-unusedmedia'), __('Type', 'freshet-unusedmedia'), __('Uploaded', 'freshet-unusedmedia'), __('Size', 'freshet-unusedmedia'), __('Scanned', 'freshet-unusedmedia')] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            $this->renderUnusedRow($id);
        }

        echo '</tbody></table>';

        $this->renderPagination($page, $list['total']);

        printf(
            '<p class="freshet-unusedmedia-actions">
                <button type="submit" class="button" onclick="return confirm(%s);">%s</button>
                <button type="button" class="button button-link-delete" id="freshet-unusedmedia-delete-all" data-count="%d" data-confirm="%s">%s</button>
            </p>',
            esc_attr(wp_json_encode(__('Delete the selected attachments? Each one is re-checked first; anything still in use is skipped.', 'freshet-unusedmedia'))),
            esc_html__('Delete selected', 'freshet-unusedmedia'),
            (int) $list['total'],
            esc_attr(sprintf(
                /* translators: %s: number of unused attachments */
                __('Delete all %s unused attachments? Each one is re-checked first; anything still in use is skipped.', 'freshet-unusedmedia'),
                number_format_i18n($list['total'])
            )),
            esc_html(sprintf(
                /* translators: %s: number of unused attachments */
                __('Delete all unused (%s)', 'freshet-unusedmedia'),
                number_format_i18n($list['total'])
            ))
        );

        echo '</form>';
        echo '</div>';
    }

    private function renderUnusedRow(int $id): void
    {
        $file = get_attached_file($id);

        // Local file first; fall back to the filesize recorded in attachment
        // metadata (WP 6.0+) — covers offloaded media with local copies removed.
        $bytes = $file !== false && file_exists($file)
            ? (int) filesize($file)
            : (int) (wp_get_attachment_metadata($id)['filesize'] ?? 0);

        $size = $bytes > 0 ? size_format($bytes) : '—';
        $scannedAt = $this->store->scannedAt($id);
        $editLink = get_edit_post_link($id);
        $title = get_the_title($id);
        $filename = $file !== false ? wp_basename($file) : sprintf('#%d', $id);

        echo '<tr>';
        printf('<th scope="row" class="check-column"><input type="checkbox" name="attachments[]" value="%d"></th>', (int) $id);

        echo '<td class="freshet-unusedmedia-file">';
        echo wp_get_attachment_image($id, [40, 40], true);
        printf(
            ' <a href="%s"><strong>%s</strong></a><br><span class="description">%s</span>',
            esc_url((string) $editLink),
            esc_html($title !== '' ? $title : $filename),
            esc_html($filename)
        );
        echo '</td>';

        echo '<td>' . esc_html((string) get_post_mime_type($id)) . '</td>';
        echo '<td>' . esc_html(get_the_date('', $id) ?: '—') . '</td>';
        echo '<td>' . esc_html((string) $size) . '</td>';
        echo '<td>' . esc_html($scannedAt > 0
            ? sprintf(
                /* translators: %s: human time diff */
                __('%s ago', 'freshet-unusedmedia'),
                human_time_diff($scannedAt)
            )
            : '—') . '</td>';
        echo '</tr>';
    }

    private function renderPagination(int $page, int $total): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);

        if ($pages < 2) {
            return;
        }

        $links = paginate_links([
            'base' => add_query_arg('unused_page', '%#%', menu_page_url(self::SLUG, false)),
            'format' => '',
            'current' => $page,
            'total' => $pages,
        ]);

        if (is_string($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
        }
    }
}

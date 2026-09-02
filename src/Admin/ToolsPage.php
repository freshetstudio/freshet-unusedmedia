<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\ScanState;
use FreshetUnusedMedia\Scan\UploadGrace;

defined('ABSPATH') || exit;

/**
 * The Media → Usage screen: the scan and what it found, the unused files with
 * their delete actions, the license, and — on a licensed site only — the used
 * files.
 */
final class ToolsPage
{
    public const SLUG = 'freshet-unusedmedia';

    public const TAB_SCAN = 'scan';
    public const TAB_USED = 'used';
    public const TAB_UNUSED = 'unused';
    public const TAB_LICENSE = 'license';

    private const CAP = 'manage_options';
    private const PER_PAGE = 50;

    /** Read once per request; both listings and every URL on the page share it. */
    private ?ResultFilters $filters = null;

    /**
     * $licenseSection, $report and $totals are null in the wordpress.org build,
     * where none of those classes exists at all — the type hints resolve
     * lazily, so passing null never reaches for a stripped file.
     *
     * $license is not one of them: LicenseInterface and NoLicense ship in every
     * build, and Plugin falls back to NoLicense when the paid files are absent.
     * That is what the header pill and the Used tab both read, so the free
     * build can say "Free" and omit the paid listing without a single paid
     * class existing.
     */
    public function __construct(
        private readonly ResultStore $store,
        private readonly ScanState $state,
        private readonly LicenseInterface $license,
        private readonly ?LicenseSection $licenseSection = null,
        private readonly ?EvidenceReport $report = null,
        private readonly ?SpaceTotals $totals = null,
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
            __('Media Usage', 'freshet-unused-media'),
            __('Usage', 'freshet-unused-media'),
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
                __('Deleted %1$d, skipped %2$d (found in use on re-check), failed %3$d.', 'freshet-unused-media'),
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

        $tab = $this->currentTab();

        // WordPress relocates every `.notice` to just after `.wp-header-end`.
        // The anchor sits ABOVE the brand strip and inside `.wrap`, so foreign
        // notices land above our chrome instead of between the tabs and the
        // panel they switch. `.wrap` must also carry an h1 for the admin's own
        // heading logic; ours is visual, so this one is for screen readers.
        echo '<div class="wrap freshet-unusedmedia-wrap">';
        printf(
            '<h1 class="screen-reader-text">%s</h1><hr class="wp-header-end">',
            esc_html__('Freshet Unused Media', 'freshet-unused-media')
        );

        $this->renderHeader($tab);

        switch ($tab) {
            case self::TAB_USED:
                $this->renderUsedTable();

                break;

            case self::TAB_UNUSED:
                $this->renderUnusedTable();

                // Licensed, so it renders its own section or nothing at all —
                // an empty heading where a feature is not entitled reads as a
                // broken screen. It follows the table because it totals it.
                $this->totals?->render();

                break;

            case self::TAB_LICENSE:
                $this->renderLicenseSection();

                break;

            default:
                $this->renderScanSection();

                // Same shape and the same reason as the totals above. It sits
                // with the scan because it exports what the last scan found,
                // used and unused alike, not just the delete list.
                $this->report?->render();
        }

        echo '</div>';
    }

    /**
     * The tabs, in order: Unused first, because finding what is unused is why
     * anyone opens this screen. Scan follows it and stays the default landing
     * tab — the second tab, not the first, which is deliberate: Unused is empty
     * on a site that has never scanned.
     *
     * Two are conditional, on two different questions, and keeping them apart
     * is the point:
     *
     * - Used is the paid listing, so it appears where the site is *entitled* to
     *   it: LicenseInterface::isPro(), the one read UsedView, SpaceTotals and
     *   EvidenceReport already make. Remove the key and the tab is gone on the
     *   next load, because nothing here asks a second question.
     * - License appears where there is a license stack to show *at all* — the
     *   wordpress.org build has none, and an empty tab is worse than no tab.
     *
     * Scan and Unused are free surfaces and always present. Nothing is ever
     * rendered locked or as a teaser (directory guidelines 5 & 8): an
     * unentitled site simply has one tab fewer.
     *
     * @return array<string, string>
     */
    private function tabs(): array
    {
        $tabs = [
            self::TAB_UNUSED => __('Unused', 'freshet-unused-media'),
            self::TAB_SCAN => __('Scan', 'freshet-unused-media'),
        ];

        if ($this->license->isPro()) {
            $tabs[self::TAB_USED] = __('Used', 'freshet-unused-media');
        }

        if ($this->licenseSection !== null) {
            $tabs[self::TAB_LICENSE] = __('License', 'freshet-unused-media');
        }

        return $tabs;
    }

    /** One query arg, an allow-list, and the scan as the default. */
    private function currentTab(): string
    {
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only navigation.

        return array_key_exists($tab, $this->tabs()) ? $tab : self::TAB_SCAN;
    }

    private function filters(): ResultFilters
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only listing filter; every value is validated in fromRequest().
        return $this->filters ??= ResultFilters::fromRequest(wp_unslash($_GET));
    }

    /**
     * The screen's own URL for one tab; the default tab carries no arg.
     *
     * The two listings carry the filter with them, so narrowing the unused list
     * and then crossing to the used one to check the same window is one click
     * rather than a re-typed filter. Scan and License take no filter — there is
     * nothing there for it to narrow.
     */
    private function tabUrl(string $tab, bool $filtered = true): string
    {
        $args = array_filter([
            'page' => self::SLUG,
            'tab' => $tab !== self::TAB_SCAN ? $tab : null,
        ]);

        if ($filtered && ($tab === self::TAB_USED || $tab === self::TAB_UNUSED)) {
            $args += $this->filters()->queryArgs();
        }

        return add_query_arg($args, admin_url('upload.php'));
    }

    // --------------------------------------------------------------- filters

    /**
     * The filter controls, above the table on both listings.
     *
     * A GET form straight back at this screen: the URL is the filter state, so
     * it survives pagination, it can be bookmarked, and it is visible — which
     * matters more here than on an ordinary list table, because the number in
     * the delete button is derived from it.
     */
    private function renderFilterBar(string $tab): void
    {
        $filters = $this->filters();

        ?>
        <form method="get" class="freshet-unusedmedia-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">

            <label>
                <span><?php esc_html_e('Uploaded from', 'freshet-unused-media'); ?></span>
                <input type="date" name="<?php echo esc_attr(ResultFilters::ARG_FROM); ?>" value="<?php echo esc_attr($filters->from); ?>">
            </label>

            <label>
                <span><?php esc_html_e('Uploaded to', 'freshet-unused-media'); ?></span>
                <input type="date" name="<?php echo esc_attr(ResultFilters::ARG_TO); ?>" value="<?php echo esc_attr($filters->to); ?>">
            </label>

            <label>
                <span><?php esc_html_e('Filename contains', 'freshet-unused-media'); ?></span>
                <input type="search" name="<?php echo esc_attr(ResultFilters::ARG_FILE); ?>" value="<?php echo esc_attr($filters->filename); ?>" placeholder="<?php esc_attr_e('e.g. hero', 'freshet-unused-media'); ?>">
            </label>

            <label>
                <span><?php esc_html_e('Min size (MB)', 'freshet-unused-media'); ?></span>
                <input type="number" min="0" step="any" name="<?php echo esc_attr(ResultFilters::ARG_MIN); ?>" value="<?php echo esc_attr($filters->queryArgs()[ResultFilters::ARG_MIN] ?? ''); ?>">
            </label>

            <label>
                <span><?php esc_html_e('Max size (MB)', 'freshet-unused-media'); ?></span>
                <input type="number" min="0" step="any" name="<?php echo esc_attr(ResultFilters::ARG_MAX); ?>" value="<?php echo esc_attr($filters->queryArgs()[ResultFilters::ARG_MAX] ?? ''); ?>">
            </label>

            <span class="freshet-unusedmedia-filters__actions">
                <button type="submit" class="button"><?php esc_html_e('Filter', 'freshet-unused-media'); ?></button>
                <?php if ($filters->isActive()) : ?>
                    <a href="<?php echo esc_url($this->tabUrl($tab, false)); ?>"><?php esc_html_e('Clear filters', 'freshet-unused-media'); ?></a>
                <?php endif; ?>
            </span>
        </form>
        <?php
    }

    /**
     * The heading count. Filtered, it says both numbers — "37 of 400" is the
     * sentence that stops a subset being read as the whole library.
     *
     * Returns escaped markup rather than plain text, because the library-wide
     * figure is the one a running delete loop moves in place: it is wrapped in
     * countFigure() and the callers echo the result instead of escaping it.
     * The filtered subset is left as text — no reply carries that number, so a
     * filtered delete moves the "of 400" and leaves the "37" naming the set the
     * button named and the loop is still walking.
     */
    private function listHeading(string $singular, int $shown, string $status): string
    {
        if (!$this->filters()->isActive()) {
            return sprintf(esc_html($singular), $this->countFigure($status, $shown));
        }

        return sprintf(
            esc_html($singular),
            sprintf(
                /* translators: 1: number of matching files, 2: number of files in total */
                esc_html__('%1$s of %2$s', 'freshet-unused-media'),
                esc_html(number_format_i18n($shown)),
                $this->countFigure($status, $this->store->counts()[$status])
            )
        );
    }

    /**
     * A count the script can move without a page reload. Every delete batch
     * replies with the unused figure as it stands after it (Ajax::deleteBatch),
     * and admin.js writes it into whichever of these carry that status —
     * nothing is stored on either side, the number is read fresh per reply.
     */
    private function countFigure(string $status, int $count): string
    {
        return sprintf(
            '<span data-freshet-unusedmedia-count="%s">%s</span>',
            esc_attr($status),
            esc_html(number_format_i18n($count))
        );
    }

    /**
     * The upload grace in words, on every screen someone hunting a fresh
     * upload could be looking at — the scan they just ran and the list it is
     * absent from. One sentence, said the same way in both places.
     *
     * The window comes from UploadGrace, which is the value the detector
     * enforces: a hardcoded "24 hours" beside a filtered grace would be the
     * one line on the screen that lies. Empty when the grace is filtered off,
     * because then nothing is being held back and there is nothing to explain.
     */
    private function graceNotice(): string
    {
        return UploadGrace::isActive()
            ? sprintf(
                /* translators: %s: the upload grace window, e.g. "24 hours" */
                __('A file uploaded in the last %s is held back whatever the scan finds, so an editor still placing it has time to finish — it is not missing, it is being kept out of the list on purpose.', 'freshet-unused-media'),
                UploadGrace::window()
            )
            : '';
    }

    /** Said once, wherever a size filter changes which files can appear. */
    private function sizeFilterNote(): string
    {
        return $this->filters()->hasSizeFilter()
            ? ' ' . __('While a size filter is applied, files whose size cannot be read are left out of the list.', 'freshet-unused-media')
            : '';
    }

    /**
     * Slim brand strip and native nav-tabs, matching the Freshet Feeds header.
     * Scoped to this page only — the rest of wp-admin is never touched. The
     * tabs use core .nav-tab classes so the user's admin color scheme applies.
     */
    private function renderHeader(string $activeTab): void
    {
        $tabs = $this->tabs();

        ?>
        <div class="frst-header">
            <div class="frst-header__row">
                <svg class="frst-header__mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <rect width="32" height="32" rx="7" fill="#1122ff"/>
                    <rect x="7.5" y="9.5" width="17" height="13" rx="2.5" stroke="#fff" stroke-width="2.5"/>
                    <circle cx="12.25" cy="13.75" r="1.75" fill="#fff"/>
                    <path d="M10.5 19.75l4-3.75 3.25 3 2.25-2 3.5 3.25" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <h1 class="frst-header__title"><?php esc_html_e('Freshet Unused Media', 'freshet-unused-media'); ?></h1>
                <span class="frst-header__version"><?php echo esc_html('v' . FRESHET_UNUSEDMEDIA_VERSION); ?></span>
                <div class="frst-header__meta">
                    <?php // The tier, from the license itself rather than from which files are on disk: NoLicense answers this in the free build, where nothing paid exists to ask. No link hangs off it — the directory build carries no upsell. ?>
                    <?php if ($this->license->isPro()) : ?>
                        <span class="frst-header__pill frst-header__pill--pro"><?php esc_html_e('Pro', 'freshet-unused-media'); ?></span>
                    <?php else : ?>
                        <span class="frst-header__pill frst-header__pill--free"><?php esc_html_e('Free', 'freshet-unused-media'); ?></span>
                    <?php endif; ?>
                    <a href="https://freshet.studio/docs" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Docs', 'freshet-unused-media'); ?></a>
                    <a href="mailto:email@freshet.studio"><?php esc_html_e('Support', 'freshet-unused-media'); ?></a>
                </div>
            </div>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a class="nav-tab<?php echo $slug === $activeTab ? ' nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url($this->tabUrl($slug)); ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ scan

    private function renderScanSection(): void
    {
        $counts = $this->store->counts();
        $running = $this->state->current();
        $last = $this->state->lastScan();

        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . esc_html__('Scan', 'freshet-unused-media') . '</h2>';

        echo '<p class="description">' . esc_html__('Every file is checked against post content and blocks, custom fields, options and theme mods, term and user meta, comments and excerpts — not just what it was uploaded to. Anything ambiguous counts as used, so a file reaches the unused list only when nothing anywhere refers to it.', 'freshet-unused-media') . '</p>';

        // freshet-D92 (4): a fresh upload is held out of the deletable pool on
        // purpose, and until now nothing said so anywhere a person looking for
        // it would be. This is the tab they land on.
        $grace = $this->graceNotice();

        if ($grace !== '') {
            echo '<p class="description">' . esc_html($grace) . '</p>';
        }

        echo '<p class="freshet-unusedmedia-counts">';
        printf(
            '%s &nbsp;•&nbsp; %s &nbsp;•&nbsp; %s',
            esc_html(sprintf(
                /* translators: %s: number of used attachments */
                __('Used: %s', 'freshet-unused-media'),
                number_format_i18n($counts['used'])
            )),
            // The one figure a running loop rewrites, so the number is wrapped
            // rather than escaped whole. The label is still escaped; only the
            // span around the count is markup.
            sprintf(
                /* translators: %s: number of unused attachments */
                esc_html__('Unused: %s', 'freshet-unused-media'),
                $this->countFigure(ResultStore::STATUS_UNUSED, $counts['unused']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in countFigure().
            ),
            esc_html(sprintf(
                /* translators: %s: number of unscanned attachments */
                __('Not scanned: %s', 'freshet-unused-media'),
                number_format_i18n($counts['unscanned'])
            ))
        );
        echo '</p>';

        if ($last !== null) {
            $note = sprintf(
                // Attachments, not files: the scan walks rows, and several rows
                // can share one file. Everything else on this screen counts
                // files, so this one has to say which unit it is in.
                /* translators: 1: human time diff, 2: number of attachments scanned */
                __('Last full scan finished %1$s ago (%2$s attachments).', 'freshet-unused-media'),
                human_time_diff($last['finished_at']),
                number_format_i18n($last['scanned'])
            );

            $changedAt = (int) get_option('freshet_unusedmedia_content_changed_at', 0);

            if ($changedAt > $last['finished_at']) {
                $note .= ' ' . __('Content has changed since — results may be stale.', 'freshet-unused-media');
            }

            echo '<p class="description">' . esc_html($note) . '</p>';
        }

        // freshet-D92 (3): a delete orphaned the webp an optimiser had made of
        // the deleted original, and the next scan flagged it — the product
        // working, read as a stale result because nothing had said a cleanup
        // can do that. Said here, once, in the same voice as the rest.
        echo '<p class="description">' . esc_html__('Worth running again after a cleanup. Deleting a file can leave others behind it unreferenced — an optimiser or a resize tool registers its derivatives as library entries of their own, and once the original is gone nothing points at those any more — so a later scan can honestly find files an earlier one did not. That is what the scan being re-runnable is for.', 'freshet-unused-media') . '</p>';

        // Reset only exists while a scan is unfinished: it is the escape hatch out
        // of a half-done run, next to Resume and Stop. It carries button-link so it
        // reads as a link rather than a third button — it throws a scan's progress
        // away and must never look like the obvious next click.
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
                    __('Resume scan (%1$s / %2$s)', 'freshet-unused-media'),
                    number_format_i18n($running['done']),
                    number_format_i18n($running['total'])
                )
                : __('Start full scan', 'freshet-unused-media')),
            esc_html__('Stop', 'freshet-unused-media'),
            $running !== null
                ? '<button type="button" class="button-link button-link-delete" id="freshet-unusedmedia-scan-reset">' . esc_html__('Reset scan', 'freshet-unused-media') . '</button>'
                : ''
        );

        $this->renderProgress();

        echo '</div>';
    }

    /**
     * The progress bar the scan and the delete-all loop both drive. Rendered on
     * whichever of the two tabs owns the button that starts a loop, and never on
     * both at once — the script looks the element up by id and does nothing when
     * it is absent, so a tab without a long-running action simply has no bar.
     */
    private function renderProgress(): void
    {
        echo '<div class="freshet-unusedmedia-progress" id="freshet-unusedmedia-progress" hidden>
                <div class="freshet-unusedmedia-progress__bar"><span></span></div>
                <span class="freshet-unusedmedia-progress__label"></span>
              </div>';
    }

    // ------------------------------------------------------------------ used

    /**
     * The files the scan kept, and the tab that is their only listing. Every
     * row links to post.php rather than to the media library: the library
     * defaults to grid, the grid opens an attachment in a modal, and a modal
     * never fires add_meta_boxes_attachment — so no usage box of any kind can
     * render there.
     */
    private function renderUsedTable(): void
    {
        // The same read the tab strip makes, and deliberately not a different
        // one: currentTab() already drops an unknown ?tab back to the default,
        // so on an unentitled site this is unreachable through the URL. It is
        // here so the listing cannot be rendered by any future caller that
        // reaches it another way — the routing is not the entitlement.
        if (!$this->license->isPro()) {
            return;
        }

        $page = max(1, absint($_GET['used_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $filters = $this->filters();
        $list = $this->store->byStatus(ResultStore::STATUS_USED, $page, self::PER_PAGE, $filters);

        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . $this->listHeading( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in listHeading().
            /* translators: %s: number of used attachments */
            __('Used files (%s)', 'freshet-unused-media'),
            $list['total'],
            'used'
        ) . '</h2>';

        $this->renderFilterBar(self::TAB_USED);

        if ($list['ids'] === []) {
            echo '<p>' . esc_html($filters->isActive()
                ? __('No used files match this filter. Clear it to see the rest.', 'freshet-unused-media')
                : __('No attachments are currently marked used. Run a scan first.', 'freshet-unused-media')) . '</p></div>';

            return;
        }

        echo '<p class="description">' . esc_html(__('Every file here was found referenced somewhere on the site, so none of them is offered for deletion. Open one to see where it is used.', 'freshet-unused-media') . $this->sizeFilterNote()) . '</p>';

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';

        foreach ([__('File', 'freshet-unused-media'), __('Type', 'freshet-unused-media'), __('Uploaded', 'freshet-unused-media'), __('Size', 'freshet-unused-media'), __('References', 'freshet-unused-media'), __('Scanned', 'freshet-unused-media')] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            echo '<tr>';
            $this->renderFileCells($id);
            $this->renderReferencesCell($id);
            $this->renderScannedCell($id);
            echo '</tr>';
        }

        echo '</tbody></table>';

        $this->renderPagination(self::TAB_USED, 'used_page', $page, $list['total']);

        echo '</div>';
    }

    // ---------------------------------------------------------------- unused

    private function renderUnusedTable(): void
    {
        $page = max(1, absint($_GET['unused_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $filters = $this->filters();
        $list = $this->store->unused($page, self::PER_PAGE, $filters);

        echo '<div class="freshet-unusedmedia-section">';

        // freshet-D75 (8): the heading row carries the destructive control on
        // its right - "Unused files (400) [Delete all]". It sits outside the
        // selection form deliberately: it is a script-driven button, not a
        // submit, so the form it would otherwise post is irrelevant to it.
        echo '<div class="freshet-unusedmedia-section__heading">';
        echo '<h2>' . $this->listHeading( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in listHeading().
            /* translators: %s: number of unused attachments */
            __('Unused files (%s)', 'freshet-unused-media'),
            $list['total'],
            'unused'
        ) . '</h2>';

        if ($list['total'] > 0) {
            $this->renderDeleteAllButton($filters, $list['total']);
        }

        echo '</div>';

        if (!(defined('MEDIA_TRASH') && MEDIA_TRASH)) {
            echo '<p class="freshet-unusedmedia-warning">' . esc_html(sprintf(
                /* translators: 1: MEDIA_TRASH constant name, 2: the PHP line to add to wp-config.php */
                __('%1$s is not enabled — deletions are permanent. Add %2$s to wp-config.php to get a trash safety net.', 'freshet-unused-media'),
                'MEDIA_TRASH',
                "define( 'MEDIA_TRASH', true );"
            )) . '</p>';
        }

        $this->renderFilterBar(self::TAB_UNUSED);

        if ($list['ids'] === []) {
            // An empty list is where a fresh upload is hardest to account for:
            // nothing here, and nothing saying why. The grace explains it, and
            // only where a filter is not the more likely answer.
            $empty = array_filter([
                $filters->isActive()
                    ? __('No unused files match this filter. Clear it to see the rest.', 'freshet-unused-media')
                    : __('No attachments are currently marked unused. Run a scan first, or enjoy the tidy library.', 'freshet-unused-media'),
                $filters->isActive() ? '' : $this->graceNotice(),
            ]);

            echo '<p>' . esc_html(implode(' ', $empty)) . '</p></div>';

            return;
        }

        // The list a person reads when the file they went looking for is not in
        // it, so it says why one gets held back instead of leaving the absence
        // to be guessed at (freshet-D92 (4)). Three reasons, and the grace
        // window is the filtered one rather than a hardcoded day.
        $notes = array_filter([
            __('A file you expected here and cannot find is being held back rather than overlooked: something on the site still refers to it — a reference from a trashed post or comment counts as usage — or another library entry points at the same file and is itself in use or in the trash.', 'freshet-unused-media'),
            $this->graceNotice(),
            __('Every file below is re-checked in the instant before it is deleted — anything that has become used in the meantime is skipped.', 'freshet-unused-media'),
        ]);

        echo '<p class="description">' . esc_html(implode(' ', $notes) . $this->sizeFilterNote()) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="freshet-unusedmedia-delete-form">';
        wp_nonce_field('freshet_unusedmedia_delete_selected');
        echo '<input type="hidden" name="action" value="freshet_unusedmedia_delete_selected">';

        $this->renderDeleteSelectedButton($filters);

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" id="freshet-unusedmedia-select-all"></td>';

        foreach ([__('File', 'freshet-unused-media'), __('Type', 'freshet-unused-media'), __('Uploaded', 'freshet-unused-media'), __('Size', 'freshet-unused-media'), __('Scanned', 'freshet-unused-media')] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            $this->renderUnusedRow($id);
        }

        echo '</tbody></table>';

        $this->renderPagination(self::TAB_UNUSED, 'unused_page', $page, $list['total']);

        $this->renderDeleteSelectedButton($filters);

        $this->renderProgress();

        echo '</form>';
        echo '</div>';
    }

    /**
     * freshet-057's permanence sentence, and the reason it now lives in a
     * method of its own: the two delete buttons no longer share a row, so the
     * one line that carries the weight of both has to be composed rather than
     * repeated. Reads the trash net at render time, not at build time.
     */
    private function permanenceNotice(): string
    {
        return defined('MEDIA_TRASH') && MEDIA_TRASH
            ? __('Deleted files go to the media trash and can be restored from there.', 'freshet-unused-media')
            : __('Deletion is permanent and cannot be undone.', 'freshet-unused-media');
    }

    /** The promise the delete path actually keeps, said in every confirmation. */
    private function recheckNotice(): string
    {
        return __('Each one is re-checked first; anything still in use is skipped.', 'freshet-unused-media');
    }

    /**
     * The destructive control, and the one rule that governs it: a button says
     * which set it acts on.
     *
     * Unfiltered, "Delete all unused (400)" means the four hundred. Filtered, it
     * becomes "Delete all matching (37)" and the confirmation spells the filter
     * out in words, because a control that still said *all* over a screen
     * showing a subset is exactly how the wrong files get deleted. The filter
     * rides along on data-filters so the batch loop walks the same set the
     * number was counted from.
     *
     * It renders on the heading row (freshet-D75 clause 8), which is exactly why
     * it keeps button-link-delete and not button-primary: prominent enough to
     * find without scrolling, never the primary action of the screen.
     */
    private function renderDeleteAllButton(ResultFilters $filters, int $total): void
    {
        $recheck = $this->recheckNotice();
        $permanence = $this->permanenceNotice();

        if ($filters->isActive()) {
            $label = sprintf(
                /* translators: %s: number of unused attachments matching the filter */
                __('Delete all matching (%s)', 'freshet-unused-media'),
                number_format_i18n($total)
            );

            $confirmAll = sprintf(
                /* translators: 1: number of matching attachments, 2: the filter in words */
                __('Delete all %1$s unused attachments matching the current filter (%2$s)?', 'freshet-unused-media'),
                number_format_i18n($total),
                $filters->describe()
            ) . ' ' . $recheck . ' ' . $permanence;
        } else {
            $label = sprintf(
                /* translators: %s: number of unused attachments */
                __('Delete all unused (%s)', 'freshet-unused-media'),
                number_format_i18n($total)
            );

            $confirmAll = sprintf(
                /* translators: %s: number of unused attachments */
                __('Delete all %s unused attachments?', 'freshet-unused-media'),
                number_format_i18n($total)
            ) . ' ' . $recheck . ' ' . $permanence;
        }

        printf(
            '<button type="button" class="button button-link-delete" id="freshet-unusedmedia-delete-all" data-count="%d" data-confirm="%s" data-filters="%s">%s</button>',
            $total,
            esc_attr($confirmAll),
            esc_attr(http_build_query($filters->queryArgs())),
            esc_html($label)
        );
    }

    /**
     * "Delete selected", rendered twice - above the table and below it - so the
     * control is in reach from either end of a fifty-row list (freshet-D75
     * clause 8). Both are submits on the same form and carry the same
     * confirmation; neither takes an id, so there is nothing to collide.
     */
    private function renderDeleteSelectedButton(ResultFilters $filters): void
    {
        $confirmSelected = __('Delete the selected attachments?', 'freshet-unused-media')
            . ' ' . $this->recheckNotice()
            . ($filters->isActive() ? ' ' . __('Only files matching the current filter are listed, so the selection comes from that list.', 'freshet-unused-media') : '')
            . ' ' . $this->permanenceNotice();

        printf(
            '<p class="freshet-unusedmedia-actions">
                <button type="submit" class="button" onclick="return confirm(%s);">%s</button>
            </p>',
            esc_attr(wp_json_encode($confirmSelected)),
            esc_html__('Delete selected', 'freshet-unused-media')
        );
    }

    private function renderUnusedRow(int $id): void
    {
        echo '<tr>';
        printf('<th scope="row" class="check-column"><input type="checkbox" name="attachments[]" value="%d"></th>', (int) $id);
        $this->renderFileCells($id);
        $this->renderScannedCell($id);
        echo '</tr>';
    }

    // ------------------------------------------------------------ table cells

    /**
     * The References column, and the one row on this listing where a number is
     * the wrong answer.
     *
     * A file whose every reference is the upload grace is not used by
     * anything — it is held back, and reporting "1" here is precisely how
     * someone goes hunting for a file the plugin is deliberately protecting
     * (freshet-D92 (4)). It says so instead.
     */
    private function renderReferencesCell(int $id): void
    {
        $data = $this->store->refs($id);

        // Stored refs are capped, so a file with more references than were kept
        // cannot be grace-only however the kept ones read.
        $graceOnly = $data['refs'] !== [] && $data['count'] === count($data['refs']);

        foreach ($data['refs'] as $ref) {
            if ($ref->match !== 'recent-upload') {
                $graceOnly = false;

                break;
            }
        }

        echo '<td>' . esc_html($graceOnly
            ? UploadGrace::heldBack()
            : number_format_i18n($data['count'])) . '</td>';
    }

    /** File, type, upload date and size — identical in both listings. */
    private function renderFileCells(int $id): void
    {
        $file = get_attached_file($id);
        $bytes = FileSize::bytes($id) ?? 0;

        $size = $bytes > 0 ? size_format($bytes) : '—';
        $editLink = get_edit_post_link($id);
        $title = get_the_title($id);
        $filename = $file !== false ? wp_basename($file) : sprintf('#%d', $id);

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
    }

    private function renderScannedCell(int $id): void
    {
        $scannedAt = $this->store->scannedAt($id);

        echo '<td>' . esc_html($scannedAt > 0
            ? sprintf(
                /* translators: %s: human time diff */
                __('%s ago', 'freshet-unused-media'),
                human_time_diff($scannedAt)
            )
            : '—') . '</td>';
    }

    // --------------------------------------------------------------- license

    /** Nothing at all without the license stack, which is the free build. */
    private function renderLicenseSection(): void
    {
        if ($this->licenseSection === null) {
            return;
        }

        echo '<div class="freshet-unusedmedia-section">';
        $this->licenseSection->render();
        echo '</div>';
    }

    private function renderPagination(string $tab, string $arg, int $page, int $total): void
    {
        $pages = (int) ceil($total / self::PER_PAGE);

        if ($pages < 2) {
            return;
        }

        $links = paginate_links([
            'base' => add_query_arg($arg, '%#%', $this->tabUrl($tab)),
            'format' => '',
            'current' => $page,
            'total' => $pages,
        ]);

        if (is_string($links)) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post($links) . '</div></div>';
        }
    }
}

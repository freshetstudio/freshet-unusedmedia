<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\Scan\FileClaims;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\FileSize;
use FreshetUnusedMedia\Scan\OrphanSizes;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\ResultFilters;
use FreshetUnusedMedia\Scan\ResultSort;
use FreshetUnusedMedia\Scan\ResultStore;
use FreshetUnusedMedia\Scan\ScanProgress;
use FreshetUnusedMedia\Scan\ScanState;
use FreshetUnusedMedia\Scan\UploadGrace;

defined('ABSPATH') || exit;

/**
 * The Media → Usage screen: the scan and what it found, and the unused files
 * with their delete actions.
 *
 * The screen is extended through hooks rather than through anything it holds:
 * `freshet_unusedmedia_tabs` adds a tab to the strip,
 * `freshet_unusedmedia_render_tab` renders one this class does not own,
 * `freshet_unusedmedia_header_meta` prints into the header's meta strip, and
 * `freshet_unusedmedia_after_scan` / `freshet_unusedmedia_after_unused_list`
 * print a section under the scan and under the unused table. The listing
 * helpers a tab needs — the filter bar, the headings, the cells, the
 * pagination — are public for that reason.
 */
final class ToolsPage
{
    public const SLUG = 'freshet-unusedmedia';

    public const TAB_SCAN = 'scan';
    public const TAB_UNUSED = 'unused';

    public const PER_PAGE = 50;

    private const CAP = 'manage_options';

    /**
     * Where the Support link goes: the studio's support page, one address for
     * every plugin (freshet-D255/D256). The UTM pair names the plugin the
     * visitor came from, so the page's traffic can be read per plugin.
     */
    private const SUPPORT_URL = 'https://freshet.studio/support?utm_source=plugin&utm_medium=freshet-unused-media';

    /** Read once per request; both listings and every URL on the page share it. */
    private ?ResultFilters $filters = null;

    /** The same, for the column the listings are ordered by. */
    private ?ResultSort $sort = null;

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
        $remaining = absint($_GET['freshet_unusedmedia_remaining'] ?? 0);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $message = sprintf(
            /* translators: 1: deleted count, 2: skipped count, 3: failed count */
            __('Deleted %1$d, skipped %2$d (found in use on re-check), failed %3$d.', 'freshet-unused-media'),
            $deleted,
            $skipped,
            $failed
        );

        // The form posts every ticked box at once and the request stops when
        // its time is up, so this is the sentence that keeps a short run from
        // reading as a finished one. The files are untouched and still listed;
        // saying so is the whole of the resume instruction.
        if ($remaining > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of selected files the request had no time left for */
                _n(
                    '%d selected file was not reached before the request ran out of time — it is untouched and still listed.',
                    '%d selected files were not reached before the request ran out of time — they are untouched and still listed.',
                    $remaining,
                    'freshet-unused-media'
                ),
                $remaining
            );
        }

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            $failed > 0 || $remaining > 0 ? 'notice-warning' : 'notice-success',
            esc_html($message)
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $tab = $this->currentTab();

        // The strip is printed BEFORE `.wrap`, which is the whole of what makes
        // it sit flush at the top and run the full width of the screen: inside
        // `.wrap` it inherited core's `margin: 10px 20px 0 2px` on top of the
        // plugin's own `.freshet-unusedmedia-wrap` 1.5em, which was the gap
        // above it and the 20px gutter at its right. Freshet Feeds renders it
        // this way (FeedsPage::renderPage()) and is the reference for all three
        // plugins (freshet-D242).
        $this->renderHeader($tab);

        // Then the panel the strip belongs to. WordPress relocates every
        // `.notice` to just after `.wp-header-end`, so with the anchor inside
        // `.wrap` foreign notices land under the strip and inside the panel,
        // rather than between the tabs and what they switch. `.wrap` must also
        // carry an h1 for the admin's own heading logic; the strip's own title
        // is visual, so this one is for screen readers.
        echo '<div class="wrap freshet-unusedmedia-wrap">';
        printf(
            '<h1 class="screen-reader-text">%s</h1><hr class="wp-header-end">',
            esc_html__('Freshet Unused Media', 'freshet-unused-media')
        );

        if ($tab === self::TAB_UNUSED) {
            $this->renderUnusedTable();

            /**
             * Fires under the unused table, before the In-trash section.
             *
             * @param ToolsPage $page
             */
            do_action('freshet_unusedmedia_after_unused_list', $this);

            // The other set, under the unused list rather than between it
            // and anything printed above: these files are not in that table.
            $this->renderTrashTable();
        } elseif ($tab === self::TAB_SCAN) {
            $this->renderScanSection();

            /**
             * Fires under the scan section, before the orphan-sizes listing.
             *
             * @param ToolsPage $page
             */
            do_action('freshet_unusedmedia_after_scan', $this);

            // A by-product of the same scan: the size files it walked past
            // whose original is gone. Its own section, never folded into the
            // unused figures above it.
            $this->renderOrphanSizes();
        } else {
            /**
             * Renders a tab registered through `freshet_unusedmedia_tabs`.
             * currentTab() has already checked the slug is a registered one.
             *
             * @param string    $tab  The tab's slug.
             * @param ToolsPage $page The screen, for its listing helpers.
             */
            do_action('freshet_unusedmedia_render_tab', $tab, $this);
        }

        echo '</div>';
    }

    /**
     * The tabs, in order: Unused first, because finding what is unused is why
     * anyone opens this screen. Scan follows it and stays the default landing
     * tab — the second tab, not the first, which is deliberate: Unused is empty
     * on a site that has never scanned.
     *
     * @return array<string, array{label: string, listing: bool}> Keyed by
     *         slug; `listing` says whether the tab's URL carries the filter
     *         and sort, which is what lets the same window be read across
     *         listings without re-typing it.
     */
    private function tabs(): array
    {
        $tabs = [
            self::TAB_UNUSED => ['label' => __('Unused', 'freshet-unused-media'), 'listing' => true],
            self::TAB_SCAN => ['label' => __('Scan', 'freshet-unused-media'), 'listing' => false],
        ];

        /**
         * Filter the tabs on Media → Usage.
         *
         * @param array<string, array{label: string, listing: bool}> $tabs
         */
        return apply_filters('freshet_unusedmedia_tabs', $tabs);
    }

    /** One query arg, an allow-list, and the scan as the default. */
    private function currentTab(): string
    {
        $tab = sanitize_key(wp_unslash($_GET['tab'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only navigation.

        return array_key_exists($tab, $this->tabs()) ? $tab : self::TAB_SCAN;
    }

    public function filters(): ResultFilters
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only listing filter; every value is validated in fromRequest().
        return $this->filters ??= ResultFilters::fromRequest(wp_unslash($_GET));
    }

    public function sort(): ResultSort
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only listing order; an unknown column is not a sort at all.
        return $this->sort ??= ResultSort::fromRequest(wp_unslash($_GET));
    }

    /**
     * The screen's own URL for one tab; the default tab carries no arg.
     *
     * A listing carries the filter with it, so narrowing one list and then
     * crossing to another to check the same window is one click rather than a
     * re-typed filter. A tab that is not a listing takes no filter — there is
     * nothing there for it to narrow.
     *
     * The sort travels the same way and for the same reason, which is what makes
     * it survive paging: every page link is built on this URL. It rides along
     * even when the filter is being cleared — clearing a filter widens the set,
     * it does not un-sort the column someone chose to read it by.
     */
    public function tabUrl(string $tab, bool $filtered = true): string
    {
        $args = array_filter([
            'page' => self::SLUG,
            'tab' => $tab !== self::TAB_SCAN ? $tab : null,
        ]);

        if ($this->tabs()[$tab]['listing'] ?? false) {
            if ($filtered) {
                $args += $this->filters()->queryArgs();
            }

            $args += $this->sort()->queryArgs();
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
    public function renderFilterBar(string $tab): void
    {
        $filters = $this->filters();

        ?>
        <form method="get" class="freshet-unusedmedia-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <?php // A GET form replaces the query string wholesale, so the sort has to be posted back with it or filtering would silently re-order the list under the person doing it. ?>
            <?php foreach ($this->sort()->queryArgs() as $name => $value) : ?>
                <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php endforeach; ?>

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
    public function listHeading(string $singular, int $shown, string $status): string
    {
        if (!$this->filters()->isActive()) {
            return sprintf(esc_html($singular), $this->countFigure($status, $shown));
        }

        // The library-wide half of "37 of 400" is its own read, and it can fail
        // while the filtered listing above it succeeded (freshet-152). A dash
        // there says the total was not counted; "37 of 0" would say something
        // the database never said, and something arithmetically impossible.
        try {
            $total = $this->store->counts()[$status];
        } catch (QueryFailed) {
            $total = null;
        }

        return sprintf(
            esc_html($singular),
            sprintf(
                /* translators: 1: number of matching files, 2: number of files in total */
                esc_html__('%1$s of %2$s', 'freshet-unused-media'),
                esc_html(number_format_i18n($shown)),
                $this->countFigure($status, $total)
            )
        );
    }

    /**
     * A count the script can move without a page reload. Every delete batch
     * replies with the unused figure as it stands after it (Ajax::deleteBatch),
     * and admin.js writes it into whichever of these carry that status —
     * nothing is stored on either side, the number is read fresh per reply.
     *
     * Null is "not counted", not zero: the span is still emitted so a later
     * reply can write a real number into it, and what it holds until then is an
     * em dash rather than a figure nothing produced (freshet-152).
     */
    private function countFigure(string $status, ?int $count): string
    {
        return sprintf(
            '<span data-freshet-unusedmedia-count="%s">%s</span>',
            esc_attr($status),
            esc_html($count === null ? '—' : number_format_i18n($count))
        );
    }

    /**
     * A listing whose query did not answer, in place of the listing.
     *
     * Deliberately not an empty table with a "nothing here" line under it:
     * those two look identical to a reader and mean opposite things, which is
     * the whole of freshet-152. The heading keeps the section recognisable, and
     * neither a count nor a Delete all button is drawn — there is no set to
     * name, so there is nothing to offer an action on.
     */
    public function renderListingFailure(string $heading): void
    {
        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . esc_html($heading) . '</h2>';
        printf(
            '<div class="notice notice-error inline"><p>%s</p></div>',
            esc_html(
                QueryFailed::userMessage() . ' '
                . __('The list is not shown at all rather than shown short: a list cut off by a failed query looks exactly like a complete one. Reload the page to try again.', 'freshet-unused-media')
            )
        );
        echo '</div>';
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

    /**
     * A lead sentence and the points under it, as a list rather than as one
     * long sentence (freshet-D119: "can be shortened - bulleted - clearer").
     *
     * The list carries .description alongside the paragraphs it sits between,
     * so it is helper text by the admin's own reckoning and takes the same
     * reading measure they do — the same cap, not a second one.
     *
     * @param list<string> $points
     */
    private function renderNoteList(string $lead, array $points): void
    {
        echo '<p class="description">' . esc_html($lead) . '</p>';
        echo '<ul class="freshet-unusedmedia-notes description">';

        foreach ($points as $point) {
            echo '<li>' . esc_html($point) . '</li>';
        }

        echo '</ul>';
    }

    /** Said once, wherever a size filter changes which files can appear. */
    public function sizeFilterNote(): string
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
                    <?php
                    /**
                     * Fires inside the header's meta strip, before the Docs link.
                     */
                    do_action('freshet_unusedmedia_header_meta');
                    ?>
                    <a href="https://freshet.studio/docs" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Docs', 'freshet-unused-media'); ?></a>
                    <a href="<?php echo esc_url(self::SUPPORT_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Support', 'freshet-unused-media'); ?></a>
                </div>
            </div>
            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $entry) : ?>
                    <a class="nav-tab<?php echo $slug === $activeTab ? ' nav-tab-active' : ''; ?>"
                       href="<?php echo esc_url($this->tabUrl($slug)); ?>">
                        <?php echo esc_html($entry['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    // ------------------------------------------------------------------ scan

    private function renderScanSection(): void
    {
        // freshet-152: a tally that did not run comes back as three zeroes, and
        // "Unused: 0" is this screen telling someone their library is clean on
        // the strength of a query that never answered. Null is carried through
        // to the figures below, which then say that instead of printing it.
        try {
            $counts = $this->store->counts();
        } catch (QueryFailed) {
            $counts = null;
        }

        $running = $this->state->current();
        $last = $this->state->lastScan();

        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . esc_html__('Scan', 'freshet-unused-media') . '</h2>';

        // freshet-D119 (1): the three figures are the reason this screen
        // exists, and they used to sit at body size between two paragraphs of
        // helper text, where they read as a footnote. They now open the
        // section, with the scan's own timestamp under them and everything
        // that qualifies them — an unreadable database, an upload folder that
        // is not on this server — immediately after. Order and weight only:
        // nothing is boxed and nothing is capped, so freshet-D81's unboxing
        // and freshet-D89 (3) are untouched.
        if ($counts === null) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html(
                    QueryFailed::userMessage() . ' '
                    . __('The used, unused and not-scanned figures below stand at a dash rather than at zero — nothing has been counted, so nothing here says your library is clean. Reload the page; if it keeps happening, the queries are being cut short.', 'freshet-unused-media')
                )
            );
        }

        echo '<p class="freshet-unusedmedia-counts">';

        if ($counts !== null) {
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
                    /* translators: %s: the number of unused attachments, or an em dash when the count could not be read */
                    esc_html__('Unused: %s', 'freshet-unused-media'),
                    $this->countFigure(ResultStore::STATUS_UNUSED, $counts['unused']) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in countFigure().
                ),
                esc_html(sprintf(
                    /* translators: %s: number of unscanned attachments */
                    __('Not scanned: %s', 'freshet-unused-media'),
                    number_format_i18n($counts['unscanned'])
                ))
            );
        } else {
            // The label without a number. The span stays, so a delete loop
            // finishing on a working query still writes the figure back into a
            // screen that was rendered without one.
            printf(
                /* translators: %s: the number of unused attachments, or an em dash when the count could not be read */
                esc_html__('Unused: %s', 'freshet-unused-media'),
                $this->countFigure(ResultStore::STATUS_UNUSED, null) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in countFigure().
            );
        }

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

            // freshet-286: what the run cost, said once it is over. The bar
            // says it while the scan is going; this is the same two figures
            // kept after the reload that ends one, because "how long does a
            // scan of this library take" is a question asked before the next
            // one is started, not during.
            $cost = [];

            if ($last['elapsed'] > 0.0) {
                $cost[] = sprintf(
                    /* translators: %s: how long the scan took, already worded ("4 minutes") */
                    __('It took %s.', 'freshet-unused-media'),
                    ScanProgress::human((int) round($last['elapsed']))
                );
            }

            $breakdown = ScanProgress::breakdown($last['timing']);

            if ($breakdown !== '') {
                // "Of the time spent checking", not "of the scan": the cursor
                // queries, the sibling lookups and the disk reads are outside
                // the detectors, so these shares rank the checks against each
                // other and are not a budget of the whole run.
                $cost[] = sprintf(
                    /* translators: %s: a list like "post content 41%%, custom fields 33%%" */
                    __('Of the time spent checking: %s.', 'freshet-unused-media'),
                    $breakdown
                );
            }

            if ($cost !== []) {
                echo '<p class="description">' . esc_html(implode(' ', $cost)) . '</p>';
            }
        }

        // freshet-141: a query that fails comes back in the same shape as one
        // that matched nothing, so a run that lost half its answers used to
        // finish looking exactly like a run that found nothing. It no longer
        // can: an attachment whose scan could not read the database is left
        // with no verdict at all, and the count of them is said here. The
        // running figure wins over the finished one — a scan in progress is
        // the thing the reader can still act on.
        $scanErrors = (int) ($running['errors'] ?? ($last['errors'] ?? 0));

        if ($scanErrors > 0) {
            printf(
                '<div class="notice notice-error inline"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: %s: number of attachments */
                    _n(
                        'The database returned an error while scanning %s file, so it has no result and appears in neither list. Run the scan again — if it keeps happening, the queries are being cut short (an execution-time limit or a dropped connection).',
                        'The database returned an error while scanning %s files, so they have no result and appear in neither list. Run the scan again — if it keeps happening, the queries are being cut short (an execution-time limit or a dropped connection).',
                        $scanErrors,
                        'freshet-unused-media'
                    ),
                    number_format_i18n($scanErrors)
                ))
            );
        }

        // freshet-144: the disk read is half of how an attachment's names are
        // found, and on a library whose files are not on this server it does
        // nothing at all — silently, because a folder that cannot be opened
        // yields the same empty listing as a folder with nothing in it. Every
        // screen then looks exactly as it does for a library that has the
        // protection. Said here, once for the whole run, in the same place the
        // scan says everything else about itself. The running figures win over
        // the finished ones, for the same reason the error count does.
        $dirsUnread = (int) ($running['dirs_unread'] ?? ($last['dirs_unread'] ?? 0));
        $dirsRead = (int) ($running['dirs_read'] ?? ($last['dirs_read'] ?? 0));

        if ($dirsUnread > 0) {
            // No figure, deliberately: the two counts are of directory reads
            // rather than of directories, so the only thing either can be
            // quoted on is whether it is zero (see SizeSiblings). "None" and
            // "Some" is also the whole of what the reader has to act on — the
            // first says the library is elsewhere, the second says part of it
            // is.
            printf(
                '<div class="notice notice-warning inline"><p>%s %s</p></div>',
                esc_html($dirsRead === 0
                    ? __('None of the upload folders this scan looked in could be read from this server — the files are stored somewhere else, on an offload or CDN service, or the folders are no longer there.', 'freshet-unused-media')
                    : __('Some of the upload folders this scan looked in could not be read from this server — those files are stored somewhere else, on an offload or CDN service, or the folders are no longer there.', 'freshet-unused-media')),
                esc_html__('Those files are judged on what your library records about them and nothing else. If an old thumbnail is still sitting beside an image while your library has stopped listing it, a page still showing that thumbnail does not count as using the image — so the image can be reported as unused while a visitor can still see it. Check anything you delete from those folders against the pages you expect it on. Where the folders can be read, this is checked for you.', 'freshet-unused-media')
            );
        }

        // What the scan looks at, and why a file reaching the unused list can
        // be trusted. One place per line rather than one 48-word sentence
        // (freshet-D119 (2)); the closing sentence is the claim the list is
        // evidence for, so it stays a sentence.
        $this->renderNoteList(
            __('Every file is checked everywhere it could be referenced, not just where it was uploaded:', 'freshet-unused-media'),
            [
                __('Post content and blocks', 'freshet-unused-media'),
                __('Custom fields', 'freshet-unused-media'),
                __('Options and theme mods', 'freshet-unused-media'),
                __('Term and user meta', 'freshet-unused-media'),
                __('Comments and excerpts', 'freshet-unused-media'),
            ]
        );

        echo '<p class="description">' . esc_html__('Anything ambiguous counts as used, so a file reaches the unused list only when nothing anywhere refers to it.', 'freshet-unused-media') . '</p>';

        // freshet-D92 (4): a fresh upload is held out of the deletable pool on
        // purpose, and until now nothing said so anywhere a person looking for
        // it would be. This is the tab they land on.
        $grace = $this->graceNotice();

        if ($grace !== '') {
            echo '<p class="description">' . esc_html($grace) . '</p>';
        }

        // freshet-D92 (3): a delete orphaned the webp an optimiser had made of
        // the deleted original, and the next scan flagged it — the product
        // working, read as a stale result because nothing had said a cleanup
        // can do that. Said here, once, in the same voice as the rest, and as
        // three causes under a lead rather than one sentence with two
        // em-dashed asides inside it (freshet-D119 (3)).
        $this->renderNoteList(
            __('Worth running again after a cleanup — a later scan can honestly find files an earlier one did not, and that is what re-running is for:', 'freshet-unused-media'),
            [
                __('Deleting a file can leave others behind it unreferenced.', 'freshet-unused-media'),
                __('An optimiser or a resize tool registers its derivatives as library entries of their own.', 'freshet-unused-media'),
                __('Once the original is gone, nothing points at those any more.', 'freshet-unused-media'),
            ]
        );

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
                // The same composer the batch reply uses, so the text the page
                // is rendered with and the text the browser rewrites it to are
                // one string in one place (freshet-304).
                ? ScanProgress::resumeLabel($running['done'], $running['total'])
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
     * Generated size files the last scan found with no original behind them —
     * no file at the original's own path, and no library entry naming it.
     *
     * Deliberately its own section rather than a row in the unused list. Those
     * files belong to no attachment, so they have no row, no group, no verdict
     * and no bytes in the space figure; presenting them as unused *files* would
     * put a number on screen that the delete path cannot act on and the totals
     * do not include. Nothing here is deletable — the listing has no attachment
     * row to corroborate it against, and being wrong about a file is worse than
     * making somebody remove it themselves.
     */
    private function renderOrphanSizes(): void
    {
        $orphans = OrphanSizes::read();

        if ($orphans['count'] === 0) {
            return;
        }

        echo '<div class="freshet-unusedmedia-section">';
        printf(
            '<h2>%s</h2>',
            esc_html(sprintf(
                /* translators: %s: number of files. */
                __('Sizes with no original (%s)', 'freshet-unused-media'),
                number_format_i18n($orphans['count'])
            ))
        );
        echo '<p class="description">' . esc_html__('Thumbnail files whose original upload is gone: nothing sits at the original\'s own path and no library entry names it, so no attachment is holding them and no page can be showing them. They are listed on their own — not counted as unused, not in the list above, not in any space figure — and this plugin does not delete them.', 'freshet-unused-media') . '</p>';

        echo '<ul class="freshet-unusedmedia-orphans">';

        foreach ($orphans['files'] as $file) {
            echo '<li><code>' . esc_html($file) . '</code></li>';
        }

        echo '</ul>';

        if ($orphans['truncated']) {
            printf(
                '<p class="description">%s</p>',
                esc_html(sprintf(
                    // The same sentence the listings use for "showing N of M",
                    // and deliberately the same msgid: one phrase, one string.
                    /* translators: 1: number of files listed, 2: number of files in total */
                    __('%1$s of %2$s', 'freshet-unused-media'),
                    number_format_i18n(count($orphans['files'])),
                    number_format_i18n($orphans['count'])
                ))
            );
        }

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

    // ---------------------------------------------------------------- unused

    private function renderUnusedTable(): void
    {
        $page = max(1, absint($_GET['unused_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $filters = $this->filters();

        // The one listing with a destructive control attached to it, so this is
        // the catch that matters most: a short read renders an empty table, and
        // an empty unused table is the screen saying there is nothing to delete
        // (freshet-152). Nothing is listed and no Delete all button is drawn.
        try {
            $list = $this->store->unused($page, self::PER_PAGE, $filters, $this->sort());
        } catch (QueryFailed) {
            $this->renderListingFailure(__('Unused files', 'freshet-unused-media'));

            return;
        }

        echo '<div class="freshet-unusedmedia-section">';

        // freshet-D75 (8): the heading row carries the destructive control on
        // its right - "Unused files (400) [Delete all]". It sits outside the
        // selection form deliberately: it is a script-driven button, not a
        // submit, so the form it would otherwise post is irrelevant to it.
        echo '<div class="freshet-unusedmedia-section__heading">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in listHeading().
        echo '<h2>' . $this->listHeading(
            /* translators: %s: number of unused attachments */
            __('Unused files (%s)', 'freshet-unused-media'),
            $list['total'],
            'unused'
        ) . '</h2>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($list['total'] > 0) {
            $this->renderDeleteAllButton($filters, $list['total']);
        }

        echo '</div>';

        // Directly under the button that starts the run, not at the foot of the
        // page. A delete-all is minutes long and the only other thing it changes
        // in view is the button greying out, so a bar a full page of rows below
        // it is a screen that reads as having stopped responding (freshet-159).
        if ($list['total'] > 0) {
            $this->renderProgress();
        }

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
        // to be guessed at (freshet-D92 (4)). One reason per line rather than
        // one sentence carrying all of them (freshet-D119 (4)) — and the grace
        // window and the size filter are two more reasons a file is absent, so
        // they join the list instead of being run on to the end of it. The
        // grace window is still the filtered one rather than a hardcoded day.
        $this->renderNoteList(
            __('A file you expected here and cannot find is being held back, not overlooked:', 'freshet-unused-media'),
            array_values(array_filter([
                __('Something on the site still refers to it — a reference from a trashed post or comment counts as usage.', 'freshet-unused-media'),
                __('Another library entry points at the same file and is itself in use or in the trash.', 'freshet-unused-media'),
                $this->graceNotice(),
                trim($this->sizeFilterNote()),
            ]))
        );

        // The promise the delete path keeps, which is not a reason a file is
        // absent from the list — so it stands on its own rather than as a
        // fourth bullet under a lead about absence.
        echo '<p class="description">' . esc_html__('Every file below is re-checked in the instant before it is deleted — anything that has become used in the meantime is skipped.', 'freshet-unused-media') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="freshet-unusedmedia-delete-form">';
        wp_nonce_field('freshet_unusedmedia_delete_selected');
        echo '<input type="hidden" name="action" value="freshet_unusedmedia_delete_selected">';

        $this->renderDeleteSelectedButton($filters);

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" class="freshet-unusedmedia-select-all"></td>';

        $this->renderColumnHeaders([
            ResultSort::BY_FILE => __('File', 'freshet-unused-media'),
            'type' => __('Type', 'freshet-unused-media'),
            ResultSort::BY_DATE => __('Uploaded', 'freshet-unused-media'),
            ResultSort::BY_SIZE => __('Size', 'freshet-unused-media'),
            'scanned' => __('Scanned', 'freshet-unused-media'),
        ], self::TAB_UNUSED);

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            $this->renderUnusedRow($id);
        }

        echo '</tbody></table>';

        $this->renderPagination(self::TAB_UNUSED, 'unused_page', $page, $list['total']);

        $this->renderDeleteSelectedButton($filters);

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
            absint($total),
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
     *
     * The In-trash section passes its own permanence sentence: its rows are
     * already in the trash, so there is no trash for them to go to and the
     * MEDIA_TRASH reading of the default would promise a restore that cannot
     * happen.
     */
    private function renderDeleteSelectedButton(ResultFilters $filters, ?string $permanence = null): void
    {
        $confirmSelected = __('Delete the selected attachments?', 'freshet-unused-media')
            . ' ' . $this->recheckNotice()
            . ($filters->isActive() ? ' ' . __('Only files matching the current filter are listed, so the selection comes from that list.', 'freshet-unused-media') : '')
            . ' ' . ($permanence ?? $this->permanenceNotice());

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

    // -------------------------------------------------------------- in trash

    /**
     * The other set: files whose every library entry is in the trash. They
     * are not unused — the unused list, its count and its delete pool are the
     * library's and do not move — and they are not hidden either, which is
     * what they were. Their own section, their own count, their own checkbox
     * form, and nothing else of the Unused section: no Delete all, because
     * core's own Empty Trash is the bulk control for this set.
     *
     * Trashing an attachment removes no file, so a binned file a page still
     * renders is shown held rather than offered, and one nobody has scanned is
     * shown as that. Only a file whose every row was scanned unused gets a
     * checkbox — and the delete path reads the same verdicts again before it
     * touches anything.
     *
     * The same catch as the unused list (freshet-152): a read that did not
     * answer renders the failure notice, never an empty table.
     */
    private function renderTrashTable(): void
    {
        $page = max(1, absint($_GET['trash_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $filters = $this->filters();

        try {
            $list = $this->store->trash($page, self::PER_PAGE, $filters, $this->sort());
        } catch (QueryFailed) {
            $this->renderListingFailure(__('In trash', 'freshet-unused-media'));

            return;
        }

        echo '<div class="freshet-unusedmedia-section">';
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in listHeading().
        echo '<h2>' . $this->listHeading(
            /* translators: %s: number of files whose every library entry is in the trash */
            __('In trash (%s)', 'freshet-unused-media'),
            $list['total'],
            'trash'
        ) . '</h2>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

        if ($list['ids'] === []) {
            echo '<p>' . esc_html($filters->isActive()
                ? __('No files in the trash match this filter. Clear it to see the rest.', 'freshet-unused-media')
                : __('No file has every one of its library entries in the trash.', 'freshet-unused-media')) . '</p></div>';

            return;
        }

        $this->renderNoteList(
            __('Every file here is in the media trash with all of its library entries, so it is not in the lists above:', 'freshet-unused-media'),
            array_values(array_filter([
                __('Trashing an attachment does not remove its file — a page that still refers to it still shows it. A file scanned as still referenced is held back here rather than offered.', 'freshet-unused-media'),
                __('A file the scan has not reached has no verdict yet and is not offered either. Run a scan to decide it.', 'freshet-unused-media'),
                __('Erasing one here removes the file and its trashed entries for good — this is the permanent half of the media trash, the same as Delete Permanently there.', 'freshet-unused-media'),
                trim($this->sizeFilterNote()),
            ]))
        );

        // Primed once for the page rather than looked up per row: every row
        // below asks for its file's verdict, which is a sibling lookup.
        FileGroups::prime($list['ids']);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="freshet-unusedmedia-trash-form">';
        wp_nonce_field('freshet_unusedmedia_delete_selected');
        echo '<input type="hidden" name="action" value="freshet_unusedmedia_delete_selected">';

        $permanence = __('These files are already in the trash: deleting them here is permanent and cannot be undone.', 'freshet-unused-media');

        $this->renderDeleteSelectedButton($filters, $permanence);

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" class="freshet-unusedmedia-select-all"></td>';

        $this->renderColumnHeaders([
            ResultSort::BY_FILE => __('File', 'freshet-unused-media'),
            'type' => __('Type', 'freshet-unused-media'),
            ResultSort::BY_DATE => __('Uploaded', 'freshet-unused-media'),
            ResultSort::BY_SIZE => __('Size', 'freshet-unused-media'),
            'status' => __('Status', 'freshet-unused-media'),
            'scanned' => __('Scanned', 'freshet-unused-media'),
        ], self::TAB_UNUSED);

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            $this->renderTrashRow($id);
        }

        echo '</tbody></table>';

        $this->renderPagination(self::TAB_UNUSED, 'trash_page', $page, $list['total']);

        $this->renderDeleteSelectedButton($filters, $permanence);

        echo '</form>';
        echo '</div>';
    }

    /**
     * One binned file: a checkbox only where every row was scanned unused,
     * and the reason in words where not — the held sentence for a file
     * something still refers to (freshet-D95), and "Not scanned" for one
     * nobody has judged. The verdict is the file's, read through the same
     * bridge the Media Library badge uses, never the representative row's own.
     */
    private function renderTrashRow(int $id): void
    {
        $unavailable = false;

        try {
            $file = FileGroups::fileStatus($id);
        } catch (QueryFailed) {
            // No verdict can be read, so nothing is offered: the row is drawn
            // without a box and says the check did not run (freshet-141).
            $file = ['status' => FileGroups::STATUS_UNSCANNED, 'set' => FileGroups::SET_LIBRARY];
            $unavailable = true;
        }

        // A file restored to the library between the read and the render is
        // not in this set any more, and is not offered from it.
        $offered = $file['set'] === FileGroups::SET_TRASH && $file['status'] === ResultStore::STATUS_UNUSED;

        echo '<tr>';

        if ($offered) {
            printf('<th scope="row" class="check-column"><input type="checkbox" name="attachments[]" value="%d"></th>', (int) $id);
        } else {
            echo '<th scope="row" class="check-column"></th>';
        }

        $this->renderFileCells($id);

        if ($unavailable) {
            echo '<td>' . StatusBadge::unavailable() . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge HTML escaped in StatusBadge.
        } elseif ($file['status'] === ResultStore::STATUS_USED) {
            echo '<td>' . esc_html__('Held back — still referenced', 'freshet-unused-media') . '</td>';
        } elseif ($offered) {
            echo '<td>' . esc_html__('Unused', 'freshet-unused-media') . '</td>';
        } else {
            echo '<td>' . esc_html__('Not scanned', 'freshet-unused-media') . '</td>';
        }

        $this->renderScannedCell($id);
        echo '</tr>';
    }

    // ---------------------------------------------------------- table headers

    /**
     * The header row of either listing, where the header *is* the sort control.
     *
     * WordPress's own convention, transcribed rather than reinvented: the same
     * `sortable`/`sorted` classes, the same paired sorting indicators, the same
     * `aria-sort` on the one column that carries the order, and the same
     * screen-reader sentence on the ones that do not. It is copied from
     * WP_List_Table::print_column_headers() because these tables are hand-rolled
     * — the list-table CSS these classes hook into is not scoped to a list
     * table, so `widefat` is enough and no stylesheet of ours is involved.
     *
     * One inversion in it looks like a mistake and is core's: an unsorted column
     * is given the class *opposite* to the direction its link would apply. It is
     * kept as core has it rather than corrected.
     *
     * Which columns are offered is not a display choice. A sort orders files,
     * and the listings page one row per file, so only what survives the grouping
     * can be ordered on — the path, the earliest upload date, and the size.
     * Type, References and Scanned are properties of an attachment row rather
     * than of the file behind it, so they are headers and nothing more.
     *
     * @param array<string, string> $columns Column key => label; a key that is
     *                                       one of ResultSort's is sortable.
     */
    public function renderColumnHeaders(array $columns, string $tab): void
    {
        $sort = $this->sort();
        $sortable = ResultSort::columns();

        foreach ($columns as $key => $label) {
            $classes = ['manage-column', 'column-' . $key];

            if (!in_array($key, $sortable, true)) {
                printf('<th scope="col" class="%s">%s</th>', esc_attr(implode(' ', $classes)), esc_html($label));

                continue;
            }

            $args = $sort->linkArgs($key);
            $order = $args[ResultSort::ARG_ORDER];
            $ariaSort = '';
            $hint = '';

            if ($sort->isSortedBy($key)) {
                $classes[] = 'sorted';
                $classes[] = $sort->direction;
                $ariaSort = $sort->isDescending() ? ' aria-sort="descending"' : ' aria-sort="ascending"';
            } else {
                $classes[] = 'sortable';
                $classes[] = $order === ResultSort::DESC ? 'asc' : 'desc';
                $hint = $order === ResultSort::ASC
                    /* translators: Hidden accessibility text. */
                    ? __('Sort ascending.', 'freshet-unused-media')
                    /* translators: Hidden accessibility text. */
                    : __('Sort descending.', 'freshet-unused-media');
            }

            printf(
                '<th scope="col" class="%1$s"%2$s><a href="%3$s"><span>%4$s</span>'
                    . '<span class="sorting-indicators">'
                        . '<span class="sorting-indicator asc" aria-hidden="true"></span>'
                        . '<span class="sorting-indicator desc" aria-hidden="true"></span>'
                    . '</span>%5$s</a></th>',
                esc_attr(implode(' ', $classes)),
                $ariaSort, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of two literals above.
                esc_url(add_query_arg($args, $this->tabUrl($tab))),
                esc_html($label),
                $hint === '' ? '' : ' <span class="screen-reader-text">' . esc_html($hint) . '</span>'
            );
        }
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
     *
     * Whether that holds is UploadGrace's to answer, not this method's: the
     * Media Library badge asks the same question one click away and the two
     * screens must not each have their own opinion (freshet-D97 (3)).
     *
     * A file kept because deleting it would take another entry's file with it
     * reads the same way and for the same reason (freshet-153) — it is a file
     * this plugin is protecting, and the number beside it would be a count of
     * entries that would be damaged rather than of places it is used. Asked
     * first, because it is the reason that does not expire.
     */
    public function renderReferencesCell(int $id): void
    {
        $data = $this->store->refs($id);

        if (FileClaims::holdsAlone($data)) {
            echo '<td>' . esc_html(FileClaims::heldBack()) . '</td>';

            return;
        }

        echo '<td>' . esc_html(UploadGrace::holdsAlone($id, $data)
            ? UploadGrace::heldBack()
            : number_format_i18n($data['count'])) . '</td>';
    }

    /** File, type, upload date and size — identical in both listings. */
    public function renderFileCells(int $id): void
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

    public function renderScannedCell(int $id): void
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


    public function renderPagination(string $tab, string $arg, int $page, int $total): void
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

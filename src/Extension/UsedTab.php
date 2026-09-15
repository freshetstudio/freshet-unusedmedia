<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Extension;

use FreshetUnusedMedia\Admin\ToolsPage;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\Scan\QueryFailed;
use FreshetUnusedMedia\Scan\ResultSort;
use FreshetUnusedMedia\Scan\ResultStore;

defined('ABSPATH') || exit;

/**
 * The Used tab on Media → Usage: the files the scan kept, and the tab that is
 * their only listing. Every row links to post.php rather than to the media
 * library: the library defaults to grid, the grid opens an attachment in a
 * modal, and a modal never fires add_meta_boxes_attachment — so no usage box
 * of any kind can render there.
 *
 * It hangs off the screen's tab hooks rather than living in ToolsPage: the tab
 * is the paid listing, so it appears where the site is *entitled* to it —
 * LicenseInterface::isPro(), the one read UsedView, SpaceTotals and
 * EvidenceReport already make. Remove the key and the tab is gone on the next
 * load, because nothing here asks a second question. Nothing is ever rendered
 * locked or as a teaser: an unentitled site simply has one tab fewer.
 */
final class UsedTab
{
    public const TAB = 'used';

    public function __construct(
        private readonly ResultStore $store,
        private readonly LicenseInterface $license,
    ) {
    }

    public function hooks(): void
    {
        add_filter('freshet_unusedmedia_tabs', [$this, 'addTab']);
        add_action('freshet_unusedmedia_render_tab', [$this, 'render'], 10, 2);
    }

    /**
     * After Scan and before License, which is where it sat before the listing
     * moved out here — the License tab registers at priority 20 for that.
     *
     * @param array<string, array{label: string, listing: bool}> $tabs
     * @return array<string, array{label: string, listing: bool}>
     */
    public function addTab(array $tabs): array
    {
        if ($this->license->isPro()) {
            $tabs[self::TAB] = ['label' => __('Used', 'freshet-unused-media'), 'listing' => true];
        }

        return $tabs;
    }

    public function render(string $tab, ToolsPage $page): void
    {
        // The same read the tab strip makes, and deliberately not a different
        // one: currentTab() already drops an unknown ?tab back to the default,
        // so on an unentitled site this is unreachable through the URL. It is
        // here so the listing cannot be rendered by any future caller that
        // reaches it another way — the routing is not the entitlement.
        if ($tab !== self::TAB || !$this->license->isPro()) {
            return;
        }

        $currentPage = max(1, absint($_GET['used_page'] ?? 1)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only pagination.
        $filters = $page->filters();

        try {
            $list = $this->store->byStatus(ResultStore::STATUS_USED, $currentPage, ToolsPage::PER_PAGE, $filters, $page->sort());
        } catch (QueryFailed) {
            $page->renderListingFailure(__('Used files', 'freshet-unused-media'));

            return;
        }

        echo '<div class="freshet-unusedmedia-section">';
        echo '<h2>' . $page->listHeading( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in listHeading().
            /* translators: %s: number of used attachments */
            __('Used files (%s)', 'freshet-unused-media'),
            $list['total'],
            'used'
        ) . '</h2>';

        $page->renderFilterBar(self::TAB);

        if ($list['ids'] === []) {
            echo '<p>' . esc_html($filters->isActive()
                ? __('No used files match this filter. Clear it to see the rest.', 'freshet-unused-media')
                : __('No attachments are currently marked used. Run a scan first.', 'freshet-unused-media')) . '</p></div>';

            return;
        }

        // The same pass as the Unused tab's notes (freshet-D119). These two
        // sentences are already short and already direct, and two of them are
        // not a list, so what changes is the one thing that was wrong with the
        // block: the size filter was run on to the end of them, where it reads
        // as part of the promise rather than as the separate qualification of
        // which files are here that it is.
        echo '<p class="description">' . esc_html__('Every file here was found referenced somewhere on the site, so none of them is offered for deletion. Open one to see where it is used.', 'freshet-unused-media') . '</p>';

        $sizeNote = trim($page->sizeFilterNote());

        if ($sizeNote !== '') {
            echo '<p class="description">' . esc_html($sizeNote) . '</p>';
        }

        echo '<table class="widefat striped freshet-unusedmedia-table"><thead><tr>';

        $page->renderColumnHeaders([
            ResultSort::BY_FILE => __('File', 'freshet-unused-media'),
            'type' => __('Type', 'freshet-unused-media'),
            ResultSort::BY_DATE => __('Uploaded', 'freshet-unused-media'),
            ResultSort::BY_SIZE => __('Size', 'freshet-unused-media'),
            'references' => __('References', 'freshet-unused-media'),
            'scanned' => __('Scanned', 'freshet-unused-media'),
        ], self::TAB);

        echo '</tr></thead><tbody>';

        foreach ($list['ids'] as $id) {
            echo '<tr>';
            $page->renderFileCells($id);
            $page->renderReferencesCell($id);
            $page->renderScannedCell($id);
            echo '</tr>';
        }

        echo '</tbody></table>';

        $page->renderPagination(self::TAB, 'used_page', $currentPage, $list['total']);

        echo '</div>';
    }
}

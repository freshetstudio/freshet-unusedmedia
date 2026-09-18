<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Admin;

use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\License\RemoteLicense;

defined('ABSPATH') || exit;

/**
 * License card on Media → Usage: enter key → activate; deactivate; status.
 * Gating itself stays in UsedView — this is UI.
 *
 * Ported from freshet-feeds' LicenseSection. What changed: it renders inside
 * the Usage page's own card markup, the redirect target is upload.php, the
 * "Data" block came out (this plugin has no delete-data setting), and the
 * copy names the whole of what a license adds here.
 *
 * It owns its tab on the screen and the tier pill in the header, through the
 * screen's hooks: the tab appears wherever there is a license stack to show
 * at all, which is every build that carries this file.
 */
final class LicenseSection
{
    public const TAB = 'license';

    /** Where a customer writes when the verdict text says to; one place, used by every sentence that names it. */
    private const SUPPORT_EMAIL = 'email@freshet.studio';

    private const NOTICE_ARG = 'freshet_unusedmedia_license_notice';
    private const MESSAGE_ARG = 'freshet_unusedmedia_license_message';

    public function __construct(
        private readonly LicenseClient $client,
        private readonly LicenseInterface $license,
    ) {
    }

    public function hooks(): void
    {
        add_action('admin_post_freshet_unusedmedia_activate_license', [$this, 'activate']);
        add_action('admin_post_freshet_unusedmedia_deactivate_license', [$this, 'deactivate']);
        add_action('admin_notices', [$this, 'renderNotice']);
        // Priority 20: last in the strip, after the Used tab's 10.
        add_filter('freshet_unusedmedia_tabs', [$this, 'addTab'], 20);
        add_action('freshet_unusedmedia_render_tab', [$this, 'renderTab']);
        add_action('freshet_unusedmedia_header_meta', [$this, 'renderPill']);
    }

    /**
     * Last in the strip. Takes no filter — there is nothing here to narrow.
     *
     * @param array<string, array{label: string, listing: bool}> $tabs
     * @return array<string, array{label: string, listing: bool}>
     */
    public function addTab(array $tabs): array
    {
        $tabs[self::TAB] = ['label' => __('License', 'freshet-unused-media'), 'listing' => false];

        return $tabs;
    }

    public function renderTab(string $tab): void
    {
        if ($tab !== self::TAB) {
            return;
        }

        echo '<div class="freshet-unusedmedia-section">';
        $this->render();
        echo '</div>';
    }

    /**
     * The tier, in the header's meta strip, from the license itself rather than
     * from which files are on disk. No link hangs off it.
     */
    public function renderPill(): void
    {
        if ($this->license->isPro()) {
            echo '<span class="frst-header__pill frst-header__pill--pro">' . esc_html__('Pro', 'freshet-unused-media') . '</span>';
        } else {
            echo '<span class="frst-header__pill frst-header__pill--free">' . esc_html__('Free', 'freshet-unused-media') . '</span>';
        }
    }

    public function activate(): void
    {
        $this->authorize('freshet_unusedmedia_activate_license');

        $key = $this->normalizeKey(sanitize_text_field(wp_unslash($_POST['license_key'] ?? '')));

        if ($key === '') {
            $this->back('error', __('Enter a license key.', 'freshet-unused-media'));
        }

        $response = $this->client->activate($key, home_url());

        // A transport failure is not an answer: the request may well have
        // reached the server and been recorded before the connection died, in
        // which case reporting failure is a lie about state that already
        // changed. Activation is idempotent per site, so asking a second time
        // is free — and whatever comes back is the server's word, not a guess.
        if (($response['error_code'] ?? '') === 'http_error') {
            $retry = $this->client->activate($key, home_url());

            if (($retry['error_code'] ?? '') !== 'http_error') {
                $response = $retry;
            }
        }

        // The key is stored only on an explicit success from the server. No
        // other branch reaches it, so an unanswered activation can never leave
        // this site claiming a license the server never confirmed.
        if (!($response['success'] ?? false)) {
            $this->back('error', $this->failureMessage($response));
        }

        update_option(RemoteLicense::OPTION_KEY, $key, false);
        RemoteLicense::bustCache();

        $this->back('activated');
    }

    public function deactivate(): void
    {
        $this->authorize('freshet_unusedmedia_deactivate_license');

        $key = RemoteLicense::storedKey();

        if ($key !== '') {
            // Best effort: free the seat server-side, but always clear locally.
            $this->client->deactivate($key, home_url());
        }

        delete_option(RemoteLicense::OPTION_KEY);
        RemoteLicense::bustCache();

        $this->back('deactivated');
    }

    /** The card itself; renderTab() wraps it in the screen's section markup. */
    public function render(): void
    {
        echo '<h2>' . esc_html__('License', 'freshet-unused-media') . '</h2>';

        // The activation outcome is announced on admin_notices at the top of
        // the screen; the card shows the standing state, not the last event.
        $key = RemoteLicense::storedKey();

        if ($key === '') {
            echo '<p class="description">' . esc_html__('Scanning, detection and deletion are free, and stay free — they are the plugin, and nothing in this download is locked or time-limited. A license adds four things:', 'freshet-unused-media') . '</p>';

            $this->renderTierList();

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('freshet_unusedmedia_activate_license');
            echo '<input type="hidden" name="action" value="freshet_unusedmedia_activate_license">';
            printf(
                '<p><input type="text" name="license_key" class="regular-text" placeholder="%s" required> <button type="submit" class="button button-primary">%s</button></p>',
                esc_attr__('License key', 'freshet-unused-media'),
                esc_html__('Activate', 'freshet-unused-media')
            );
            echo '</form>';

            return;
        }

        $isPro = $this->license->isPro();

        // The state line says why, not only which tier: the reason is what
        // RemoteLicense cached beside its verdict. A license handed in through
        // the filter has only the boolean, and the line says only that.
        $verdict = $this->license instanceof RemoteLicense ? $this->license->verdict() : ['valid' => $isPro];

        printf(
            '<p>%s <code>%s…%s</code> — <strong class="%s">%s</strong></p>',
            esc_html__('Key:', 'freshet-unused-media'),
            esc_html(substr($key, 0, 6)),
            esc_html(substr($key, -4)),
            $isPro ? 'freshet-unusedmedia-license--on' : 'freshet-unusedmedia-license--off',
            esc_html($this->stateLine($verdict))
        );

        // The tier said once, then what it actually buys: the state line above
        // answers "am I on Pro", and a customer's next question is "and what
        // does that give me" — which the screen never answered, so he had to
        // ask. Same four either way; only the framing changes, because a key
        // that stopped validating has not taken the free plugin away with it.
        echo '<p class="description">' . esc_html($isPro
            ? __('Alongside the free scan, detection and deletion, your license adds these four:', 'freshet-unused-media')
            : __('Scanning, detection and deletion carry on as normal. These four are what a license adds:', 'freshet-unused-media')) . '</p>';

        $this->renderTierList();

        $deactivateUrl = wp_nonce_url(
            add_query_arg(['action' => 'freshet_unusedmedia_deactivate_license'], admin_url('admin-post.php')),
            'freshet_unusedmedia_deactivate_license'
        );

        printf(
            '<p><a href="%s" class="button">%s</a></p>',
            esc_url($deactivateUrl),
            esc_html__('Deactivate license on this site', 'freshet-unused-media')
        );
    }

    /**
     * What a license adds, in one place and in the reader's words rather than
     * the code's. Four items because the tier has four components, and the
     * same four whether or not there is a working key on the site: the free
     * build is the whole plugin, so this list adds, it never unlocks.
     */
    private function renderTierList(): void
    {
        $adds = [
            __('The Used view — open any file that is in use and see every place it is used, not just the first few.', 'freshet-unused-media'),
            __('A WP-CLI command — run the same scan across many sites without a browser, and read the result as JSON.', 'freshet-unused-media'),
            __('An evidence report — export the whole library as CSV or JSON, one row per file with its status, size and references, before anything is deleted.', 'freshet-unused-media'),
            __('Space totals — how much disk the unused files are holding now, and how much deleting here has already freed.', 'freshet-unused-media'),
        ];

        echo '<ul class="freshet-unusedmedia-adds description">';

        foreach ($adds as $add) {
            echo '<li>' . esc_html($add) . '</li>';
        }

        echo '</ul>';
    }

    /**
     * Activating is the first thing a paying customer does, so its outcome is
     * announced where WordPress puts outcomes — the top of the screen, on
     * `admin_notices` — and not inline in a card that sits below the scan
     * panel and the whole unused-files table. Runs on our screen only.
     */
    public function renderNotice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notice from our own redirect.
        if (!current_user_can('manage_options') || sanitize_key(wp_unslash($_GET['page'] ?? '')) !== ToolsPage::SLUG) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET[self::NOTICE_ARG] ?? ''));
        $message = sanitize_text_field(wp_unslash($_GET[self::MESSAGE_ARG] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($notice === '') {
            return;
        }

        $text = match ($notice) {
            'activated' => __('License activated. This site is now on Freshet Unused Media Pro.', 'freshet-unused-media'),
            'deactivated' => __('License removed from this site.', 'freshet-unused-media'),
            default => $message !== '' ? $message : __('Activation failed.', 'freshet-unused-media'),
        };

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            $notice === 'error' ? 'notice-error' : 'notice-success',
            esc_html($text)
        );
    }

    /**
     * What to tell the customer when the key was not stored. Only the license
     * server may call a key wrong: a connection that failed and a body that
     * could not be parsed say nothing whatsoever about the key, and reporting
     * either as a rejection sends someone hunting a fault that is ours. The
     * verdicts themselves are told in the plugin's words (verdictText); the
     * server's sentence is the fallback for a code this plugin does not know.
     *
     * @param array{success?: bool, data?: array<string, mixed>, error?: string, error_code?: string} $response
     */
    private function failureMessage(array $response): string
    {
        $code = (string) ($response['error_code'] ?? '');
        $detail = trim((string) ($response['error'] ?? ''));
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return match ($code) {
            'http_error' => sprintf(
                /* translators: %s: the connection error reported by WordPress */
                __('Could not reach the license server, so nothing on this site changed. This is not a verdict on your key — try again in a minute. (%s)', 'freshet-unused-media'),
                $detail !== '' ? $detail : __('no response', 'freshet-unused-media')
            ),
            'invalid_response' => sprintf(
                /* translators: %s: description of the unreadable response, including the HTTP status */
                __('%s The key was not activated and this is not a verdict on your key — try again, and contact support if it keeps happening.', 'freshet-unused-media'),
                $detail !== '' ? $detail : __('The license server sent a response this plugin could not read.', 'freshet-unused-media')
            ),
            default => $this->verdictText($code, $data)
                ?? ($detail !== '' ? $detail : __('Activation failed.', 'freshet-unused-media')),
        };
    }

    /**
     * The plugin's own words for each verdict the license server can give —
     * what happened, then what to do — read by the activation notice and the
     * card's state line alike, so the two never drift and both translate. The
     * codes are the server's contract and are matched, never rewritten; a
     * code this map does not know returns null and the caller falls back to
     * the server's sentence.
     *
     * @param array<string, mixed> $counts anything carrying activations_used / activation_limit
     */
    private function verdictText(string $code, array $counts = []): ?string
    {
        $used = $counts['activations_used'] ?? null;
        $limit = $counts['activation_limit'] ?? null;

        return match ($code) {
            // An unknown key gets the one thing the server cannot know: the
            // paste has already been cleaned (normalizeKey), so re-pasting it
            // will not change the answer — checking the characters will.
            'invalid_key' => sprintf(
                /* translators: %s: the support email address */
                __('This key isn\'t one the license server knows — it may have lost a character in the paste, or belong to a different Freshet plugin. Spaces and invisible characters were already stripped before sending, so pasting it again will not help: compare it character by character with your purchase email, and if it matches, write to %s from the address you paid with.', 'freshet-unused-media'),
                self::SUPPORT_EMAIL
            ),
            'expired' => sprintf(
                /* translators: %s: the support email address */
                __('This key\'s twelve months of updates and support have ended, so this site is on Free. The plugin keeps working — scanning, detection and deletion are the plugin — and another year is a separate purchase: write to %s from the address you paid with.', 'freshet-unused-media'),
                self::SUPPORT_EMAIL
            ),
            'revoked' => sprintf(
                /* translators: %s: the support email address */
                __('This key was cancelled, usually after a refund or a chargeback, so this site is on Free. If that is not what you expected, write to %s from the address you paid with.', 'freshet-unused-media'),
                self::SUPPORT_EMAIL
            ),
            'activation_limit_reached' => is_numeric($used) && is_numeric($limit)
                ? sprintf(
                    /* translators: 1: number of sites the key is active on, 2: number it is allowed on */
                    __('This key is already active on its allowed number of sites — %1$d of %2$d. Deactivate it on a site you no longer use (that site\'s License section), or buy a second key.', 'freshet-unused-media'),
                    (int) $used,
                    (int) $limit
                )
                : __('This key is already active on its allowed number of sites. Deactivate it on a site you no longer use (that site\'s License section), or buy a second key.', 'freshet-unused-media'),
            default => null,
        };
    }

    /**
     * The card's standing sentence, from the cached verdict: which tier the
     * site is on and — when it is Free with a key stored — why, in the same
     * words the activation notice uses. An unreachable server is the one
     * state the notice never shows: on grace the site is still on Pro and the
     * line says so with the date of the failed check; once the grace has run
     * out it is on Free and the line says the server, not the key, is the
     * reason.
     *
     * @param array{valid?: bool, reason?: string, checked_at?: int, message?: string, activations_used?: ?int, activation_limit?: ?int} $verdict
     */
    private function stateLine(array $verdict): string
    {
        $reason = (string) ($verdict['reason'] ?? '');
        // The date of the failed check, in the site's own date format; only the
        // two unreachable lines read it.
        $checked = fn (): string => (string) wp_date((string) get_option('date_format', 'F j, Y'), (int) ($verdict['checked_at'] ?? 0));

        if ($verdict['valid'] ?? false) {
            return $reason === RemoteLicense::REASON_UNREACHABLE
                ? sprintf(
                    /* translators: %s: the date of the last validation attempt */
                    __('Active — this site is still on Freshet Unused Media Pro for now, though the license server could not be reached when it last checked, on %s. Nothing about your key changed, and the check is retried automatically.', 'freshet-unused-media'),
                    $checked()
                )
                : __('Active — this site is on Freshet Unused Media Pro.', 'freshet-unused-media');
        }

        return match ($reason) {
            RemoteLicense::REASON_UNREACHABLE => sprintf(
                /* translators: 1: the date of the last validation attempt, 2: the support email address */
                __('The license server could not be reached when this site last checked, on %1$s, and there is no recent confirmation of the key to fall back on — so this site is on Free until the server answers again. Nothing about your key changed; if this keeps up, write to %2$s.', 'freshet-unused-media'),
                $checked(),
                self::SUPPORT_EMAIL
            ),
            'invalid_response' => sprintf(
                /* translators: %s: the support email address */
                __('The license server sent a response this plugin could not read, so this site is on Free for now. This is not a verdict on your key — the check is retried automatically, and if it keeps happening, write to %s.', 'freshet-unused-media'),
                self::SUPPORT_EMAIL
            ),
            default => $this->verdictText($reason, $verdict)
                ?? ((string) ($verdict['message'] ?? '') !== ''
                    ? (string) $verdict['message']
                    : __('This key did not validate, so this site is on Free.', 'freshet-unused-media')),
        };
    }

    /**
     * A key pasted out of an email routinely arrives wrapped in a non-breaking
     * space or a zero-width character, neither of which sanitize_text_field()
     * removes — and the server then correctly answers "unknown key" about a
     * key that was copied correctly. Drop what is invisible and change nothing
     * else: which characters a key may contain is the server's business.
     */
    private function normalizeKey(string $key): string
    {
        $stripped = preg_replace('/[\s\x{00A0}\x{00AD}\x{180E}\x{200B}-\x{200F}\x{2060}\x{FEFF}]/u', '', $key);

        return is_string($stripped) ? $stripped : trim($key);
    }

    private function authorize(string $nonceAction): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage the license.', 'freshet-unused-media'));
        }

        check_admin_referer($nonceAction);
    }

    private function back(string $notice, string $message = ''): never
    {
        wp_safe_redirect(add_query_arg(array_filter([
            'page' => ToolsPage::SLUG,
            // Back to the tab the form was submitted from; without it an
            // activation lands on the scan and its notice looks unrelated.
            'tab' => self::TAB,
            self::NOTICE_ARG => $notice,
            self::MESSAGE_ARG => $message !== '' ? rawurlencode($message) : null,
        ]), admin_url('upload.php')));

        exit;
    }
}

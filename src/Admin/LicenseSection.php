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
 * the Usage page's own card markup rather than a tab, the redirect target is
 * upload.php, the "Data" block came out (this plugin has no delete-data
 * setting), and the copy names the whole of what a license adds here.
 */
final class LicenseSection
{
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

    /** Rendered by ToolsPage inside its own card. */
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

        printf(
            '<p>%s <code>%s…%s</code> — %s</p>',
            esc_html__('Key:', 'freshet-unused-media'),
            esc_html(substr($key, 0, 6)),
            esc_html(substr($key, -4)),
            $isPro
                ? '<strong class="freshet-unusedmedia-license--on">' . esc_html__('Active — this site is on Freshet Unused Media Pro.', 'freshet-unused-media') . '</strong>'
                : '<strong class="freshet-unusedmedia-license--off">' . esc_html__('Invalid or expired — this site is on Free.', 'freshet-unused-media') . '</strong>'
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
     * either as a rejection sends someone hunting a fault that is ours.
     *
     * @param array{success?: bool, error?: string, error_code?: string} $response
     */
    private function failureMessage(array $response): string
    {
        $detail = trim((string) ($response['error'] ?? ''));

        return match ((string) ($response['error_code'] ?? '')) {
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
            // Anything else is the server's own answer about the key; it says
            // it better than we can, so it is passed through as written.
            default => $detail !== '' ? $detail : __('Activation failed.', 'freshet-unused-media'),
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
            'tab' => ToolsPage::TAB_LICENSE,
            self::NOTICE_ARG => $notice,
            self::MESSAGE_ARG => $message !== '' ? rawurlencode($message) : null,
        ]), admin_url('upload.php')));

        exit;
    }
}

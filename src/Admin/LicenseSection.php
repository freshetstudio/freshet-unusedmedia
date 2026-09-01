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
 * setting), and the copy names what the license buys here — the Used view.
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
    }

    public function activate(): void
    {
        $this->authorize('freshet_unusedmedia_activate_license');

        $key = sanitize_text_field(wp_unslash($_POST['license_key'] ?? ''));

        if ($key === '') {
            $this->back('error', __('Enter a license key.', 'freshet-unused-media'));
        }

        $response = $this->client->activate($key, home_url());

        if (!($response['success'] ?? false)) {
            $this->back('error', (string) ($response['error'] ?? __('Activation failed.', 'freshet-unused-media')));
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

        $this->renderNotice();

        $key = RemoteLicense::storedKey();

        if ($key === '') {
            echo '<p class="description">' . esc_html__('Scanning, detection and deletion are free, and stay free. A license adds the Used view: open any file that is in use and see every place it is used.', 'freshet-unused-media') . '</p>';

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

        printf(
            '<p>%s <code>%s…%s</code> — %s</p>',
            esc_html__('Key:', 'freshet-unused-media'),
            esc_html(substr($key, 0, 6)),
            esc_html(substr($key, -4)),
            $this->license->isPro()
                ? '<strong class="freshet-unusedmedia-license--on">' . esc_html__('Active — the Used view is on every file that is in use.', 'freshet-unused-media') . '</strong>'
                : '<strong class="freshet-unusedmedia-license--off">' . esc_html__('Invalid or expired — the Used view is hidden. Everything else is unaffected.', 'freshet-unused-media') . '</strong>'
        );

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

    private function renderNotice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notice from our own redirect.
        $notice = sanitize_key(wp_unslash($_GET[self::NOTICE_ARG] ?? ''));
        $message = sanitize_text_field(wp_unslash($_GET[self::MESSAGE_ARG] ?? ''));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($notice === '') {
            return;
        }

        $text = match ($notice) {
            'activated' => __('License activated.', 'freshet-unused-media'),
            'deactivated' => __('License removed from this site.', 'freshet-unused-media'),
            default => $message !== '' ? $message : __('Activation failed.', 'freshet-unused-media'),
        };

        printf(
            '<div class="notice inline %s"><p>%s</p></div>',
            $notice === 'error' ? 'notice-error' : 'notice-success',
            esc_html($text)
        );
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
            self::NOTICE_ARG => $notice,
            self::MESSAGE_ARG => $message !== '' ? rawurlencode($message) : null,
        ]), admin_url('upload.php')));

        exit;
    }
}

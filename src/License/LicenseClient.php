<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\License;

defined('ABSPATH') || exit;

/**
 * HTTP client for the license server (freshet.studio). All endpoints return the
 * {success, data?, error?, error_code?} envelope; license-level failures arrive
 * as HTTP 200 with success:false — one parse path.
 *
 * Ported from freshet-feeds. Only the three calls the license state needs are
 * here: update injection is not part of this plugin, so updateCheck() and the
 * product constant it carried stayed behind.
 */
final class LicenseClient
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        /**
         * Filter the license server base URL (dev: point at the .test server).
         *
         * @param string $baseUrl
         */
        $this->baseUrl = untrailingslashit((string) apply_filters(
            'freshet_unusedmedia_license_server',
            $baseUrl ?? 'https://freshet.studio'
        ));
    }

    /** @return array{success: bool, data?: array<string, mixed>, error?: string, error_code?: string} */
    public function activate(string $key, string $siteUrl): array
    {
        return $this->post('/api/v1/licenses/activate', ['key' => $key, 'site_url' => $siteUrl]);
    }

    /** @return array{success: bool, data?: array<string, mixed>, error?: string, error_code?: string} */
    public function deactivate(string $key, string $siteUrl): array
    {
        return $this->post('/api/v1/licenses/deactivate', ['key' => $key, 'site_url' => $siteUrl]);
    }

    /** @return array{success: bool, data?: array<string, mixed>, error?: string, error_code?: string} */
    public function validate(string $key, string $siteUrl): array
    {
        return $this->post('/api/v1/licenses/validate', ['key' => $key, 'site_url' => $siteUrl]);
    }

    /**
     * @param array<string, string> $body
     * @return array{success: bool, data?: array<string, mixed>, error?: string, error_code?: string}
     */
    private function post(string $path, array $body): array
    {
        $response = wp_remote_post($this->baseUrl . $path, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
                'error_code' => 'http_error',
            ];
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($decoded) || !isset($decoded['success'])) {
            return [
                'success' => false,
                'error' => sprintf(
                    /* translators: %d: HTTP status code */
                    __('Unexpected response from the license server (HTTP %d).', 'freshet-unused-media'),
                    (int) wp_remote_retrieve_response_code($response)
                ),
                'error_code' => 'invalid_response',
            ];
        }

        return $decoded;
    }
}

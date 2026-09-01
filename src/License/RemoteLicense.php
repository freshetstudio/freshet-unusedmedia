<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\License;

defined('ABSPATH') || exit;

/**
 * License backed by the remote license server. What a validating key buys is
 * the Used view; scanning, detection, the unused list and every delete flow are
 * free and never reach this class.
 *
 * Validation results are cached in a transient (12h); a lapsed cache
 * re-validates lazily and FAILS OPEN for a bounded window on network errors — a
 * hiccup at the license server must never downgrade a paying customer's site.
 *
 * Ported from freshet-feeds. Unchanged in substance: the cache, the grace and
 * the "no key means no call" short-circuit are the same code. What differs is
 * the option/transient names, the missing canUseProxy() (there is no proxy
 * here), and the ABSPATH guard this repo puts on every source file.
 */
final class RemoteLicense implements LicenseInterface
{
    public const OPTION_KEY = 'freshet_unusedmedia_license_key';
    private const CACHE_KEY = 'freshet_unusedmedia_license_status';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** How long an unreachable license server keeps a site on Pro (grace, not forever). */
    private const FAIL_OPEN_GRACE = 7 * DAY_IN_SECONDS;
    private const LAST_OK_OPTION = 'freshet_unusedmedia_license_last_ok';

    private ?bool $valid = null;

    public function __construct(private readonly LicenseClient $client)
    {
    }

    public function isPro(): bool
    {
        return $this->valid ??= $this->resolve();
    }

    public static function storedKey(): string
    {
        return (string) get_option(self::OPTION_KEY, '');
    }

    /** Force a fresh validation on next check (after activate/deactivate). */
    public static function bustCache(): void
    {
        delete_transient(self::CACHE_KEY);
    }

    private function resolve(): bool
    {
        $key = self::storedKey();

        // No key: never call the license server. The directory build must not
        // phone home unprompted, and a free site has nothing to validate.
        if ($key === '') {
            return false;
        }

        $cached = get_transient(self::CACHE_KEY);

        if (is_array($cached) && ($cached['key'] ?? '') === $key) {
            return (bool) ($cached['valid'] ?? false);
        }

        $response = $this->client->validate($key, home_url());

        if (($response['error_code'] ?? '') === 'http_error') {
            // Server unreachable: keep the customer running — but only within a
            // bounded grace window since the last SUCCESSFUL validation, so
            // blocking the license server doesn't become a permanent unlock.
            $lastOk = (int) get_option(self::LAST_OK_OPTION, 0);
            $valid = $lastOk > 0 && (time() - $lastOk) < self::FAIL_OPEN_GRACE;

            set_transient(self::CACHE_KEY, ['key' => $key, 'valid' => $valid], self::CACHE_TTL);

            return $valid;
        }

        $valid = ($response['success'] ?? false) && (bool) ($response['data']['valid'] ?? false);

        if ($valid) {
            update_option(self::LAST_OK_OPTION, time(), false);
        }

        set_transient(self::CACHE_KEY, ['key' => $key, 'valid' => $valid], self::CACHE_TTL);

        return $valid;
    }
}

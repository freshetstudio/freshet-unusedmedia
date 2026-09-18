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
 * The cache remembers the reason beside the verdict — active, expired, revoked,
 * unknown key, or the server unreachable — and when it was last checked, so the
 * License card can say why a site is on Free rather than only that it is.
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

    /** The reasons this class assigns itself; anything else is the server's error code as sent. */
    public const REASON_ACTIVE = 'active';
    public const REASON_EXPIRED = 'expired';
    public const REASON_REVOKED = 'revoked';
    public const REASON_UNREACHABLE = 'unreachable';

    /** @var array{valid: bool, reason: string, checked_at: int, message: string, activations_used: ?int, activation_limit: ?int}|null */
    private ?array $verdict = null;

    public function __construct(private readonly LicenseClient $client)
    {
    }

    public function isPro(): bool
    {
        return $this->verdict()['valid'];
    }

    /**
     * The verdict as the License card reads it: whether the key validates,
     * why, when that was last checked, the server's own sentence when it sent
     * one, and the activation counts when it sent those. Same resolution and
     * same cache as isPro() — this is the whole of what isPro() reduces to a
     * boolean. A site without a key has no verdict: reason is empty.
     *
     * @return array{valid: bool, reason: string, checked_at: int, message: string, activations_used: ?int, activation_limit: ?int}
     */
    public function verdict(): array
    {
        return $this->verdict ??= $this->resolve();
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

    /** @return array{valid: bool, reason: string, checked_at: int, message: string, activations_used: ?int, activation_limit: ?int} */
    private function resolve(): array
    {
        $key = self::storedKey();

        // No key: never call the license server. The directory build must not
        // phone home unprompted, and a free site has nothing to validate.
        if ($key === '') {
            return self::verdictOf(false, '', 0);
        }

        $cached = get_transient(self::CACHE_KEY);

        // A transient written before the reason was cached is a miss, not a
        // verdict without a why: one extra validation after the upgrade.
        if (is_array($cached) && ($cached['key'] ?? '') === $key && isset($cached['reason'])) {
            return self::verdictOf(
                (bool) ($cached['valid'] ?? false),
                (string) $cached['reason'],
                (int) ($cached['checked_at'] ?? 0),
                (string) ($cached['message'] ?? ''),
                $cached
            );
        }

        $response = $this->client->validate($key, home_url());
        $now = time();

        if (($response['error_code'] ?? '') === 'http_error') {
            // Server unreachable: keep the customer running — but only within a
            // bounded grace window since the last SUCCESSFUL validation, so
            // blocking the license server doesn't become a permanent unlock.
            $lastOk = (int) get_option(self::LAST_OK_OPTION, 0);
            $valid = $lastOk > 0 && ($now - $lastOk) < self::FAIL_OPEN_GRACE;

            $verdict = self::verdictOf($valid, self::REASON_UNREACHABLE, $now);
        } else {
            $answered = (bool) ($response['success'] ?? false);
            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $valid = $answered && (bool) ($data['valid'] ?? false);

            if ($valid) {
                update_option(self::LAST_OK_OPTION, $now, false);
            }

            // The server answers about a known key with valid + status, and it
            // has no "expired" status: a key it knows and will not honour is
            // revoked, or it is past its year — the same split the server
            // makes itself when it refuses an activation. A key it does not
            // know, or any other refusal, carries an error code, kept as sent.
            $reason = match (true) {
                $valid => self::REASON_ACTIVE,
                $answered => ($data['status'] ?? '') === 'revoked' ? self::REASON_REVOKED : self::REASON_EXPIRED,
                default => (string) ($response['error_code'] ?? ''),
            };

            $verdict = self::verdictOf($valid, $reason, $now, trim((string) ($response['error'] ?? '')), $data);
        }

        set_transient(self::CACHE_KEY, ['key' => $key] + $verdict, self::CACHE_TTL);

        return $verdict;
    }

    /**
     * @param array<string, mixed> $counts anything carrying activations_used / activation_limit
     * @return array{valid: bool, reason: string, checked_at: int, message: string, activations_used: ?int, activation_limit: ?int}
     */
    private static function verdictOf(bool $valid, string $reason, int $checkedAt, string $message = '', array $counts = []): array
    {
        return [
            'valid' => $valid,
            'reason' => $reason,
            'checked_at' => $checkedAt,
            'message' => $message,
            'activations_used' => is_numeric($counts['activations_used'] ?? null) ? (int) $counts['activations_used'] : null,
            'activation_limit' => is_numeric($counts['activation_limit'] ?? null) ? (int) $counts['activation_limit'] : null,
        ];
    }
}

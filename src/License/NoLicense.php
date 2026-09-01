<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\License;

defined('ABSPATH') || exit;

/**
 * The wordpress.org build. It carries neither the license client nor the
 * feature that client gates — bin/release.conf strips both — so nothing in the
 * directory archive is locked and nothing upsells (directory guideline 5).
 * Used automatically when the remote-license stack is absent.
 */
final class NoLicense implements LicenseInterface
{
    public function isPro(): bool
    {
        return false;
    }
}

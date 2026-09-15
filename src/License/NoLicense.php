<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\License;

defined('ABSPATH') || exit;

/**
 * A license that answers no. Not wired by default — RemoteLicense is — but a
 * site can hand it in through the freshet_unusedmedia_license filter to hold a
 * licensed build on the free surfaces, and the smoke tests construct it.
 */
final class NoLicense implements LicenseInterface
{
    public function isPro(): bool
    {
        return false;
    }
}

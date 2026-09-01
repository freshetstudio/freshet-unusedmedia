<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\License;

defined('ABSPATH') || exit;

interface LicenseInterface
{
    /**
     * Whether this site is entitled to the paid tier. Scanning, detection, the
     * unused list and every delete flow are free and never consult this.
     */
    public function isPro(): bool;
}

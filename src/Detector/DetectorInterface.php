<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

interface DetectorInterface
{
    public function id(): string;

    /** @return Reference[] */
    public function find(AttachmentContext $ctx): array;
}

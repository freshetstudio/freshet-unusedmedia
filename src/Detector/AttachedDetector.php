<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * The core "Uploaded to" relation (post_parent). Informational only: an
 * attachment record on a post is not a content reference — this is exactly
 * the unreliable signal the plugin exists to correct, so it never marks a
 * file as used.
 */
final class AttachedDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'attached';
    }

    public function find(AttachmentContext $ctx): array
    {
        if ($ctx->parentId <= 0) {
            return [];
        }

        $parent = get_post($ctx->parentId);

        if ($parent === null || $parent->post_type === 'revision') {
            return [];
        }

        return [new Reference(
            detector: 'attached',
            objectType: 'post',
            objectId: $ctx->parentId,
            detail: 'post_parent',
            match: 'attached',
            confidence: Reference::INFO,
        )];
    }
}

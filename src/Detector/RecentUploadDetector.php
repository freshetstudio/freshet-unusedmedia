<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\Reference;
use FreshetUnusedMedia\Scan\UploadGrace;

defined('ABSPATH') || exit;

/**
 * A file uploaded recently has had no chance to be referenced yet: an editor
 * is still placing it, or the post that will carry it is not saved. It stays
 * out of the deletable pool for a grace period (default one day; filter
 * `freshet_unusedmedia_upload_grace`, seconds, 0 disables).
 *
 * The window is read through UploadGrace, which is also what the Tools screens
 * quote when they explain the absence — one filter read, one number, so the
 * copy cannot promise a window this does not enforce.
 */
final class RecentUploadDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'recent-upload';
    }

    public function find(AttachmentContext $ctx): array
    {
        $grace = UploadGrace::seconds();
        $uploaded = get_post_timestamp($ctx->id);

        if ($grace <= 0 || $uploaded === false || time() - $uploaded >= $grace) {
            return [];
        }

        return [new Reference(
            detector: 'recent-upload',
            objectType: 'post',
            objectId: $ctx->id,
            detail: 'recent-upload',
            match: 'recent-upload',
            confidence: Reference::POSSIBLE,
        )];
    }
}

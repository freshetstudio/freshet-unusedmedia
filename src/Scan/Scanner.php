<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

use FreshetUnusedMedia\Detector\AttachedDetector;
use FreshetUnusedMedia\Detector\DetectorInterface;
use FreshetUnusedMedia\Detector\OptionsDetector;
use FreshetUnusedMedia\Detector\PostContentDetector;
use FreshetUnusedMedia\Detector\PostmetaDetector;
use FreshetUnusedMedia\Detector\TermMetaDetector;
use FreshetUnusedMedia\Detector\UserMetaDetector;

defined('ABSPATH') || exit;

/**
 * Runs all detectors against one attachment, computes the status
 * (any confirmed/possible reference = used) and persists the result.
 */
final class Scanner
{
    public function __construct(private readonly ResultStore $store)
    {
    }

    /**
     * @return array{status: string, refs: Reference[]}
     */
    public function scan(int $attachmentId): array
    {
        $ctx = AttachmentContext::forAttachment($attachmentId);

        $detectors = [
            new PostmetaDetector(),
            new PostContentDetector(),
            new OptionsDetector(),
            new TermMetaDetector(),
            new UserMetaDetector(),
            new AttachedDetector(),
        ];

        /** @var DetectorInterface[] $detectors */
        $detectors = apply_filters('freshet_unusedmedia_detectors', $detectors, $ctx);

        $refs = [];

        foreach ($detectors as $detector) {
            foreach ($detector->find($ctx) as $ref) {
                $refs[] = $ref;
            }
        }

        $used = false;

        foreach ($refs as $ref) {
            if ($ref->countsAsUsed()) {
                $used = true;
                break;
            }
        }

        /** Final say on the computed status for site-specific edge cases. */
        $used = (bool) apply_filters('freshet_unusedmedia_is_used', $used, $refs, $ctx);

        $status = $used ? ResultStore::STATUS_USED : ResultStore::STATUS_UNUSED;

        $this->store->save($attachmentId, $status, $refs);

        return ['status' => $status, 'refs' => $refs];
    }
}

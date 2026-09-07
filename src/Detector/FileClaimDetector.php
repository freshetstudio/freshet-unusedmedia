<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Detector;

use FreshetUnusedMedia\Scan\AttachmentContext;
use FreshetUnusedMedia\Scan\FileClaims;
use FreshetUnusedMedia\Scan\FileGroups;
use FreshetUnusedMedia\Scan\Reference;

defined('ABSPATH') || exit;

/**
 * A file another library entry stands on, or names, and would lose.
 *
 * The one detector that does not look for a reference to this attachment. It
 * asks the question the delete path asks — *would removing this file take a
 * file some other attachment still needs?* — and asks it of the same class,
 * FileClaims, so there is one implementation of it rather than a scan-time
 * copy that can drift from the guard it is meant to agree with (freshet-153).
 *
 * **Why the scan has to ask it at all.** FileGroups keys on `_wp_attached_file`
 * and cannot express this relation, so two rows sharing one physical file sit
 * in two groups that never meet and honestly reach opposite verdicts: on a real
 * library, one attachment scans *used* and the other scans *unused* about the
 * same file on disk. The delete path refuses the second one — that is
 * freshet-142 and it is what keeps the file — but the listing had already
 * offered it, and the refusal came back as a "skipped … found in use on
 * re-check" count with nothing on the row. The plugin knew exactly why and said
 * nothing. Deciding it here means the file never reaches the unused list, and
 * the reason travels with it as evidence like every other reason does.
 *
 * **The claim is on the group, never on the row.** claimants() excludes the
 * rows it is asked about, so a lone row would report its own siblings — every
 * duplicate of a file claiming its twin — which is the grouping question
 * FileGroups already answers correctly. The siblings lookup is memoised per
 * path for the request and primed for a whole batch at once, so it is one query
 * per file rather than one per row.
 *
 * **CONFIRMED, and it counts as used.** Nothing is inferred: both sides were
 * read from the database, and the file would genuinely be destroyed. `info`
 * would be the wrong confidence twice over — it never counts as used, which is
 * the whole point here, and it is the confidence reserved for a signal this
 * plugin distrusts.
 */
final class FileClaimDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'file-claim';
    }

    /**
     * @return Reference[]
     * @throws \FreshetUnusedMedia\Scan\QueryFailed A read that did not answer is
     *         not an answer of "nobody else needs these files": Scanner turns it
     *         into no verdict at all rather than into "unused" (freshet-141).
     */
    public function find(AttachmentContext $ctx): array
    {
        $refs = [];

        foreach (FileClaims::claimants(FileGroups::siblings($ctx->id)) as $path => $rowId) {
            $refs[] = new Reference(
                detector: 'file-claim',
                objectType: 'post',
                objectId: $rowId,
                // The file at risk, so the evidence line names what would be
                // lost rather than only who would lose it.
                detail: $path,
                match: FileClaims::MATCH,
                confidence: Reference::CONFIRMED,
            );
        }

        return $refs;
    }
}

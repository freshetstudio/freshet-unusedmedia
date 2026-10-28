<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * This request is no longer the one building the reference index.
 *
 * **Two admins, two tabs, one index.** Nothing stopped a second pass over the
 * corpus starting while the first was still running, and the two did not
 * merely duplicate work — they corrupted each other. The pass forgets whatever
 * a read wrote past its cursor on the way into that read
 * (`ReferenceIndex::forgetAfter()`, and the note there says why it must), so a
 * slower pass entering `post-content` deletes the rows a faster one has already
 * written for it. The faster one then reaches the end of the eight reads and
 * stamps the index `complete`. What is left is an index with a hole in it that
 * every lookup trusts, and a hole in this index reads as *no references* —
 * which is *unused*, which is the delete offer. freshet-424, and the direction
 * freshet-D116 does not allow a wrong answer to point in.
 *
 * **So a pass writes only while it is still the pass.** Every request that
 * drives the index holds the cursor it last saw (`ScanState::indexToken()`),
 * and each of the three writes that can do damage — the rewind, the cursor
 * advance, the completion stamp — is refused when the stored cursor is no
 * longer that one. Somebody else is driving: a newer run from a second Start,
 * or the same run moved on by a request that overtook this one. Either way the
 * honest thing is to stop, because this request's idea of where the pass is
 * came from a reading that is no longer true.
 *
 * Raised rather than returned, for the reason `QueryFailed` is: the callers in
 * the middle of this — eight reads deep in a loop — have nowhere to put a false
 * and every reason not to carry on. `Ajax::scanBatch()` and the WP-CLI command
 * catch it at the top and say so.
 */
final class ScanSuperseded extends \RuntimeException
{
    /**
     * What a person is told.
     *
     * Nothing went wrong and nothing was lost — the other scan is still
     * running and has the library — so the sentence says what happened, what
     * it means, and the one thing to do about it (R26 / aitodo-D104).
     */
    public static function userMessage(): string
    {
        return __(
            'Another scan started while this one was running, so this one stopped rather than write over it. Nothing was lost — reload the page to follow the scan that is still going.',
            'freshet-unused-media'
        );
    }
}

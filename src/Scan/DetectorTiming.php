<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * How long each detector spent, for the run to say so.
 *
 * A scan of a large library is the one thing this plugin does that a person has
 * to wait for, and until now the only thing it said about itself while running
 * was `120 / 3400`. Two questions it could not answer: how long is left, and
 * what is it doing. The second is this class — the first needs a clock over the
 * whole run and lives in ScanState (freshet-286).
 *
 * **Request-scoped and taken, not read.** Same idiom as SizeSiblings'
 * directory tally: a batch accumulates into it, the caller `take()`s the total
 * once and the slate is clear for the next batch. Nothing persists here,
 * because the figure that outlives a request belongs in the cursor option
 * where a page reload can find it.
 *
 * **It measures, it never decides.** add() is called from Scanner around each
 * detector's find(); no verdict, no reference and no count reads any of this.
 * A timing that is wrong makes a sentence on screen wrong and nothing else —
 * which is why the measurement is a microtime subtraction and not, say, a
 * query counter that would have to reach inside $wpdb.
 */
final class DetectorTiming
{
    /** @var array<string, float> Seconds per detector id, this request. */
    private static array $seconds = [];

    public static function add(string $detectorId, float $seconds): void
    {
        // Floored at zero: microtime() is wall clock and a clock that steps
        // backwards mid-batch would otherwise subtract from a total the screen
        // then reports as a share of 100%.
        self::$seconds[$detectorId] = (self::$seconds[$detectorId] ?? 0.0) + max(0.0, $seconds);
    }

    /**
     * This batch's tally, and a clean slate.
     *
     * @return array<string, float>
     */
    public static function take(): array
    {
        $seconds = self::$seconds;
        self::$seconds = [];

        return $seconds;
    }

    public static function flush(): void
    {
        self::$seconds = [];
    }

    /**
     * What this detector is called on screen.
     *
     * The ids are stable keys — they are written into the cursor option and
     * read back by a later request — so the words a person reads are mapped
     * here rather than being the ids themselves. Deliberately the same
     * vocabulary as the "Every file is checked everywhere it could be
     * referenced" list on Media → Usage: someone reading "custom fields" in
     * the progress line has already read it three inches above.
     *
     * An id this map does not know is another plugin's detector, added through
     * `freshet_unusedmedia_detectors`. It is named by its own id rather than
     * dropped — a run whose time went somewhere unnamed says where, and a
     * third-party detector is exactly the thing worth seeing in that line.
     */
    public static function label(string $detectorId): string
    {
        $labels = [
            'postmeta' => __('custom fields', 'freshet-unused-media'),
            'post-content' => __('post content', 'freshet-unused-media'),
            'options' => __('options and theme mods', 'freshet-unused-media'),
            'termmeta' => __('term meta', 'freshet-unused-media'),
            'term-description' => __('term descriptions', 'freshet-unused-media'),
            'usermeta' => __('user meta', 'freshet-unused-media'),
            'comment' => __('comments', 'freshet-unused-media'),
            'recent-upload' => __('recent uploads', 'freshet-unused-media'),
            'attached' => __('what a file is attached to', 'freshet-unused-media'),
            'file-claim' => __('other library entries on the same file', 'freshet-unused-media'),
        ];

        return $labels[$detectorId] ?? $detectorId;
    }
}

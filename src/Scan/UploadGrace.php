<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * The upload grace: how long a freshly uploaded file is kept out of the
 * deletable pool, and how that is said to a human.
 *
 * The value and the sentence live together on purpose. The screens have to
 * quote the window a user's file is actually being held for, and a hardcoded
 * "24 hours" beside a filtered grace is a lie told by the one surface whose
 * whole job is to explain an absence. `RecentUploadDetector` decides with
 * seconds(); the copy quotes window(); there is one read of the filter.
 *
 * heldBack() is the settled phrasing for a file the plugin is protecting
 * rather than missing — "Held back — <why>". Other reasons a file never
 * reaches the unused list (a sibling row on the same path still in use, or one
 * of them in the trash — see FileGroups::verdict()) read in the same shape, so
 * a user meets one sentence pattern rather than three inventions.
 */
final class UploadGrace
{
    /**
     * The grace in seconds, filtered. Zero or less disables the hold entirely,
     * which is how the test install sees every fresh upload immediately.
     *
     * The plugin's one read of `freshet_unusedmedia_upload_grace`.
     */
    public static function seconds(): int
    {
        return (int) apply_filters('freshet_unusedmedia_upload_grace', DAY_IN_SECONDS);
    }

    /** Whether anything is being held back at all. Copy that claims a window checks this first. */
    public static function isActive(): bool
    {
        return self::seconds() > 0;
    }

    /**
     * The same window as a duration a person reads — "24 hours", "2 days",
     * "30 minutes". The default day is quoted in hours rather than as "1 day"
     * because that is the sentence the screens want: *uploaded in the last 24
     * hours*.
     */
    public static function window(): string
    {
        $grace = max(0, self::seconds());

        if ($grace >= 2 * DAY_IN_SECONDS && $grace % DAY_IN_SECONDS === 0) {
            $days = intdiv($grace, DAY_IN_SECONDS);

            return sprintf(
                /* translators: %s: number of days */
                _n('%s day', '%s days', $days, 'freshet-unused-media'),
                number_format_i18n($days)
            );
        }

        if ($grace >= HOUR_IN_SECONDS && $grace % HOUR_IN_SECONDS === 0) {
            $hours = intdiv($grace, HOUR_IN_SECONDS);

            return sprintf(
                /* translators: %s: number of hours */
                _n('%s hour', '%s hours', $hours, 'freshet-unused-media'),
                number_format_i18n($hours)
            );
        }

        if ($grace >= MINUTE_IN_SECONDS && $grace % MINUTE_IN_SECONDS === 0) {
            $minutes = intdiv($grace, MINUTE_IN_SECONDS);

            return sprintf(
                /* translators: %s: number of minutes */
                _n('%s minute', '%s minutes', $minutes, 'freshet-unused-media'),
                number_format_i18n($minutes)
            );
        }

        return sprintf(
            /* translators: %s: number of seconds */
            _n('%s second', '%s seconds', $grace, 'freshet-unused-media'),
            number_format_i18n($grace)
        );
    }

    /**
     * Is the grace the only thing keeping this file out of the unused pool?
     *
     * The one place this is decided. It used to be a local variable inside
     * ToolsPage::renderReferencesCell(), which is why the Media Library badge
     * went on printing "Used (1 reference)" for a file the plugin was holding
     * for 24 hours — the number a person then goes hunting behind
     * (freshet-D92 (4), freshet-D95 (4)). A rule discovered in one screen's
     * private method belongs to the class that owns the concept, so both
     * screens ask rather than the second one re-deriving it (freshet-D97 (3)).
     *
     * Three things have to hold, and dropping any one of them makes the
     * sentence a lie:
     *
     * - the grace is on, and this file is *still* inside it. Stored refs are a
     *   record of what a scan found when it ran; a day later the same meta
     *   would have the screen claim a file was "uploaded in the last 24 hours"
     *   when it was uploaded last week. The window is read now, not then.
     * - every reference kept is the grace's own.
     * - nothing was truncated. Refs are capped at storage, so a file with more
     *   references than were kept cannot be grace-only however the kept ones
     *   read — believing a truncated list is how a used file gets called held.
     *
     * @param array{count: int, refs: Reference[]} $data exactly what ResultStore::refs() returns
     */
    public static function holdsAlone(int $attachmentId, array $data): bool
    {
        if ($data['refs'] === [] || $data['count'] !== count($data['refs'])) {
            return false;
        }

        foreach ($data['refs'] as $ref) {
            if ($ref->match !== 'recent-upload') {
                return false;
            }
        }

        $grace = self::seconds();
        $uploaded = get_post_timestamp($attachmentId);

        // The same three-part test RecentUploadDetector makes, read from the
        // other end: it decides whether the reference is created, this decides
        // whether the reference still means what it says.
        return $grace > 0 && $uploaded !== false && time() - $uploaded < $grace;
    }

    /**
     * How a held-back fresh upload reads wherever one file is named — the
     * listing cell, the Media Library badge, and any later surface that has to
     * say why a file it is showing is not on offer.
     */
    public static function heldBack(): string
    {
        return sprintf(
            /* translators: %s: the grace window, e.g. "24 hours" */
            __('Held back — uploaded in the last %s', 'freshet-unused-media'),
            self::window()
        );
    }
}

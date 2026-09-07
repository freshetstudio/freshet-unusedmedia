<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * The generated size files sitting beside an attachment on disk.
 *
 * Everything else in this plugin asks the database what an attachment's files
 * are called, and `_wp_attachment_metadata` is the only record of a generated
 * size. That record is rewritten wholesale whenever thumbnails are regenerated
 * — after a theme change, an import from a site with different sizes, an
 * offload plugin rebuilding metadata — and a size that has stopped being
 * registered simply drops out of it. The file it generated stays on disk.
 *
 * At that moment the file is still on live pages (an `<img src>` written when
 * the size existed) and is no longer one of the attachment's basenames, so the
 * reference stops resolving and the original scans unused while a page displays
 * it. That is the one failure this plugin exists to prevent, so the disk is
 * read: whatever metadata says, a `stem-WxH.ext` file next to the original is
 * one of that original's names.
 *
 * Two boundaries hold this in place.
 *
 * - **Own stem only.** A sibling counts only when the part before `-WxH` is
 *   *this* attachment's stem, compared whole. `hero.jpg` and `hero-1.jpg` are
 *   two uploads, and `hero-1-300x200.jpg` belongs to the second one — admitting
 *   it for the first would attach a reference to the wrong file.
 * - **One directory at a time.** The listing is remembered for a single
 *   directory, so a batch of attachments from the same month reads it once, and
 *   nothing is held across batches (flush()).
 */
final class SizeSiblings
{
    /** The directory whose listing is remembered. Exactly one, ever. */
    private static string $dir = '';

    /** @var string[] Entry names in that directory. */
    private static array $listing = [];

    /** Directories this request has read. */
    private static int $read = 0;

    /** Directories this request could not read. See takeDirectoryReads(). */
    private static int $unread = 0;

    /**
     * The names in $dir, or an empty list when it cannot be read.
     *
     * An unreadable directory is not an error here: media is routinely
     * offloaded, and a scan that failed because one month's folder is gone
     * would be worse than one that finds nothing there.
     *
     * Entries are not stat'ed. A stat per file is the expensive part of a
     * directory read and it would buy nothing — a *directory* named
     * `stem-640x480.jpg` is the only thing it could exclude, and the one caller
     * that acts on a file rather than matching a name checks for itself.
     *
     * @return string[]
     */
    public static function listing(string $dir): array
    {
        if ($dir === self::$dir) {
            return self::$listing;
        }

        self::$dir = $dir;
        self::$listing = [];

        if ($dir === '') {
            return [];
        }

        // Suppressed for the same reason scandir() is on the line below, and it
        // has to be: `is_dir()` on a path whose scheme no stream wrapper claims
        // — `s3://…`, which is how an offload plugin filters get_attached_file()
        // — emits `Unable to find the wrapper` before returning false. That is
        // one warning per directory, and with display_errors on it prints into
        // the body of an AJAX response, ahead of the JSON the scan screen is
        // waiting for. A scheme test instead of the @ was the alternative and is
        // the wrong instrument twice over: it would not cover the other paths
        // that warn here (an open_basedir restriction, a permissions failure, a
        // mount that has gone away), and a registered wrapper is not a failure
        // at all — an offload plugin that keeps its wrapper active reads its own
        // remote directory through this call and the disk read simply works.
        // What matters is the answer, not the shape of the path.
        if (!@is_dir($dir)) {
            ++self::$unread;

            return [];
        }

        $entries = @scandir($dir);

        if ($entries === false) {
            ++self::$unread;

            return [];
        }

        ++self::$read;

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::$listing[] = $entry;
            }
        }

        return self::$listing;
    }

    /**
     * How many directories this run has read, and how many it could not.
     *
     * The tally exists because an unreadable directory is silent by design
     * (see listing()) and that silence is indistinguishable from a directory
     * with nothing in it — so a library whose files are not on this server gets
     * none of the protection this class is here to give, and every screen goes
     * on looking exactly as it does for a library that has it. The counts are
     * what lets the scan say so once, in its own summary, instead of not at all
     * (freshet-144).
     *
     * Directories rather than attachments on purpose: the listing is read once
     * per directory and a month's worth of uploads shares one, so per-attachment
     * figures would say the same thing multiplied by an arbitrary number.
     *
     * These are counts of *reads*, not of distinct directories, and the
     * difference is why nothing quotes them as a number. Only one listing is
     * remembered at a time, so a run whose attachments alternate between
     * directories reads one of them more than once, and a directory that
     * straddles a batch boundary is read again in the next request. What is
     * exact either way is whether each count is zero — which is all the two
     * things that read this ask: did any directory fail, and did any succeed.
     *
     * Read-and-reset, so the caller adds a delta to the run's own total. Not
     * folded into flush(): the two callers flush on either side of where they
     * record the batch, and a tally that depended on that order would quietly
     * halve or double.
     *
     * @return array{read: int, unread: int}
     */
    public static function takeDirectoryReads(): array
    {
        $tally = ['read' => self::$read, 'unread' => self::$unread];

        self::$read = 0;
        self::$unread = 0;

        return $tally;
    }

    /** Drop the remembered listing. Called at every batch boundary. */
    public static function flush(): void
    {
        self::$dir = '';
        self::$listing = [];
    }

    /**
     * The stem core cuts an original's sizes from: `hero-scaled.jpg` and
     * `hero.jpg` are both `hero`, because the sizes of a big image are named
     * after the image, not after the scaled copy of it.
     *
     * `-WIDTHxHEIGHT` is deliberately NOT stripped here, and that is the one
     * thing to get wrong. AttachmentContext strips it elsewhere for a different
     * purpose — recovering the name of a hand-written URL's original — but a
     * *stem* is what generated names are built from, and an upload that arrives
     * already called `photo-1024x768.jpg` has its sizes built from the whole of
     * that: `photo-1024x768-300x200.jpg`. Stripping the dimensions would make
     * that upload claim every `photo-*x*.jpg` in the directory, which is a
     * second upload's family of files. Measured rather than reasoned: of the
     * attachments on two production libraries whose own filename ends in
     * dimensions, every one has its sizes named from the whole basename, and
     * every `-scaled`/`-rotated` attachment has them named from the stripped
     * stem.
     */
    public static function stem(string $basename): string
    {
        $name = (string) preg_replace('/-(?:scaled|rotated)(\.[a-z0-9]+)$/i', '$1', $basename);
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, 0, $dot);
    }

    /**
     * The stem a generated size was cut from, or '' when the name is not one.
     *
     * The shape is core's own: the original's whole name, `-WIDTHxHEIGHT`, then
     * the extension — with one more extension allowed after it, because an
     * alternate-mime copy is written as `hero-300x200.jpg.webp`. Both ends are
     * fixed (the stem is matched whole against the digits, the extension
     * terminates the name), which is what keeps `hero` from claiming
     * `hero-1-300x200.jpg`: that name's stem is `hero-1`, and the two are
     * compared as strings rather than as prefixes.
     */
    public static function sizeStem(string $basename): string
    {
        return preg_match('/^(.+)-\d+x\d+(?:\.[a-z0-9]{1,6}){1,2}$/i', $basename, $matches) === 1
            ? $matches[1]
            : '';
    }

    /** The last extension of a name, lowercased: `hero-300x200.jpg` is `jpg`. */
    public static function extension(string $basename): string
    {
        return strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));
    }

    /**
     * Is $candidate a size generated from this stem, in this format?
     *
     * The extension is part of the boundary and not decoration. A library holds
     * `doc.pdf` and `doc.jpg` as two separate uploads often enough to matter,
     * and `doc-300x200.jpg` is the second one's. One further extension is
     * allowed after the format, for the alternate-mime copies core writes as
     * `hero-300x200.jpg.webp`.
     */
    public static function isSibling(string $candidate, string $stem, string $extension): bool
    {
        if ($stem === '') {
            return false;
        }

        $format = $extension === '' ? '[a-z0-9]{1,6}' : preg_quote($extension, '/');

        return (bool) preg_match(
            '/^' . preg_quote($stem, '/') . '-\d+x\d+\.' . $format . '(?:\.[a-z0-9]{1,6})?$/i',
            $candidate
        );
    }

    /**
     * How core names this attachment's generated sizes: [stem, extension].
     *
     * Read off the attachment's own metadata where there is any, because that
     * is core's record of what it actually wrote — rather than a table of rules
     * about what it would write. A PDF is the case that makes the difference:
     * its previews are `doc-pdf-300x169.jpg` beside `doc.pdf`, so neither the
     * stem nor the format can be inferred from the attached file's name.
     *
     * The fallback, for an attachment whose metadata lists no sizes at all, is
     * the attached file's own stem and format — right for an image, and for a
     * document it simply finds nothing, which is the safe way to be wrong.
     *
     * @return array{0: string, 1: string}
     */
    public static function naming(string $path, mixed $meta = null): array
    {
        $base = wp_basename($path);

        foreach ((array) (is_array($meta) ? ($meta['sizes'] ?? []) : []) as $size) {
            if (!is_array($size) || empty($size['file'])) {
                continue;
            }

            $name = wp_basename((string) $size['file']);
            $stem = self::sizeStem($name);

            if ($stem !== '') {
                return [$stem, self::extension($name)];
            }
        }

        return [self::stem($base), self::extension($base)];
    }

    /**
     * Every size file on disk that belongs to this attachment.
     *
     * $path is the attachment's file as `get_attached_file()` reports it, so a
     * site that filters that path (an offload plugin) is read where it says its
     * files are, not where core would have put them.
     *
     * @param array<string, mixed>|false|null $meta The attachment's metadata.
     * @return string[] Basenames, in directory order.
     */
    public static function forFile(string $path, mixed $meta = null): array
    {
        [$stem, $extension] = self::naming($path, $meta);

        if ($stem === '') {
            return [];
        }

        $names = [];

        foreach (self::listing(dirname($path)) as $entry) {
            if (self::isSibling($entry, $stem, $extension)) {
                $names[] = $entry;
            }
        }

        return $names;
    }
}

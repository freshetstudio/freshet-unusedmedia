<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * Which attachments a row's filenames could be naming — the inverted half of
 * `LikePatterns::containsBasename()`.
 *
 * **This class exists for one failure mode, and it is the only `used → unused`
 * direction in the reference index.** `containsBasename()` is a raw
 * `str_contains()`, so a stored value spelling `foo-hero.jpg` counts today as a
 * reference to `hero.jpg`. The index reads each row once and has to recognise
 * the filenames in it, so an index keyed on the filenames it *saw* holds
 * `foo-hero.jpg` and answers nothing when `hero.jpg` is looked up. That is a
 * file on a page reading as unused, which freshet-D116 isolated as the one
 * direction that is not a trade.
 *
 * So a basename is matched as a **suffix** of a token rather than as its equal.
 *
 * **What it costs, measured rather than costed** (freshet-365). §2d of
 * `_unusedmedia-1-1.md` priced this at *"2,920–3,659 tokens, 0.12 MB"*, which is
 * the count of tokens found **in content**; a suffix lookup needs every basename
 * **in the library**. On a 27,140-attachment library `basenamesFor()` yields
 * **961,262 folded names, 329,229 of them distinct**, and the first cut of this
 * class held them as string keys (60 MB) plus a list per attachment (94 MB):
 * **126 MB of structures inside a 128 MB `WP_MEMORY_LIMIT`**, which fatalled the
 * pass mid-request. What is stored now is **one integer per distinct basename**,
 * 20.8 MB on the same library, and the per-attachment lists are gone from here
 * entirely — `ReferenceIndexer::context()` reads them per candidate instead.
 * The whole pass went from a fatal to a 105.3 MB peak.
 *
 * **Completeness, not precision, is what this owes.** A token match makes a
 * (row, attachment) pair a *candidate*; the detector's own chain then runs
 * `containsBasename()` against the real value and decides. So an extra match
 * costs one rejected pair and a missed one costs a deleted file. Everything
 * below is written for the first error and against the second:
 *
 * - **A token is emitted at every extension occurrence**, not once per filename
 *   run. `foo-hero.jpg.webp` yields both `foo-hero.jpg` and `foo-hero.jpg.webp`,
 *   because `hero.jpg` is a substring of that value and a suffix of only the
 *   first of those two.
 * - **No right-hand boundary.** `hero.jpgx` emits `hero.jpg`, because
 *   `str_contains()` finds `hero.jpg` in it and this must not be the one place
 *   that disagrees.
 * - **A space does not end a token.** `sanitize_file_name()` would never leave
 *   one in an upload, but an imported `_wp_attached_file` can, and the literal
 *   spelling then appears in content. What bounds a token instead is the longest
 *   basename in the vocabulary: nothing longer than that can be one, so nothing
 *   longer is kept.
 * - **The extension set comes from the library**, not from a list written here:
 *   `wp_get_mime_types()` for what WordPress accepts, plus every extension the
 *   basenames actually carry, so a filter's upload type or an imported file
 *   cannot fall outside it.
 * - **A hash collision is the harmless error too.** The keys are integers (see
 *   `key()`), so two different basenames can in principle land on one, and the
 *   cost is a candidate the chain rejects — never a lookup that misses. What a
 *   collision must not do is *lose* an attachment, which is why a key holds every
 *   id put under it (see `packIds()`) rather than the last one.
 *
 * **Why that is enough** — the argument `tests/basename-vocabulary.php` then
 * checks by brute force rather than by example. If basename `B` occurs in a
 * value ending at byte `p`, then `B`'s own extension ends at `p`, so the scan
 * below matches there and emits the token ending at `p`. `B` holds no character
 * that ends a token and is no longer than the cap, so `B` is a suffix of that
 * token and the lookup finds it.
 */
final class BasenameVocabulary
{
    /**
     * key() of a folded basename => the attachment rows it belongs to,
     * `packIds()`-encoded.
     *
     * **An integer key and an integer value, because the strings are what cost.**
     * Held as string keys this array was 60 MB on a 27,140-attachment library's
     * 329,229 distinct basenames; keyed by `key()` it is **20.8 MB and holds no
     * filename at all**. Nothing reads a basename back out of here — the chain
     * gets its names from `AttachmentContext::basenamesFor()` — so storing them
     * was storing a quarter of a million strings to answer a question nobody
     * asked.
     *
     * **And a shared basename is the common case, not the exception.** That
     * library holds its 27,140 attachments under **9,284 distinct filenames** —
     * every file about three times over, which is what a library carries after a
     * few imports — so 296,730 of its 329,229 entries named more than one
     * attachment. Spelled as the comma-joined string this started as, that was
     * **12.7 MB** of `zend_string` headers for 5 MB of digits. Up to three ids
     * therefore live inside the value itself, which leaves **12,365 entries on
     * the string fallback** — a fourth sharer, or an id too large to pack (see
     * `packIds()`).
     *
     * @var array<int, int|string>
     */
    private array $byBasename = [];

    /**
     * The distinct byte lengths of the basenames above, as a set — the only
     * substring lengths a lookup needs to try. Sorted into $ascending on use.
     *
     * @var array<int, true>
     */
    private array $lengths = [];

    /** @var int[]|null Ascending $lengths, rebuilt when a new length appears. */
    private ?array $ascending = null;

    private int $maxLength = 0;

    /**
     * Folded extensions, without the dot, a token may be anchored on.
     *
     * @var array<string, true>
     */
    private array $extensions = [];

    /** The longest extension in the set, which caps the run read after a dot. */
    private int $maxExtensionLength = 0;

    /** Resolved once, exactly as LikePatterns::containsBasename() resolves it. */
    private static ?bool $foldsUnicodeCase = null;

    /**
     * Characters that cannot occur inside a basename, so a token stops at them.
     *
     * `wp_basename()` splits on `/` and `\`, so neither is ever inside one —
     * except that the `\u`-escaped spelling `AttachmentContext` adds is all
     * backslashes, which is why only the forward slash is here. The quotes and
     * angle brackets are the markup delimiters a filename is written between;
     * the control characters are where a line of stored markup is broken.
     */
    private const STOPS = "/\"'<>\r\n\t\0";

    public function __construct()
    {
        self::$foldsUnicodeCase ??= function_exists('mb_strtolower') && function_exists('mb_check_encoding');
    }

    /**
     * The stored key for one folded basename of a known length.
     *
     * **The length is in the key, and that is what makes the collision argument
     * trivial.** A lookup only ever probes a suffix against the lengths the
     * vocabulary actually holds, so every probe already knows the length it is
     * asking about; pairing it with `crc32()` means two basenames can only
     * collide if they are the **same length** and share a CRC. On a vocabulary
     * of 329,229 names across 119 distinct lengths that is a fraction of one
     * expected collision — and a collision offers an extra candidate the
     * detector chain then rejects, which is the direction this class is allowed
     * to err in.
     *
     * `crc32()` rather than a wider digest because this is the hot path: it is
     * one native call per probed length per token, on every row of eight tables.
     */
    private function key(string $folded, int $length): int
    {
        return ($length << 32) | crc32($folded);
    }

    /** Bits per packed attachment id, and the ids that therefore fit. */
    private const SHARE_BITS = 20;
    private const SHARE_MASK = (1 << self::SHARE_BITS) - 1;

    /** How many ids fit beside the two-bit count in one 62-bit integer. */
    private const SHARE_MAX = 3;

    /**
     * The attachments one basename names, as one value.
     *
     * An `int` holds the count in its low two bits and up to `SHARE_MAX` ids
     * above them, twenty bits each: 62 bits in all, so it is a PHP integer on
     * every platform this plugin runs on and costs nothing but the array slot it
     * already needs. A fourth sharer, or any id of a million or more, does not
     * fit and falls back to the comma-joined string — which is the shape this
     * started as, so the fallback is not a new path so much as the old one kept
     * for the cases it is still needed for.
     *
     * @param int[] $ids
     */
    private function packIds(array $ids): int|string
    {
        if (count($ids) > self::SHARE_MAX) {
            return implode(',', $ids);
        }

        $packed = count($ids);
        $at = 2;

        foreach ($ids as $id) {
            if ($id < 0 || $id > self::SHARE_MASK) {
                return implode(',', $ids);
            }

            $packed |= $id << $at;
            $at += self::SHARE_BITS;
        }

        return $packed;
    }

    /**
     * The ids back out of either shape.
     *
     * @return int[]
     */
    private function unpackIds(int|string $held): array
    {
        if (is_string($held)) {
            return array_map('intval', explode(',', $held));
        }

        $ids = [];

        for ($left = $held & 3, $at = 2; $left > 0; --$left, $at += self::SHARE_BITS) {
            $ids[] = ($held >> $at) & self::SHARE_MASK;
        }

        return $ids;
    }

    /**
     * Add one attachment's basenames.
     *
     * @param string[] $basenames From AttachmentContext::basenamesFor().
     */
    public function add(int $attachmentId, array $basenames): void
    {
        foreach ($basenames as $name) {
            if ($name === '') {
                continue;
            }

            // Both folds LikePatterns::containsBasename() can compare with, and
            // only where it reaches for the second: the ASCII fold always, the
            // Unicode one when the name itself carries a non-ASCII byte. Pairing
            // the same two folds here is what keeps the vocabulary a superset of
            // what that predicate answers true for.
            $this->remember(strtolower($name), $attachmentId);

            if (self::$foldsUnicodeCase && !mb_check_encoding($name, 'ASCII')) {
                $this->remember(mb_strtolower($name, 'UTF-8'), $attachmentId);
            }
        }
    }

    private function remember(string $folded, int $attachmentId): void
    {
        if ($folded === '') {
            return;
        }

        $length = strlen($folded);
        $key = $this->key($folded, $length);

        if (!isset($this->byBasename[$key])) {
            if (!isset($this->lengths[$length])) {
                $this->lengths[$length] = true;
                $this->ascending = null;
                $this->maxLength = max($this->maxLength, $length);
            }

            $this->rememberExtension(strtolower((string) pathinfo($folded, PATHINFO_EXTENSION)));

            $this->byBasename[$key] = $this->packIds([$attachmentId]);

            return;
        }

        // A file uploaded twice, a translation copy, or — on a library that has
        // been imported more than once — simply the ordinary case. Linear, and it
        // has to be: the list is a handful of ids long and a set per basename is
        // the allocation this storage exists to avoid.
        $ids = $this->unpackIds($this->byBasename[$key]);

        if (in_array($attachmentId, $ids, true)) {
            return;
        }

        $ids[] = $attachmentId;

        $this->byBasename[$key] = $this->packIds($ids);
    }

    /** Whether anything was added at all — an empty library names no tokens. */
    public function isEmpty(): bool
    {
        return $this->byBasename === [];
    }

    /** How many distinct basename keys the vocabulary holds. */
    public function size(): int
    {
        return count($this->byBasename);
    }

    /**
     * Seed the extension set with every type WordPress accepts.
     *
     * Called before the library's own basenames are added, so a token can be
     * anchored on an extension no attachment happens to carry — a value naming a
     * file that has since been deleted, or one spelled with the other half of a
     * pair (`jpeg` where the library only has `jpg`). It only widens what is
     * tokenised, and the suffix lookup still decides.
     */
    public function seedWordPressExtensions(): void
    {
        foreach (array_keys(wp_get_mime_types()) as $group) {
            // Core keys that map by alternation: 'jpg|jpeg|jpe' is one entry.
            foreach (explode('|', (string) $group) as $extension) {
                $this->rememberExtension(strtolower(trim($extension)));
            }
        }
    }

    /**
     * Every filename-shaped token in one stored value — one per extension
     * occurrence, in the spelling the value carries.
     *
     * **Occurrences overlap, and that is the point.** A single regex over an
     * alternation of the extensions returns non-overlapping matches, so
     * `x.jpeg` would yield only `x.jpeg` and a library holding `x.jpe` — a
     * spelling `wp_get_mime_types()` ships — would lose the reference. Reading
     * the run after each dot and emitting a token for **every prefix of it that
     * is a known extension** is what closes that: `x.jpeg` yields `x.jpe` and
     * `x.jpeg` both. Dots are cheap to find and the run is at most a few bytes,
     * so this is also the faster of the two.
     *
     * @return string[] De-duplicated, in no particular order.
     */
    public function tokensIn(string $text): array
    {
        if ($text === '' || $this->maxLength === 0 || $this->extensions === []) {
            return [];
        }

        $length = strlen($text);
        $tokens = [];
        $at = 0;

        while (($dot = strpos($text, '.', $at)) !== false) {
            $at = $dot + 1;

            // The alphanumeric run after the dot, no longer than the longest
            // extension on file. A dot in prose is followed by a space or a
            // capital and stops here, which is what keeps this cheap on text.
            $run = '';
            $stop = min($length, $dot + 1 + $this->maxExtensionLength);

            for ($i = $dot + 1; $i < $stop; ++$i) {
                $character = $text[$i];

                if (
                    ($character < '0' || $character > '9')
                    && ($character < 'a' || $character > 'z')
                    && ($character < 'A' || $character > 'Z')
                ) {
                    break;
                }

                $run .= $character;
            }

            if ($run === '') {
                continue;
            }

            $folded = strtolower($run);

            for ($take = strlen($folded); $take >= 1; --$take) {
                if (!isset($this->extensions[substr($folded, 0, $take)])) {
                    continue;
                }

                $token = $this->tokenEndingAt($text, $dot, $dot + 1 + $take);

                if ($token !== '') {
                    $tokens[$token] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * The token whose extension's dot is at $dot and which ends at $end.
     *
     * Back to the nearest character a basename cannot hold, or to the cap —
     * nothing longer than the longest basename on file can be one. A reversed
     * copy and strcspn() would allocate per occurrence, and this runs on every
     * row of eight tables.
     */
    private function tokenEndingAt(string $text, int $dot, int $end): string
    {
        $start = max(0, $end - $this->maxLength);

        for ($at = $dot - 1; $at >= $start; --$at) {
            if (strpos(self::STOPS, $text[$at]) !== false) {
                $start = $at + 1;
                break;
            }
        }

        return substr($text, $start, $end - $start);
    }

    /**
     * The attachments one token could be naming.
     *
     * Every suffix of the token that is a basename in the vocabulary, which is
     * the whole of the seam this class closes: `foo-hero.jpg` answers for
     * `hero.jpg` exactly as `str_contains()` does.
     *
     * @return int[] Ascending, de-duplicated.
     */
    public function attachmentsNamedBy(string $token): array
    {
        if ($token === '' || $this->byBasename === []) {
            return [];
        }

        $folds = [strtolower($token) => true];

        // The second fold, where the token carries a non-ASCII byte — the same
        // gate add() applies to a basename, so an accented filename is matched
        // under the fold it was stored under.
        if (self::$foldsUnicodeCase && !mb_check_encoding($token, 'ASCII')) {
            $folds[mb_strtolower($token, 'UTF-8')] = true;
        }

        $lengths = $this->ascending ??= $this->sortedLengths();
        $found = [];

        foreach (array_keys($folds) as $candidate) {
            $length = strlen($candidate);

            foreach ($lengths as $take) {
                if ($take > $length) {
                    break; // Ascending, so nothing further fits either.
                }

                $suffix = $take === $length ? $candidate : substr($candidate, -$take);

                $held = $this->byBasename[$this->key($suffix, $take)] ?? null;

                if (is_int($held)) {
                    // packIds() inlined: this is the innermost loop of the pass,
                    // and the alternative is an array allocated per hit.
                    for ($left = $held & 3, $at = 2; $left > 0; --$left, $at += self::SHARE_BITS) {
                        $found[($held >> $at) & self::SHARE_MASK] = true;
                    }
                } elseif (is_string($held)) {
                    foreach (explode(',', $held) as $attachmentId) {
                        $found[(int) $attachmentId] = true;
                    }
                }
            }
        }

        $ids = array_keys($found);
        sort($ids);

        return $ids;
    }

    /**
     * Every attachment any token in this value could be naming.
     *
     * @return int[] Ascending, de-duplicated.
     */
    public function attachmentsNamedIn(string $text): array
    {
        $found = [];

        foreach ($this->tokensIn($text) as $token) {
            foreach ($this->attachmentsNamedBy($token) as $attachmentId) {
                $found[$attachmentId] = true;
            }
        }

        $ids = array_keys($found);
        sort($ids);

        return $ids;
    }

    /** @return int[] */
    private function sortedLengths(): array
    {
        $lengths = array_keys($this->lengths);
        sort($lengths);

        return $lengths;
    }

    /** One extension the token scan may anchor on. */
    private function rememberExtension(string $extension): void
    {
        if ($extension === '' || isset($this->extensions[$extension])) {
            return;
        }

        $this->extensions[$extension] = true;
        $this->maxExtensionLength = max($this->maxExtensionLength, strlen($extension));
    }
}

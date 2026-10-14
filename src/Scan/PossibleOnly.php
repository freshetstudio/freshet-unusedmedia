<?php

declare(strict_types=1);

namespace FreshetUnusedMedia\Scan;

defined('ABSPATH') || exit;

/**
 * A file nothing confirms and something might.
 *
 * The verdict does not change: a possible match keeps the file, as it always
 * has, and that is the direction to be wrong in. What changes is the sentence.
 * "Used (213 references)" over a file whose 213 references are 206 cache records
 * and seven numbers that happen to equal its id is not a count of anything a
 * person can go and look at — it is the one reading that made freshet-380's
 * library distrust the screen. A file in this state is **held back**, like the
 * three other things this plugin protects a file for, and it says so in the
 * settled phrasing (freshet-D95).
 *
 * The peer of FileClaims::holdsAlone() and UploadGrace::holdsAlone(), and
 * deliberately the last of the three to be asked: the upload grace and a shared
 * file name a reason a person can act on today, while this one is a statement
 * about the evidence.
 */
final class PossibleOnly
{
    /**
     * Is "nothing confirmed it" the only reason this file is being kept?
     *
     * **Truncation cannot fool it, which is why it reads the counters.** The two
     * sibling tests have to refuse to answer when the stored reference list is
     * short, because they ask what the kept references *are*; this asks how the
     * whole set *split*, which ResultStore counts over every reference before it
     * throws any away. A file with twenty stored references out of 212 is
     * exactly the file this question is about.
     *
     * @param array{count: int, confirmed: int, possible: int, refs: Reference[]} $data
     *        exactly what ResultStore::refs() returns
     */
    public static function holdsAlone(array $data): bool
    {
        return $data['confirmed'] === 0 && $data['possible'] > 0;
    }

    /**
     * How such a file reads wherever one file is named — the listing cell and
     * the Media Library badge.
     *
     * "Held back — <why>", em dash, lower case, no alarm, like its three
     * siblings (freshet-D95). The why is the whole content: a person who sees
     * this knows the file is not on offer, knows no page was found using it, and
     * knows the evidence list under it is a list of guesses rather than of
     * places.
     */
    public static function heldBack(): string
    {
        return __('Held back — nothing confirmed, only possible matches', 'freshet-unused-media');
    }

    /**
     * The line that introduces the possible matches themselves, wherever they
     * are listed.
     *
     * One sentence, and it does three things D287 allows a sentence to do: it
     * gives the figure, it says what the figure is of, and it says what to do
     * with it. The alternative was a bare count, which leaves a reader to work
     * out why a file with 206 references is not called used.
     *
     * @param int $possible How many there are.
     */
    public static function explanation(int $possible): string
    {
        return sprintf(
            /* translators: %s: number of possible matches */
            _n(
                '%s possible match — a number or a stored value that could be this file\'s ID, or could be something else. Worth a look before deleting.',
                '%s possible matches — numbers and stored values that could be this file\'s ID, or could be something else. Worth a look before deleting.',
                $possible,
                'freshet-unused-media'
            ),
            number_format_i18n($possible)
        );
    }
}

<?php

declare(strict_types=1);

/**
 * Scan-progress smoke test — the arithmetic behind what a running scan says.
 *
 * Pure PHP: no WordPress, no database, no test framework. Run it with:
 *
 *     php tests/scan-progress.php
 *
 * What it guards is a screen that misinforms rather than one that lies: every
 * figure here is a sentence someone reads while deciding whether to wait, and
 * the ways it can go wrong are all quiet. An estimate built on the wall clock
 * tells a person resuming yesterday's scan that a thousand files need a week.
 * A share rounded off a total of zero is a division by zero on a library small
 * enough that no detector measured. A truncated ranking with no ellipsis says
 * five checks were all of them.
 *
 * The accumulation itself (ScanState) and the measurement (Scanner) are not
 * here — they need an option store and a database. What is here is every
 * decision made on those numbers once they exist.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
// ABSPATH is defined below rather than checked, so the guard is on the SAPI.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

// --------------------------------------------------------------- WP stubs
//
// The four translation and formatting functions these classes touch. They are
// stubbed to the identity so the assertions below read as the English the
// plugin ships; a site in another language substitutes its own strings through
// exactly these calls.

function __(string $text, string $domain = ''): string
{
    return $text;
}

function _n(string $single, string $plural, int $number, string $domain = ''): string
{
    return $number === 1 ? $single : $plural;
}

function number_format_i18n(float|int $number, int $decimals = 0): string
{
    return number_format((float) $number, $decimals);
}

require_once ABSPATH . 'src/Scan/DetectorTiming.php';
require_once ABSPATH . 'src/Scan/ScanProgress.php';

use FreshetUnusedMedia\Scan\DetectorTiming;
use FreshetUnusedMedia\Scan\ScanProgress;

$passed = 0;
$failures = [];

function check(string $label, mixed $actual, mixed $expected): void
{
    global $passed, $failures;

    if ($actual === $expected) {
        ++$passed;

        return;
    }

    $failures[] = sprintf('%s — expected %s, got %s', $label, var_export($expected, true), var_export($actual, true));
}

// ------------------------------------------------------------------- ETA

// 100 of 1,000 in 10 seconds is 0.1s each, so 900 left is 90 seconds.
check('the estimate is the rate so far over what is left', ScanProgress::etaSeconds(100, 1000, 10.0), 90);

// The three shapes that have no rate to extrapolate from. Each is a real
// moment: the first batch has not landed, the clock has not moved, the
// library shrank under a long run or the total was read as zero.
check('no estimate before a batch has landed', ScanProgress::etaSeconds(0, 1000, 0.0), null);
check('no estimate without time spent', ScanProgress::etaSeconds(100, 1000, 0.0), null);
check('no estimate when nothing is left', ScanProgress::etaSeconds(1000, 1000, 10.0), null);
check('no estimate when the total is behind the count', ScanProgress::etaSeconds(1000, 900, 10.0), null);

// The whole reason ScanState carries `elapsed` rather than the screen
// subtracting `started_at` from now. Both numbers below describe one scan of
// 100 files: ten seconds of scanning, and the twelve hours since it was
// started and left overnight. The second is what a wall-clock estimate would
// extrapolate from, and it is four and a half days of "about … left".
check(
    'scanning time and wall clock are not the same estimate — ScanState hands over the first',
    [ScanProgress::etaSeconds(100, 1000, 10.0), ScanProgress::etaSeconds(100, 1000, 43210.0)],
    [90, 388890]
);

// -------------------------------------------------------------- durations

check('seconds', ScanProgress::human(8), '8 seconds');
check('one second is singular', ScanProgress::human(1), '1 second');
check('under a minute stays in seconds', ScanProgress::human(59), '59 seconds');
check('a minute', ScanProgress::human(60), '1 minute');
check('minutes round', ScanProgress::human(150), '3 minutes');
check('an hour', ScanProgress::human(3600), '1 hour');
check('hours and minutes', ScanProgress::human(7800), '2 hours 10 minutes');

// 59 minutes 40 seconds rounds its minutes to 60, which must carry rather than
// print an hour with sixty minutes in it.
check('a rounded hour carries', ScanProgress::human(3580), '1 hour');

check('nothing negative reaches the words', ScanProgress::human(-5), '0 seconds');

// ----------------------------------------------------------------- shares

$timing = [
    'post-content' => 4.1,
    'postmeta' => 3.3,
    'options' => 1.6,
    'comment' => 1.0,
];

check('shares are whole percents, largest first', ScanProgress::shares($timing), [
    'post-content' => 41,
    'postmeta' => 33,
    'options' => 16,
    'comment' => 10,
]);

// A run too short to measure, and a cursor written by a version that recorded
// no timing at all: both are the same empty answer rather than a division by
// zero or a row of noughts.
check('nothing measured is no shares', ScanProgress::shares([]), []);
check('a total of zero is no shares', ScanProgress::shares(['postmeta' => 0.0]), []);

// A detector that cost a thousandth of the run is dropped rather than listed
// at 0%: a share of nothing reads as a defect.
check('a share that rounds to nothing is dropped', ScanProgress::shares(['postmeta' => 100.0, 'comment' => 0.1]), ['postmeta' => 100]);

check('the busiest detector is the one the time went to', ScanProgress::busiest($timing), 'post-content');
check('nothing measured has no busiest', ScanProgress::busiest([]), null);

// --------------------------------------------------------------- the list

check(
    'the ranking is named in the plugin\'s words',
    ScanProgress::breakdown($timing),
    'post content 41%, custom fields 33%, options and theme mods 16%, comments 10%'
);

// Cut short, and saying so. A reader who sees a list with no tail is entitled
// to believe it was the whole of it.
check(
    'a truncated ranking ends in an ellipsis',
    ScanProgress::breakdown($timing, 2),
    'post content 41%, custom fields 33%, …'
);

check('nothing measured prints nothing at all', ScanProgress::breakdown([]), '');

// A detector another plugin added through `freshet_unusedmedia_detectors` has
// no label here. It is named by its own id rather than dropped: a run whose
// time went somewhere unnamed still says where.
check(
    'a third-party detector is named by its id',
    ScanProgress::breakdown(['acme-slider' => 1.0]),
    'acme-slider 100%'
);

// ---------------------------------------------------------------- the line

check(
    'the whole line while a scan runs',
    ScanProgress::label(100, 1000, 10.0, $timing),
    '100 / 1,000 attachments — 10 seconds so far, about 2 minutes left · checking post content'
);

// The first batch has landed but the numbers say nothing can be extrapolated —
// the line still carries what it does know rather than waiting for all four.
check(
    'no estimate yet, and the line says the rest',
    ScanProgress::label(0, 1000, 0.0, $timing),
    '0 / 1,000 attachments — 0 seconds so far · checking post content'
);

check(
    'a batch that measured nothing drops the check, not the figures',
    ScanProgress::label(100, 1000, 10.0, []),
    '100 / 1,000 attachments — 10 seconds so far, about 2 minutes left'
);

check(
    'and with neither',
    ScanProgress::label(0, 0, 0.0, []),
    '0 / 0 attachments — 0 seconds so far'
);

// ------------------------------------------------------------ the tally

DetectorTiming::flush();
DetectorTiming::add('postmeta', 1.5);
DetectorTiming::add('postmeta', 0.5);
DetectorTiming::add('comment', 0.25);

check('a detector\'s seconds accumulate across rows', DetectorTiming::take(), ['postmeta' => 2.0, 'comment' => 0.25]);

// take() is the batch boundary: what it handed back is not handed back twice,
// or every batch would report the whole run's time as its own.
check('taking the tally clears it', DetectorTiming::take(), []);

// A clock that steps backwards mid-batch — an NTP correction, a container
// resuming — must not subtract from a total that is about to be a percentage.
DetectorTiming::add('postmeta', -3.0);

check('a backwards clock contributes nothing', DetectorTiming::take(), ['postmeta' => 0.0]);

check('an unknown id is its own label', DetectorTiming::label('acme-slider'), 'acme-slider');
check('a known id is a phrase a person reads', DetectorTiming::label('file-claim'), 'other library entries on the same file');

// -------------------------------------------------------- the resume label
//
// freshet-304. The Resume button was the one figure on the screen the browser
// could not rewrite: PHP printed "Resume scan (130 / 3,466)" at page load, the
// batch reply carried no text for it, and nothing in admin.js touched it — so
// stopping a scan at 170 left a button offering to resume from 130 until
// someone reloaded the page. The fix is the plugin's own idiom, the one this
// file's subject already is: the sentence is composed on the server and the
// browser only puts it where it goes.
//
// Four claims, and they are different. The first is the wording. The next
// three are structural — that the one composer is what the screen prints, that
// it rides the batch reply, and that the script writes it into the button —
// because the defect was never in the arithmetic. Every figure was right; they
// just never travelled.

check('the button says what it will resume, in the site\'s number format', ScanProgress::resumeLabel(170, 3466), 'Resume scan (170 / 3,466)');

// The moment the stop path has to be right about: a batch that finished
// exactly on the total, and a run that has not started one. Neither is a
// special case in the composer, and neither should read as one.
check('a run that reached the end still reads as a count', ScanProgress::resumeLabel(3466, 3466), 'Resume scan (3,466 / 3,466)');
check('and one that has scanned nothing', ScanProgress::resumeLabel(0, 3466), 'Resume scan (0 / 3,466)');

$progressSource = (string) file_get_contents(ABSPATH . 'src/Scan/ScanProgress.php');
$toolsSource = (string) file_get_contents(ABSPATH . 'src/Admin/ToolsPage.php');
$ajaxSource = (string) file_get_contents(ABSPATH . 'src/Admin/Ajax.php');
$adminScript = (string) file_get_contents(ABSPATH . 'assets/admin.js');

// One msgid, in one place. A second `sprintf` of the same sentence in
// ToolsPage is how the two halves drift apart again — and how a translator
// ends up with two strings to translate identically.
check(
    'only the composer writes the sentence',
    preg_match_all("/Resume scan \(%1\\\$s \/ %2\\\$s\)/", $progressSource . $toolsSource . $ajaxSource),
    1
);

check(
    'the screen renders the button from that composer',
    preg_match('/ScanProgress::resumeLabel\(\$running\[.done.\], \$running\[.total.\]\)/', $toolsSource),
    1
);

// The reply half of the fix: without this field there is nothing for the
// browser to write, whatever the script does.
check(
    'every batch reply carries it',
    preg_match("/'resume' => ScanProgress::resumeLabel\(/", $ajaxSource),
    1
);

// And the browser half: the field is read, and it lands on the button the
// server rendered — not on the progress label, which was never the defect.
check('the script reads it off the reply', preg_match('/updateResumeLabel\(data\.resume\)/', $adminScript), 1);
check('and writes it onto the Resume button', preg_match('/function updateResumeLabel\(text\).+startButton\.textContent = text;/sU', $adminScript), 1);

// ------------------------------------------------------------------ report

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

printf("%d passed, %d failed\n", $passed, count($failures));

exit($failures === [] ? 0 : 1);

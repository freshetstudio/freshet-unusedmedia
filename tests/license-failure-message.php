<?php

declare(strict_types=1);

/**
 * License failure-message smoke test — the server's sentence, passed through.
 *
 * Pure PHP: no WordPress, no database, no test framework. Run it with:
 *
 *     php tests/license-failure-message.php
 *
 * The license server writes the sentence a customer reads when a key is
 * refused; this plugin passes it through as written and adds its own words
 * in exactly three places, each for a reason the server cannot know:
 *
 * 1. **A transport failure is not a verdict.** `http_error` and
 *    `invalid_response` say nothing about the key, so the plugin says so.
 * 2. **An unknown key was already cleaned.** `invalid_key` carries the
 *    server's sentence *plus* one line: the paste has already had its spaces
 *    and invisible characters stripped (normalizeKey), so pasting it again
 *    will not change the answer.
 * 3. **Every other verdict is the server's, verbatim.** Expired, revoked,
 *    the activation limit — whatever the server writes is what is shown,
 *    with nothing prepended or appended.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');

// --------------------------------------------------------------- WP stubs

function __(string $text, string $domain = ''): string
{
    return $text;
}

function apply_filters(string $hook, mixed $value): mixed
{
    return $value;
}

function untrailingslashit(string $value): string
{
    return rtrim($value, '/');
}

foreach ([
    'src/License/LicenseInterface.php',
    'src/License/LicenseClient.php',
    'src/Admin/LicenseSection.php',
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\LicenseSection;
use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\LicenseInterface;

// ------------------------------------------------------------- assertions

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

// ------------------------------------------------------------ the section

$section = new LicenseSection(new LicenseClient(), new class () implements LicenseInterface {
    public function isPro(): bool
    {
        return false;
    }
});

$failureMessage = (new ReflectionMethod($section, 'failureMessage'))->getClosure($section);
$normalizeKey = (new ReflectionMethod($section, 'normalizeKey'))->getClosure($section);

$serverSentence = 'This key isn\'t one we issued — check it against your purchase email.';

// 3. The server's verdicts pass through verbatim.
foreach (['expired', 'revoked', 'activation_limit_reached', 'unknown_product'] as $code) {
    check(
        sprintf('%s is the server\'s sentence, unchanged', $code),
        $failureMessage(['success' => false, 'error' => $serverSentence, 'error_code' => $code]),
        $serverSentence
    );
}

// 2. An unknown key keeps the server's sentence and gains the one hint.
$unknown = $failureMessage(['success' => false, 'error' => $serverSentence, 'error_code' => 'invalid_key']);

check('invalid_key starts with the server\'s sentence', str_starts_with($unknown, $serverSentence), true);
check('invalid_key carries the already-stripped hint', str_contains($unknown, 'already stripped'), true);
check('invalid_key is one paragraph, not two', str_contains($unknown, "\n"), false);
check(
    'invalid_key with no server sentence is the hint alone',
    str_starts_with($failureMessage(['success' => false, 'error_code' => 'invalid_key']), 'Spaces'),
    true
);

// 1. Transport failures are not verdicts and say so.
check(
    'http_error says the key was not judged',
    str_contains($failureMessage(['success' => false, 'error' => 'cURL error 28', 'error_code' => 'http_error']), 'not a verdict on your key'),
    true
);
check(
    'invalid_response says the key was not judged',
    str_contains($failureMessage(['success' => false, 'error' => 'HTTP 502', 'error_code' => 'invalid_response']), 'not a verdict on your key'),
    true
);

// And the hint is true: the normaliser does strip what a paste brings along.
check('a key pasted with NBSP and zero-width space is stripped', $normalizeKey("\u{00A0}FRSH-\u{200B}ABCD\u{FEFF} "), 'FRSH-ABCD');
check('a clean key is untouched', $normalizeKey('FRSH-ABCD'), 'FRSH-ABCD');

// ------------------------------------------------------------------ result

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL  ' . $failure . PHP_EOL);
}

printf('%d passed, %d failed%s', $passed, count($failures), PHP_EOL);

exit($failures === [] ? 0 : 1);

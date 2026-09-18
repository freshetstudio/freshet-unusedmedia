<?php

declare(strict_types=1);

/**
 * License verdict smoke test — the plugin's own words, on the notice and the card.
 *
 * Pure PHP: no WordPress, no database, no test framework. Run it with:
 *
 *     php tests/license-failure-message.php
 *
 * The license server answers in codes (`error_code` on a refusal, `status`
 * beside `valid` on a validation); this plugin owns the sentence a customer
 * reads for each of them, in one map that the activation notice and the
 * License card's state line both read. Three things are pinned:
 *
 * 1. **A transport failure is not a verdict.** `http_error` and
 *    `invalid_response` say nothing about the key, so the notice says so.
 * 2. **Every verdict is told in the plugin's words** — unknown key (with the
 *    already-stripped hint, since normalizeKey ran), expired, revoked, the
 *    activation limit (with the counts when the server sends them) — and
 *    names where to write. A code the map does not know falls back to the
 *    server's sentence, then to the generic line.
 * 3. **The card reads the same map from the cached verdict.** RemoteLicense
 *    caches the reason and the check time beside `valid`; the state line
 *    says why a site is on Free, and — the one state the notice never shows
 *    — that the server was unreachable, on grace and once grace has lapsed.
 */

// This file ships inside the plugin, so it must not be executable over HTTP.
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', dirname(__DIR__) . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

// --------------------------------------------------------------- WP stubs

/** @var array<string, mixed> */
$options = [];
/** @var array<string, mixed> */
$transients = [];

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

function home_url(): string
{
    return 'https://example.test';
}

function get_option(string $name, mixed $default = false): mixed
{
    global $options;

    return $options[$name] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload = false): bool
{
    global $options;
    $options[$name] = $value;

    return true;
}

function get_transient(string $name): mixed
{
    global $transients;

    return $transients[$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    global $transients;
    $transients[$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    global $transients;
    unset($transients[$name]);

    return true;
}

function wp_date(string $format, int $timestamp): string
{
    return gmdate($format, $timestamp);
}

// The transport, scripted: the real client runs, the network does not.
// $scripted is the decoded body the server would send; a WP_Error stands in
// for a connection that never got one.
final class WP_Error
{
    public function __construct(private string $message)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

/** @var array<string, mixed>|WP_Error */
$scripted = [];

function wp_remote_post(string $url, array $args): mixed
{
    global $scripted;

    return $scripted instanceof WP_Error ? $scripted : ['body' => json_encode($scripted), 'response' => ['code' => 200]];
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_body(array $response): string
{
    return $response['body'];
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['response']['code'];
}

function wp_json_encode(mixed $value): string
{
    return (string) json_encode($value);
}

foreach ([
    'src/License/LicenseInterface.php',
    'src/License/LicenseClient.php',
    'src/License/RemoteLicense.php',
    'src/Admin/LicenseSection.php',
] as $file) {
    require_once ABSPATH . $file;
}

use FreshetUnusedMedia\Admin\LicenseSection;
use FreshetUnusedMedia\License\LicenseClient;
use FreshetUnusedMedia\License\LicenseInterface;
use FreshetUnusedMedia\License\RemoteLicense;

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

// ------------------------------------------------------------- the notice

$section = new LicenseSection(new LicenseClient(), new class () implements LicenseInterface {
    public function isPro(): bool
    {
        return false;
    }
});

$failureMessage = (new ReflectionMethod($section, 'failureMessage'))->getClosure($section);
$stateLine = (new ReflectionMethod($section, 'stateLine'))->getClosure($section);
$normalizeKey = (new ReflectionMethod($section, 'normalizeKey'))->getClosure($section);

$serverSentence = 'The server\'s own sentence about this key.';
$support = 'email@freshet.studio';

// 2. Each verdict is the plugin's sentence: what happened, then where to write.
$own = [
    'invalid_key' => 'one the license server knows',
    'expired' => 'twelve months of updates and support have ended',
    'revoked' => 'cancelled, usually after a refund',
    'activation_limit_reached' => 'allowed number of sites',
];

foreach ($own as $code => $phrase) {
    $text = $failureMessage(['success' => false, 'error' => $serverSentence, 'error_code' => $code]);

    check(sprintf('%s is told in the plugin\'s words', $code), str_contains($text, $phrase), true);
    check(sprintf('%s does not repeat the server\'s sentence', $code), str_contains($text, $serverSentence), false);
    check(sprintf('%s is one paragraph', $code), str_contains($text, "\n"), false);
}

foreach (['invalid_key', 'expired', 'revoked'] as $code) {
    check(
        sprintf('%s names the support address', $code),
        str_contains($failureMessage(['success' => false, 'error_code' => $code]), $support),
        true
    );
}

check(
    'invalid_key carries the already-stripped hint',
    str_contains($failureMessage(['success' => false, 'error_code' => 'invalid_key']), 'already stripped'),
    true
);
check(
    'activation_limit_reached shows the counts when the server sends them',
    str_contains($failureMessage(['success' => false, 'error_code' => 'activation_limit_reached', 'data' => ['activations_used' => 3, 'activation_limit' => 3]]), '3 of 3'),
    true
);
check(
    'activation_limit_reached without counts names no numbers',
    preg_match('/\d/', $failureMessage(['success' => false, 'error_code' => 'activation_limit_reached'])),
    0
);

// A code the map does not know is the server's sentence, else the generic line.
check(
    'unknown_product is the server\'s sentence, unchanged',
    $failureMessage(['success' => false, 'error' => $serverSentence, 'error_code' => 'unknown_product']),
    $serverSentence
);
check(
    'an unknown code with no sentence is the generic line',
    $failureMessage(['success' => false, 'error_code' => 'something_new']),
    'Activation failed.'
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

// ------------------------------------------------- the cached verdict

/**
 * A fresh RemoteLicense with a key stored and nothing cached, resolving
 * against the scripted answer.
 *
 * @param array<string, mixed>|WP_Error $answer
 */
function verdictFor(array|WP_Error $answer, int $lastOk = 0): array
{
    global $options, $transients, $scripted;
    $options = [RemoteLicense::OPTION_KEY => 'FRSH-ABCD', 'freshet_unusedmedia_license_last_ok' => $lastOk];
    $transients = [];
    $scripted = $answer;

    return (new RemoteLicense(new LicenseClient('https://license.test')))->verdict();
}

$counts = ['activations_used' => 1, 'activation_limit' => 3];

$active = verdictFor(['success' => true, 'data' => ['valid' => true, 'status' => 'active'] + $counts]);
check('a validating key is active', [$active['valid'], $active['reason']], [true, 'active']);
check('a validating key records when it was checked', $active['checked_at'] > 0, true);
check('a validating key keeps the counts', [$active['activations_used'], $active['activation_limit']], [1, 3]);
check('a validating key is remembered with its reason', $transients['freshet_unusedmedia_license_status']['reason'] ?? null, 'active');

$expired = verdictFor(['success' => true, 'data' => ['valid' => false, 'status' => 'active'] + $counts]);
check('a known key past its year is expired', [$expired['valid'], $expired['reason']], [false, 'expired']);

$revoked = verdictFor(['success' => true, 'data' => ['valid' => false, 'status' => 'revoked'] + $counts]);
check('a revoked key is revoked', [$revoked['valid'], $revoked['reason']], [false, 'revoked']);

$unknown = verdictFor(['success' => false, 'error' => $serverSentence, 'error_code' => 'invalid_key']);
check('an unknown key keeps the server\'s code', [$unknown['valid'], $unknown['reason']], [false, 'invalid_key']);
check('an unknown key keeps the server\'s sentence for the fallback', $unknown['message'], $serverSentence);

$grace = verdictFor(new WP_Error('cURL error 28'), time() - DAY_IN_SECONDS);
check('an unreachable server inside grace keeps the site on Pro', [$grace['valid'], $grace['reason']], [true, 'unreachable']);

$lapsed = verdictFor(new WP_Error('cURL error 28'), time() - 8 * DAY_IN_SECONDS);
check('an unreachable server past grace puts the site on Free', [$lapsed['valid'], $lapsed['reason']], [false, 'unreachable']);

// A cached verdict is read back whole, and one from before the reason was cached is not trusted.
$transients['freshet_unusedmedia_license_status'] = ['key' => 'FRSH-ABCD', 'valid' => false, 'reason' => 'revoked', 'checked_at' => 1234, 'message' => ''];
$scripted = ['success' => true, 'data' => ['valid' => true, 'status' => 'active']];
$cached = (new RemoteLicense(new LicenseClient('https://license.test')))->verdict();
check('a cached verdict is read back with its reason', [$cached['valid'], $cached['reason'], $cached['checked_at']], [false, 'revoked', 1234]);

$transients['freshet_unusedmedia_license_status'] = ['key' => 'FRSH-ABCD', 'valid' => false];
$refreshed = (new RemoteLicense(new LicenseClient('https://license.test')))->verdict();
check('a cache without a reason is validated again', [$refreshed['valid'], $refreshed['reason']], [true, 'active']);

$options = [];
$none = (new RemoteLicense(new LicenseClient('https://license.test')))->verdict();
check('no key means no call and no verdict', [$none['valid'], $none['reason']], [false, '']);

// ---------------------------------------------------- the card's state line

// 3. The card says the same thing the notice would, from the cached verdict.
check('active reads Active', $stateLine($active), 'Active — this site is on Freshet Unused Media Pro.');
check('expired on the card is the expired sentence', str_contains($stateLine($expired), $own['expired']), true);
check('revoked on the card is the revoked sentence', str_contains($stateLine($revoked), $own['revoked']), true);
check('an unknown key on the card is the unknown-key sentence', str_contains($stateLine($unknown), $own['invalid_key']), true);
check('the card and the notice agree on expired', $stateLine($expired), $failureMessage(['success' => false, 'error_code' => 'expired']));
check('the card and the notice agree on revoked', $stateLine($revoked), $failureMessage(['success' => false, 'error_code' => 'revoked']));

$graceLine = $stateLine($grace);
check('grace on the card is still Active', str_starts_with($graceLine, 'Active'), true);
check('grace on the card says the server was not reached', str_contains($graceLine, 'could not be reached'), true);
check('grace on the card names the date of the check', str_contains($graceLine, gmdate('F j, Y', $grace['checked_at'])), true);

$lapsedLine = $stateLine($lapsed);
check('lapsed grace on the card is on Free', str_contains($lapsedLine, 'on Free'), true);
check('lapsed grace on the card blames the server, not the key', str_contains($lapsedLine, 'Nothing about your key changed'), true);

check(
    'an unreadable response on the card is not a verdict',
    str_contains($stateLine(['valid' => false, 'reason' => 'invalid_response']), 'not a verdict on your key'),
    true
);
check(
    'an unknown reason on the card is the server\'s sentence',
    $stateLine(['valid' => false, 'reason' => 'something_new', 'message' => $serverSentence]),
    $serverSentence
);
check(
    'an unknown reason with no sentence is the generic line',
    $stateLine(['valid' => false, 'reason' => 'something_new']),
    'This key did not validate, so this site is on Free.'
);
check(
    'a license from the filter has only the boolean',
    $stateLine(['valid' => false]),
    'This key did not validate, so this site is on Free.'
);

// ------------------------------------------------------------------ result

foreach ($failures as $failure) {
    fwrite(STDERR, 'FAIL  ' . $failure . PHP_EOL);
}

printf('%d passed, %d failed%s', $passed, count($failures), PHP_EOL);

exit($failures === [] ? 0 : 1);

<?php

/**
 * Zero-dependency unit runner for the pure security helpers (Phase 1
 * remediation): UrlSafety (SSRF guard + link-scheme allowlist) and
 * TempFileSafety (upload-import path-traversal guard).
 *
 * Requires the helper classes directly (no Craft bootstrap), asserts with
 * plain PHP, and exits non-zero on any failure.
 *
 *   php tests/run-security.php
 *
 * A handful of UrlSafety::isPublicHttpUrl() cases exercise real hostname
 * resolution (DNS) rather than IP literals, to prove the "hostname resolves
 * to a private IP" path actually works — not just the deterministic
 * IP-literal path. Those cases are skipped (not failed) when this
 * environment has no working DNS, since they depend on live network access;
 * every IP-literal case (the security-critical minimum) always runs.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.4.0
 */

require __DIR__ . '/../src/helpers/UrlSafety.php';
require __DIR__ . '/../src/helpers/TempFileSafety.php';

use matrixcreate\contentiqimporter\helpers\TempFileSafety;
use matrixcreate\contentiqimporter\helpers\UrlSafety;

$failures = 0;
$passes   = 0;
$skips    = 0;

/**
 * Asserts two values are equal (loose structural compare via var_export).
 */
function check(string $label, mixed $expected, mixed $actual): void
{
    global $failures, $passes;

    if (var_export($expected, true) === var_export($actual, true)) {
        $passes++;
        echo "  PASS  {$label}\n";
        return;
    }

    $failures++;
    echo "  FAIL  {$label}\n";
    echo "        expected: " . var_export($expected, true) . "\n";
    echo "        actual:   " . var_export($actual, true) . "\n";
}

/**
 * Records a skipped (not failed) assertion — used only for the handful of
 * DNS-dependent cases when this environment has no live network access.
 */
function skip(string $label, string $reason): void
{
    global $skips;

    $skips++;
    echo "  SKIP  {$label} ({$reason})\n";
}

// -----------------------------------------------------------------------------
// UrlSafety::isPublicHttpUrl — IP-literal cases (no DNS required).
// -----------------------------------------------------------------------------
echo "isPublicHttpUrl — IP literals\n";

check('public IPv4 literal (Google DNS)', true, UrlSafety::isPublicHttpUrl('http://8.8.8.8/x'));
check('cloud metadata IP (169.254.169.254)', false, UrlSafety::isPublicHttpUrl('http://169.254.169.254/latest/meta-data/'));
check('loopback (127.0.0.1)', false, UrlSafety::isPublicHttpUrl('http://127.0.0.1/'));
check('private 10.0.0.0/8', false, UrlSafety::isPublicHttpUrl('http://10.0.0.5/'));
check('private 172.16.0.0/12', false, UrlSafety::isPublicHttpUrl('http://172.20.1.1/'));
check('private 192.168.0.0/16', false, UrlSafety::isPublicHttpUrl('http://192.168.1.1/'));
check('"this network" 0.0.0.0/8', false, UrlSafety::isPublicHttpUrl('http://0.0.0.0/'));
check('IPv6 loopback ::1', false, UrlSafety::isPublicHttpUrl('http://[::1]/'));
check('IPv6 unique-local fc00::/7', false, UrlSafety::isPublicHttpUrl('http://[fc00::1]/'));
check('IPv6 link-local fe80::/10', false, UrlSafety::isPublicHttpUrl('http://[fe80::1]/'));
check('public IPv6 literal (Google DNS)', true, UrlSafety::isPublicHttpUrl('http://[2001:4860:4860::8888]/'));

// -----------------------------------------------------------------------------
// UrlSafety::isPublicHttpUrl — scheme / malformed-input cases (no DNS
// required — these are rejected before any host resolution is attempted).
// -----------------------------------------------------------------------------
echo "isPublicHttpUrl — scheme / malformed input\n";

check('non-http(s) scheme (ftp)', false, UrlSafety::isPublicHttpUrl('ftp://example.com/file'));
check('javascript: has no host', false, UrlSafety::isPublicHttpUrl('javascript:alert(1)'));
check('empty string', false, UrlSafety::isPublicHttpUrl(''));
check('not a url at all', false, UrlSafety::isPublicHttpUrl('not a url at all'));
check('scheme with empty host', false, UrlSafety::isPublicHttpUrl('http:///path'));

// -----------------------------------------------------------------------------
// UrlSafety::isPublicHttpUrl — hostname-resolution cases (require live DNS).
// Uses real, well-known public services rather than made-up hostnames so the
// assertions are meaningful when network access is available:
//   - example.com / one.one.one.one resolve to real public addresses.
//   - *.nip.io is a real public wildcard-DNS service (commonly used for this
//     exact kind of SSRF test) that resolves "<ip>.nip.io" to <ip> — used
//     here to prove a *hostname* resolving to a private/metadata IP is
//     caught, not just an IP literal typed directly into the URL.
// -----------------------------------------------------------------------------
echo "isPublicHttpUrl — hostname resolution (requires DNS)\n";

$dnsAvailable = @gethostbyname('example.com') !== 'example.com';

if ($dnsAvailable) {
    check('public hostname (example.com)', true, UrlSafety::isPublicHttpUrl('https://example.com/x'));
    check('public hostname (one.one.one.one)', true, UrlSafety::isPublicHttpUrl('http://one.one.one.one/a.jpg'));
    check('hostname resolving to loopback (nip.io)', false, UrlSafety::isPublicHttpUrl('http://127.0.0.1.nip.io/'));
    check('hostname resolving to cloud metadata IP (nip.io)', false, UrlSafety::isPublicHttpUrl('http://169.254.169.254.nip.io/'));
    check('non-resolving hostname fails closed', false, UrlSafety::isPublicHttpUrl('http://this-host-does-not-exist.invalid/'));
} else {
    skip('public hostname (example.com)', 'no DNS in this environment');
    skip('public hostname (one.one.one.one)', 'no DNS in this environment');
    skip('hostname resolving to loopback (nip.io)', 'no DNS in this environment');
    skip('hostname resolving to cloud metadata IP (nip.io)', 'no DNS in this environment');
    skip('non-resolving hostname fails closed', 'no DNS in this environment');
}

// -----------------------------------------------------------------------------
// UrlSafety::safeHref — allowlisted schemes and relative/anchor URLs pass
// through unchanged.
// -----------------------------------------------------------------------------
echo "safeHref — allowed\n";

check('http passes through', 'http://example.com', UrlSafety::safeHref('http://example.com'));
check('https passes through', 'https://example.com/page', UrlSafety::safeHref('https://example.com/page'));
check('mailto passes through', 'mailto:test@example.com', UrlSafety::safeHref('mailto:test@example.com'));
check('tel passes through', 'tel:+15551234567', UrlSafety::safeHref('tel:+15551234567'));
check('root-relative passes through', '/about/team', UrlSafety::safeHref('/about/team'));
check('anchor passes through', '#section-2', UrlSafety::safeHref('#section-2'));
check('query-only passes through', '?ref=footer', UrlSafety::safeHref('?ref=footer'));
check('bare relative path passes through', 'about/team', UrlSafety::safeHref('about/team'));
check('empty string passes through', '', UrlSafety::safeHref(''));

// -----------------------------------------------------------------------------
// UrlSafety::safeHref — dangerous schemes collapse to '#', including
// whitespace/control-char and HTML-entity obfuscated variants.
// -----------------------------------------------------------------------------
echo "safeHref — rejected\n";

check('javascript: rejected', '#', UrlSafety::safeHref('javascript:alert(1)'));
check('JavaScript: rejected (case-insensitive)', '#', UrlSafety::safeHref('JavaScript:alert(1)'));
check('data: rejected', '#', UrlSafety::safeHref('data:text/html;base64,PHNjcmlwdD4='));
check('vbscript: rejected', '#', UrlSafety::safeHref('vbscript:msgbox(1)'));
check('leading space + javascript: rejected', '#', UrlSafety::safeHref('  javascript:alert(1)'));
check('leading tab + javascript: rejected', '#', UrlSafety::safeHref("\tjavascript:alert(1)"));
check('embedded control chars rejected', '#', UrlSafety::safeHref("java\x00script:alert(1)"));
check('entity-obfuscated letter rejected', '#', UrlSafety::safeHref('java&#115;cript:alert(1)'));
check('entity-obfuscated colon rejected', '#', UrlSafety::safeHref('javascript&#58;alert(1)'));
check('mixed whitespace + entity obfuscation rejected', '#', UrlSafety::safeHref(" \tjava&#115;cript:alert(1)"));

// -----------------------------------------------------------------------------
// TempFileSafety::sanitize — path-traversal guard for the CP upload flow's
// tempFilename round-trip.
// -----------------------------------------------------------------------------
echo "TempFileSafety::sanitize\n";

check(
    'well-formed temp filename passes through',
    'contentiq-import-260813_231045.json',
    TempFileSafety::sanitize('contentiq-import-260813_231045.json'),
);
check(
    'path traversal to an unrelated file is rejected',
    null,
    TempFileSafety::sanitize('../../etc/passwd'),
);
check(
    'traversal prefix is stripped, not followed — basename still must match the pattern',
    'contentiq-import-260813_231045.json',
    TempFileSafety::sanitize('../../../contentiq-import-260813_231045.json'),
);
check('wrong extension is rejected', null, TempFileSafety::sanitize('contentiq-import-abc.txt'));
check('wrong prefix is rejected', null, TempFileSafety::sanitize('random.json'));
check('empty string is rejected', null, TempFileSafety::sanitize(''));
check(
    'absolute path outside temp dir is rejected (basename only matches with no valid pattern)',
    null,
    TempFileSafety::sanitize('/etc/contentiq-import-hack/../../passwd'),
);

// -----------------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------------
echo "\n" . ($failures === 0 ? "OK" : "FAILED") . ": {$passes} passed, {$failures} failed, {$skips} skipped\n";

exit($failures === 0 ? 0 : 1);

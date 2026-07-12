<?php

/**
 * Zero-dependency unit runner for the pure globals transforms.
 *
 * Requires the helper class directly (no Craft bootstrap), asserts with plain
 * PHP, and exits non-zero on the first failure.
 *
 *   php tests/run-transforms.php
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.3.0
 */

require __DIR__ . '/../src/helpers/GlobalsTransforms.php';

use matrixcreate\contentiqimporter\helpers\GlobalsTransforms;

$failures = 0;
$passes   = 0;

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

// -----------------------------------------------------------------------------
// Opening hours — real lab shapes.
// -----------------------------------------------------------------------------
echo "Opening hours\n";

$labGroups = [
    ['days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'closed' => false, 'opens' => '09:00', 'closes' => '17:00'],
    ['days' => ['saturday'], 'closed' => false, 'opens' => '09:00', 'closes' => '22:00'],
    ['days' => ['sunday'], 'closed' => true, 'opens' => null, 'closes' => null],
];

$expected = [
    0 => ['open' => null, 'close' => null],       // Sunday closed
    1 => ['open' => '09:00', 'close' => '17:00'],  // Monday
    2 => ['open' => '09:00', 'close' => '17:00'],  // Tuesday
    3 => ['open' => '09:00', 'close' => '17:00'],  // Wednesday
    4 => ['open' => '09:00', 'close' => '17:00'],  // Thursday
    5 => ['open' => '09:00', 'close' => '17:00'],  // Friday
    6 => ['open' => '09:00', 'close' => '22:00'],  // Saturday
];
check('mon-fri + sat groups, sunday closed', $expected, GlobalsTransforms::openingHours($labGroups));

$allBlank = [
    0 => ['open' => null, 'close' => null],
    1 => ['open' => null, 'close' => null],
    2 => ['open' => null, 'close' => null],
    3 => ['open' => null, 'close' => null],
    4 => ['open' => null, 'close' => null],
    5 => ['open' => null, 'close' => null],
    6 => ['open' => null, 'close' => null],
];
check('empty array → all blank', $allBlank, GlobalsTransforms::openingHours([]));

// A day named in no group stays blank; only the named day is set.
$oneDay = GlobalsTransforms::openingHours([
    ['days' => ['wednesday'], 'closed' => false, 'opens' => '10:00', 'closes' => '16:00'],
]);
check('unnamed days stay blank (monday)', ['open' => null, 'close' => null], $oneDay[1]);
check('named day set (wednesday)', ['open' => '10:00', 'close' => '16:00'], $oneDay[3]);

// -----------------------------------------------------------------------------
// Country lookup.
// -----------------------------------------------------------------------------
echo "Country lookup\n";

$codeToName = [
    'GB' => 'United Kingdom',
    'US' => 'United States',
    'FR' => 'France',
    'IE' => 'Ireland',
];

check('United Kingdom → GB', 'GB', GlobalsTransforms::countryCode('United Kingdom', $codeToName));
check('UK → GB', 'GB', GlobalsTransforms::countryCode('UK', $codeToName));
check('USA → US', 'US', GlobalsTransforms::countryCode('USA', $codeToName));
check('US of A → US', 'US', GlobalsTransforms::countryCode('US of A', $codeToName));
check('france (lowercase name) → FR', 'FR', GlobalsTransforms::countryCode('france', $codeToName));
check('existing ISO GB passes through', 'GB', GlobalsTransforms::countryCode('gb', $codeToName));
check('garbage → null', null, GlobalsTransforms::countryCode('Wakanda', $codeToName));
check('empty → null', null, GlobalsTransforms::countryCode('', $codeToName));
check('null → null', null, GlobalsTransforms::countryCode(null, $codeToName));

// -----------------------------------------------------------------------------
// Address split.
// -----------------------------------------------------------------------------
echo "Address split\n";

check('single line → line 1 only', [
    'addressLine1' => '1 Main Street',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress('1 Main Street'));

check('two lines', [
    'addressLine1' => '10 High Street',
    'addressLine2' => 'Parker Parish',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress("10 High Street\nParker Parish"));

check('three lines', [
    'addressLine1' => 'A',
    'addressLine2' => 'B',
    'addressLine3' => 'C',
], GlobalsTransforms::splitAddress("A\nB\nC"));

check('four+ lines fold into line 3', [
    'addressLine1' => 'A',
    'addressLine2' => 'B',
    'addressLine3' => 'C, D',
], GlobalsTransforms::splitAddress("A\nB\nC\nD"));

check('empty string → all blank', [
    'addressLine1' => '',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress(''));

check('null → all blank', [
    'addressLine1' => '',
    'addressLine2' => '',
    'addressLine3' => '',
], GlobalsTransforms::splitAddress(null));

// -----------------------------------------------------------------------------
// URL-prefix drift.
// -----------------------------------------------------------------------------
echo "URL-prefix drift\n";

check('blog vs the-blog drifts', true, GlobalsTransforms::urlPrefixDrifts('the-blog', 'blog/{slug}'));
check('team vs meet-the-team drifts', true, GlobalsTransforms::urlPrefixDrifts('meet-the-team', 'team/{slug}'));
check('projects vs projects matches', false, GlobalsTransforms::urlPrefixDrifts('projects', 'projects/{slug}'));
check('static prefix extraction', 'blog/category', GlobalsTransforms::staticUriPrefix('blog/category/{slug}'));
check('leading token → empty prefix, no drift', false, GlobalsTransforms::urlPrefixDrifts('anything', '{slug}'));

// -----------------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------------
echo "\n" . ($failures === 0 ? "OK" : "FAILED") . ": {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);

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
// content_types map — every row must have the keys _getContentTypesMap()
// consumers rely on, with non-empty section/entryType/contentField.
// headingField is genuinely optional (see blog_categories) so it is not
// required. This is a shape guard, not a live-Craft check — it cannot catch
// a handle that doesn't exist in a given site's schema, only a row that's
// missing a piece the importer will dereference unconditionally.
// -----------------------------------------------------------------------------
echo "\ncontent_types map shape\n";

$defaults = require __DIR__ . '/../src/config/defaults.php';
$contentTypes = $defaults['content_types'] ?? [];

check('content_types is non-empty', true, count($contentTypes) > 0);

foreach ($contentTypes as $slug => $row) {
    check("{$slug}: is array", true, is_array($row));

    if (!is_array($row)) {
        continue;
    }

    foreach (['section', 'entryType', 'contentField'] as $requiredKey) {
        check(
            "{$slug}: has non-empty '{$requiredKey}'",
            true,
            isset($row[$requiredKey]) && $row[$requiredKey] !== '',
        );
    }
}

// -----------------------------------------------------------------------------
// MatrixBuilder — collection child carrying blocks[] (§7.1/7.2).
//
// ContentIQ is about to start emitting blocks[] for collection members (case
// studies, team, blog posts) instead of one undifferentiated `content` blob.
// _importCollectionChild() now runs the same MatrixBuilder machinery the page
// path uses when blocks[] is present and non-empty. This exercises that
// machinery directly against a captured collection-child shape and asserts on
// the matrixData MatrixBuilder::build() actually returns — the real risk
// surface, given a whole-Matrix overwrite writes straight to client entries.
//
// MatrixBuilder/NodesRenderer reach Craft/Yii only by name (Craft::warning(),
// yii\base\Component, ContentIQImporter::$plugin) — none of it is exercised
// by the two block types below, so lightweight stubs (tests/fixtures/craft-
// stubs.php) are enough to run the real service classes standalone.
// -----------------------------------------------------------------------------
echo "\nMatrixBuilder — collection child blocks[] shape\n";

require __DIR__ . '/fixtures/craft-stubs.php';
// LinkHelper::hyperInertUrl() delegates scheme checking to UrlSafety, so it
// must be loaded first — this harness require-s classes by hand rather than
// autoloading (no Craft bootstrap here).
require __DIR__ . '/../src/helpers/UrlSafety.php';
require __DIR__ . '/../src/helpers/LinkHelper.php';
require __DIR__ . '/../src/services/NodesRenderer.php';
require __DIR__ . '/../src/services/MatrixBuilder.php';

$fakeImages = new class {
    public function importFromField($value, $dryRun)
    {
        return null;
    }
};

$fakeImports = new class {
    public function getContentTypesMap(): array
    {
        return [];
    }
};

$fakePlugin = new class {
    public $nodes;
    public $images;
    public $imports;
};
$fakePlugin->nodes   = new \matrixcreate\contentiqimporter\services\NodesRenderer();
$fakePlugin->images  = $fakeImages;
$fakePlugin->imports = $fakeImports;

\matrixcreate\contentiqimporter\ContentIQImporter::$plugin = $fakePlugin;

$fixture = json_decode(
    file_get_contents(__DIR__ . '/fixtures/collection-child-with-blocks.json'),
    true,
);

$matrixBuilder = new \matrixcreate\contentiqimporter\services\MatrixBuilder();
$matrixBuilder->prepare(['blockOverrides' => []]);

$built = $matrixBuilder->build($fixture['blocks'], false, $fixture['document']['slug']);

check('matrixData has one outer entry per block', 2, count($built['matrixData']));
check('new1 (usp) outer type', 'contentiqUsp', $built['matrixData']['new1']['type'] ?? null);
check(
    'new1 (usp) uspText renders heading + list',
    '<h2>Why it matters</h2><ul><li>Reduces bycatch</li><li>Protects reef habitats</li><li>Supports local fisheries</li></ul>',
    $built['matrixData']['new1']['fields']['uspText'] ?? null,
);
check('new2 (text) outer type', 'text', $built['matrixData']['new2']['type'] ?? null);
check('new2 (text) columnLayout passes through', 'singleColumn', $built['matrixData']['new2']['fields']['columnLayout'] ?? null);

$textBlocks = $built['matrixData']['new2']['fields']['textBlocks'] ?? [];
check('new2 (text) has one inner textBlock', 1, count($textBlocks));
check('new2 (text) inner entry type', 'textBlock', $textBlocks['new1']['type'] ?? null);
check(
    'new2 (text) inner richText renders the paragraph',
    '<p>Our approach combines survey data with on-the-ground partnerships.</p>',
    $textBlocks['new1']['fields']['richText'] ?? null,
);

check('blockReport has one row per block, none skipped', [false, false], array_column($built['blockReport'], 'skipped'));

// -----------------------------------------------------------------------------
// ImportService — §7.5 hero shape probe; §7.6/§7.6.1 legacy-field clearing
// (rulings O2/O4) and its guard-2 "was this previously non-empty" warnings.
//
// These are exercised through private methods via Reflection rather than the
// full importPage()/​_importCollectionChild() pipeline, because that pipeline
// is inseparable from live Craft (Entry::find(), Craft::$app->entries,
// saveElement()...). Each method under test here was deliberately kept pure
// (or reduced to the minimal Craft surface — FieldLayout::getFieldByHandle())
// specifically so the actual judgement calls — which hero shape, whether to
// clear, whether to warn — are unit-testable without a Craft bootstrap. See
// tests/fixtures/craft-stubs.php for the FieldLayout/ContentBlock stand-ins.
// -----------------------------------------------------------------------------
echo "\nImportService — hero shape probe (§7.5)\n";

require __DIR__ . '/../src/services/ImportService.php';

$importService = new \matrixcreate\contentiqimporter\services\ImportService();

/**
 * Invokes a private/protected method via Reflection — this test file has no
 * Craft bootstrap to construct these services through their public API alone.
 */
function callPrivate(object $obj, string $method, array $args = []): mixed
{
    // No setAccessible() call — private methods have been reflection-callable
    // without it since PHP 8.1; setAccessible() itself is deprecated as of 8.5.
    return (new ReflectionMethod($obj, $method))->invokeArgs($obj, $args);
}

$contentBlockLayout = new \craft\models\FieldLayout([
    'hero' => new \craft\fields\ContentBlock(),
]);
check(
    "'hero' field is a ContentBlock instance → contentblock shape",
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [$contentBlockLayout]),
);

$flatLayout = new \craft\models\FieldLayout([
    'heroTitle' => new class {}, // any field object — not a ContentBlock
]);
check(
    "flat 'heroTitle' field, no ContentBlock 'hero' → flat shape",
    'flat',
    callPrivate($importService, '_detectHeroShape', [$flatLayout]),
);

$neitherLayout = new \craft\models\FieldLayout([]);
check(
    'neither shape present on layout → falls back to contentblock',
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [$neitherLayout]),
);

check(
    'no field layout given → falls back to contentblock',
    'contentblock',
    callPrivate($importService, '_detectHeroShape', [null]),
);

// End-to-end through _buildHeroField() — same hero block, both shapes, wired
// through the real image/nodes/LinkHelper machinery (fakeImages/nodes reused
// from the MatrixBuilder fixture above).
$heroBlock = [
    'fields' => [
        'heading'    => ['level' => 1, 'text' => 'Welcome'],
        'subheading' => ['level' => 2, 'text' => 'Subtitle'],
        'body'       => 'Body text.',
        'image'      => ['key' => 'k1', 'url' => 'https://example.com/hero.jpg', 'alt' => 'Hero'],
        'buttons'    => [
            ['text' => 'Learn more', 'url' => 'https://example.com/learn'],
        ],
    ],
];

$contentBlockHero = callPrivate($importService, '_buildHeroField', [$heroBlock, false, $contentBlockLayout]);
check('contentblock shape: enableHero', true, $contentBlockHero['enableHero'] ?? null);
check('contentblock shape: heading nested under hero.fields', '<h1>Welcome</h1>', $contentBlockHero['hero']['fields']['heading'] ?? null);
check('contentblock shape: richText nested under hero.fields', '<h2>Subtitle</h2><p>Body text.</p>', $contentBlockHero['hero']['fields']['richText'] ?? null);
check('contentblock shape: no flat heroTitle key written', false, array_key_exists('heroTitle', $contentBlockHero));

$flatHero = callPrivate($importService, '_buildHeroField', [$heroBlock, false, $flatLayout]);
check('flat shape: enableHero', true, $flatHero['enableHero'] ?? null);
check('flat shape: heroTitle (not nested)', '<h1>Welcome</h1>', $flatHero['heroTitle'] ?? null);
check('flat shape: heroRichText (not nested)', '<h2>Subtitle</h2><p>Body text.</p>', $flatHero['heroRichText'] ?? null);
check(
    'flat shape: heroActionButtons carries the button',
    'https://example.com/learn',
    $flatHero['heroActionButtons']['new1']['fields']['actionButton'][0]['linkValue'] ?? null,
);
check('flat shape: no nested hero key written', false, array_key_exists('hero', $flatHero));
check('flat shape: heroMobileImage absent (no mobile_image on the block)', false, array_key_exists('heroMobileImage', $flatHero));

// -----------------------------------------------------------------------------
echo "\nImportService — collection-child legacy-field clearing (§7.6/§7.6.1, rulings O2/O4)\n";

$docWithH1 = [
    'type' => 'doc',
    'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'My Article Heading']]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body copy.']]],
    ],
];

$docWithoutH1 = [
    'type' => 'doc',
    'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Just a paragraph.']]],
    ],
];

// blocks present (O2/O4) → contentField AND headingField are '' — explicitly
// present as empty strings, never omitted — regardless of what $content held.
$blocksPresentBoth = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithH1, true, 'articleContent', 'headline',
]);
check('blocks present: contentField key exists', true, array_key_exists('articleContent', $blocksPresentBoth));
check('blocks present: contentField is empty string (not absent)', '', $blocksPresentBoth['articleContent'] ?? 'MISSING');
check('blocks present: headingField key exists', true, array_key_exists('headline', $blocksPresentBoth));
check('blocks present: headingField is empty string (not absent)', '', $blocksPresentBoth['headline'] ?? 'MISSING');
check('blocks present: exactly two keys written (no stray heading omission)', 2, count($blocksPresentBoth));

// blocks present, content_type has no headingField configured (e.g. blog_categories)
// → only contentField is cleared; there is no heading key to clear.
$blocksPresentNoHeading = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithH1, true, 'body', null,
]);
check('blocks present, no headingField configured: only contentField key written', ['body'], array_keys($blocksPresentNoHeading));
check('blocks present, no headingField configured: contentField is empty string', '', $blocksPresentNoHeading['body'] ?? 'MISSING');

// blocks absent (pre-§7.1 behaviour, untouched) → normal H1 extraction: the
// H1 is lifted into headingField and stripped from the body that renders
// into contentField (so it isn't rendered twice).
$blocksAbsentWithH1 = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithH1, false, 'articleContent', 'headline',
]);
check('blocks absent + H1 present: headingField extracted', 'My Article Heading', $blocksAbsentWithH1['headline'] ?? null);
check('blocks absent + H1 present: contentField renders remaining body, H1 stripped', '<p>Body copy.</p>', $blocksAbsentWithH1['articleContent'] ?? null);

// blocks absent, no H1 in the doc → headingField is never populated (stays
// genuinely absent, not '') — this is the pre-existing behaviour and must
// stay untouched by the new blocks-present clearing path.
$blocksAbsentNoH1 = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithoutH1, false, 'articleContent', 'headline',
]);
check('blocks absent, no H1: headingField key is genuinely absent (not cleared to \'\')', false, array_key_exists('headline', $blocksAbsentNoH1));
check('blocks absent, no H1: contentField renders the paragraph', '<p>Just a paragraph.</p>', $blocksAbsentNoH1['articleContent'] ?? null);

// blocks absent, no headingField configured → same as today: only contentField.
$blocksAbsentNoHeadingField = callPrivate($importService, '_buildCollectionChildContentFields', [
    $docWithoutH1, false, 'body', null,
]);
check('blocks absent, no headingField configured: only contentField key written', ['body'], array_keys($blocksAbsentNoHeadingField));

// -----------------------------------------------------------------------------
echo "\nImportService — §7.6.1 guard 2 / §7.7: 'previously non-empty' warnings\n";

check('empty string is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ['']));
check('whitespace-only string is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ["   \n\t"]));
check('empty tags-only HTML is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', ['<p></p>']));
check('null is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', [null]));
check('empty array is not non-empty', false, callPrivate($importService, '_isFieldValueNonEmpty', [[]]));
check('HTML with real text is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', ['<p>Hello</p>']));
check('plain non-empty string is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', ['Hello']));
check('non-empty array is non-empty', true, callPrivate($importService, '_isFieldValueNonEmpty', [[1, 2]]));

check(
    'nothing previously populated → no warnings',
    [],
    callPrivate($importService, '_buildBlockOwnershipWarnings', [false, false, 0, 'articleContent', 'headline', 'contentBlocks']),
);

$contentWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [true, false, 0, 'articleContent', 'headline', 'contentBlocks']);
check('contentField previously non-empty → exactly one warning', 1, count($contentWarnings));
check(
    'contentField warning names the handle',
    "Blocks now own this page — clearing previously non-empty 'articleContent' field.",
    $contentWarnings[0] ?? null,
);

$headingWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [false, true, 0, 'articleContent', 'headline', 'contentBlocks']);
check('headingField previously non-empty → exactly one warning', 1, count($headingWarnings));
check(
    'headingField warning names the handle',
    "Blocks now own this page's heading — clearing previously non-empty 'headline' field.",
    $headingWarnings[0] ?? null,
);

$matrixWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [false, false, 3, 'articleContent', 'headline', 'contentBlocks']);
check('matrix previously non-empty → exactly one warning', 1, count($matrixWarnings));
check(
    'matrix warning names the handle and count',
    "Content Blocks field 'contentBlocks' already holds 3 block(s) not written by this importer — this sync will replace them wholesale with new element IDs.",
    $matrixWarnings[0] ?? null,
);

$allThreeWarnings = callPrivate($importService, '_buildBlockOwnershipWarnings', [true, true, 1, 'articleContent', 'headline', 'contentBlocks']);
check('all three previously non-empty → three warnings', 3, count($allThreeWarnings));

// -----------------------------------------------------------------------------
// Summary.
// -----------------------------------------------------------------------------
echo "\n" . ($failures === 0 ? "OK" : "FAILED") . ": {$passes} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);

<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\ExitCode;

/**
 * Isolated Matrix save test.
 *
 * Proves whether a nested Matrix structure (contentBlocks → textAndMedia →
 * textAndMediaBlocks → textAndMediaBlock with an image Assets field) can be
 * saved correctly via the raw Craft API, with no importer abstraction at all.
 *
 * Usage:
 *   php craft contentiq-importer/test-matrix
 *
 * Everything is hardcoded. If this command saves correctly (draft exists in
 * CP with rich text AND the image asset populated in the image field), the
 * problem is in the importer abstraction layer. If it does NOT save correctly,
 * the problem is in how we're calling the Craft API and we need to fix that
 * understanding before touching the importer.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class TestMatrixController extends Controller
{
    // =========================================================================
    // HARDCODED TEST CONSTANTS — change these to match your environment
    // =========================================================================

    /**
     * Slug of an existing published page entry in the 'pages' section.
     * We create a draft off this entry.
     */
    private const PAGE_SLUG = 'tents-shelters';

    /**
     * ID of an existing asset in the Craft DB to use as the image relation.
     * Run: SELECT id, filename FROM assets LIMIT 10;
     */
    private const ASSET_ID = 144;

    // =========================================================================

    /**
     * Run the isolated Matrix save test.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        $this->stdout("=== Matrix Save Test ===\n\n", Console::BOLD);

        // ------------------------------------------------------------------
        // Step 1: Verify the asset exists.
        // ------------------------------------------------------------------
        $this->stdout("Step 1: Verify asset ID " . self::ASSET_ID . " exists... ");

        $asset = Asset::find()->id(self::ASSET_ID)->status(null)->one();

        if ($asset === null) {
            $this->failure("Asset ID " . self::ASSET_ID . " not found. Update ASSET_ID constant.");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("OK ({$asset->filename})\n", Console::FG_GREEN);

        // ------------------------------------------------------------------
        // Step 2: Find the canonical page entry by slug.
        // ------------------------------------------------------------------
        $this->stdout("Step 2: Find page entry slug='" . self::PAGE_SLUG . "'... ");

        $canonical = Entry::find()
            ->section('pages')
            ->slug(self::PAGE_SLUG)
            ->status(null)
            ->drafts(false)
            ->one();

        if ($canonical === null) {
            $this->failure("No page entry found with slug '" . self::PAGE_SLUG . "'.");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("OK (entry ID {$canonical->id})\n", Console::FG_GREEN);

        // ------------------------------------------------------------------
        // Step 3: Create a draft via Craft's draft service.
        // ------------------------------------------------------------------
        $this->stdout("Step 3: Create draft... ");

        $draft = Craft::$app->getDrafts()->createDraft(
            canonical: $canonical,
            creatorId: null,
            name: 'Matrix Test Draft',
        );

        $this->stdout("OK (draft ID {$draft->id})\n", Console::FG_GREEN);

        // ------------------------------------------------------------------
        // Step 4: Build the hardcoded Matrix data structure.
        //
        // contentBlocks (outer Matrix on the entry)
        //   → new1: textAndMedia (outer entry type)
        //       → textAndMediaBlocks (inner Matrix field on textAndMedia)
        //           → new1: textAndMediaBlock (inner entry type)
        //               → richText: '<p>...</p>'
        //               → image:    [assetId]   ← asset relation
        // ------------------------------------------------------------------
        $this->stdout("Step 4: Build hardcoded Matrix data... ");

        $matrixData = [
            'new1' => [
                'type'   => 'textAndMedia',
                'fields' => [
                    'textAndMediaBlocks' => [
                        'new1' => [
                            'type'   => 'textAndMediaBlock',
                            'fields' => [
                                'richText' => '<p>This is a test paragraph written by the matrix test command.</p>',
                                'image'    => [self::ASSET_ID],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->stdout("OK\n", Console::FG_GREEN);
        $this->stdout("  Data shape: contentBlocks → textAndMedia → textAndMediaBlocks → textAndMediaBlock\n", Console::FG_GREY);
        $this->stdout("  richText: '<p>Test...</p>'\n", Console::FG_GREY);
        $this->stdout("  image: [" . self::ASSET_ID . "]\n", Console::FG_GREY);

        // ------------------------------------------------------------------
        // Step 5: Set the contentBlocks field value on the draft.
        // ------------------------------------------------------------------
        $this->stdout("Step 5: setFieldValue('contentBlocks', \$matrixData)... ");

        $draft->setFieldValue('contentBlocks', $matrixData);

        $this->stdout("OK\n", Console::FG_GREEN);
        $this->stdout("  Field dirty: " . ($draft->isFieldDirty('contentBlocks') ? 'yes' : 'NO — problem!') . "\n", Console::FG_GREY);

        // ------------------------------------------------------------------
        // Step 6: Save the draft (runValidation=false to skip anchor uniqueness).
        // ------------------------------------------------------------------
        $this->stdout("Step 6: saveElement(\$draft, false)... ");

        $saved = Craft::$app->getElements()->saveElement($draft, false);

        if (!$saved) {
            $errors = implode(', ', $draft->getFirstErrors());
            $this->failure("saveElement failed: {$errors}");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("OK (draft ID {$draft->id})\n", Console::FG_GREEN);

        // ------------------------------------------------------------------
        // Step 7: Reload the draft fresh from DB and inspect the result.
        // ------------------------------------------------------------------
        $this->stdout("Step 7: Reload draft from DB and inspect...\n");

        $reloaded = Entry::find()
            ->id($draft->id)
            ->drafts(true)
            ->status(null)
            ->one();

        if ($reloaded === null) {
            $this->failure("Could not reload draft ID {$draft->id}.");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("  Draft reloaded OK\n", Console::FG_GREEN);

        // Inspect outer Matrix entries (contentBlocks).
        $outerEntries = $reloaded->contentBlocks->all();
        $this->stdout("  contentBlocks count: " . count($outerEntries) . " (expected: 1)\n",
            count($outerEntries) === 1 ? Console::FG_GREEN : Console::FG_RED);

        if (empty($outerEntries)) {
            $this->failure("No outer Matrix entries found — save did not create any contentBlocks entries.");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        foreach ($outerEntries as $i => $outer) {
            $typeHandle = $outer->getType()->handle;
            $this->stdout("  outer[{$i}] type: {$typeHandle} (expected: textAndMedia)\n",
                $typeHandle === 'textAndMedia' ? Console::FG_GREEN : Console::FG_RED);

            // Inspect inner Matrix entries (textAndMediaBlocks).
            $innerEntries = $outer->textAndMediaBlocks->all();
            $this->stdout("    textAndMediaBlocks count: " . count($innerEntries) . " (expected: 1)\n",
                count($innerEntries) === 1 ? Console::FG_GREEN : Console::FG_RED);

            foreach ($innerEntries as $j => $inner) {
                $innerType = $inner->getType()->handle;
                $this->stdout("    inner[{$j}] type: {$innerType} (expected: textAndMediaBlock)\n",
                    $innerType === 'textAndMediaBlock' ? Console::FG_GREEN : Console::FG_RED);

                // richText field.
                $richText = $inner->richText;
                $this->stdout("    inner[{$j}] richText: " . (string)$richText . "\n",
                    (string)$richText !== '' ? Console::FG_GREEN : Console::FG_RED);

                // image field — this is what we're most interested in.
                $imageQuery = $inner->image;
                $imageIds   = $imageQuery->ids();
                $this->stdout("    inner[{$j}] image IDs: [" . implode(', ', $imageIds) . "] (expected: [" . self::ASSET_ID . "])\n",
                    in_array(self::ASSET_ID, $imageIds, true) ? Console::FG_GREEN : Console::FG_RED);
            }
        }

        // ------------------------------------------------------------------
        // Step 8: Raw DB inspection — what is actually in the content JSON?
        // ------------------------------------------------------------------
        $this->stdout("\nStep 8: Raw DB content JSON for inner entries...\n");

        $innerIds = [];
        foreach ($outerEntries as $outer) {
            foreach ($outer->textAndMediaBlocks->all() as $inner) {
                $innerIds[] = $inner->id;
            }
        }

        if (!empty($innerIds)) {
            $db = Craft::$app->getDb();
            foreach ($innerIds as $innerId) {
                $row = (new \craft\db\Query())
                    ->select(['content'])
                    ->from('{{%elements_sites}}')
                    ->where(['elementId' => $innerId])
                    ->scalar();

                $decoded = $row ? Json::decodeIfJson($row) : null;
                $this->stdout("  elements_sites.content for entry ID {$innerId}:\n");
                $this->stdout("    " . Json::encode($decoded, JSON_PRETTY_PRINT) . "\n", Console::FG_GREY);
            }
        } else {
            $this->stdout("  (no inner entries found to inspect)\n", Console::FG_YELLOW);
        }

        // ------------------------------------------------------------------
        // Result.
        // ------------------------------------------------------------------
        $this->stdout("\n");

        $imageOk = false;
        foreach ($outerEntries as $outer) {
            foreach ($outer->textAndMediaBlocks->all() as $inner) {
                if (in_array(self::ASSET_ID, $inner->image->ids(), true)) {
                    $imageOk = true;
                }
            }
        }

        if ($imageOk) {
            $this->stdout("PASS: Image field populated correctly.\n", Console::FG_GREEN, Console::BOLD);
            $this->stdout("Draft ID {$draft->id} is ready to inspect in the CP.\n");
        } else {
            $this->stdout("FAIL: Image field is EMPTY after save. The raw Craft API does not\n", Console::FG_RED, Console::BOLD);
            $this->stdout("save image relations in nested Matrix via this data shape.\n", Console::FG_RED);
        }

        return ExitCode::OK;
    }
}

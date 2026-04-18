<?php

namespace matrixcreate\copydeckimporter\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\models\FieldLayout;
use matrixcreate\copydeckimporter\CopydeckImporter;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the full import pipeline for a single Copydeck page.
 *
 * Pipeline (per page):
 *   1. Validate and parse the JSON structure.
 *   2. Resolve section and entry type from config.
 *   3. Find existing entry by slug (or create a new one).
 *   4. Set title from document.title.
 *   5. Populate SEO fields.
 *   6. Download and import images via ImageImportService.
 *   7. Walk blocks via MatrixBuilder → set contentBlocks Matrix field.
 *   8. Save directly to the entry (no draft).
 *   9. Return a result array for the controller to render.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImportService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Resolved and merged config for the current run.
     *
     * @var array|null
     */
    private ?array $_config = null;

    // Public Methods
    // =========================================================================

    /**
     * Runs the full import pipeline for a single Copydeck page export.
     *
     * Returns a result array consumed by ImportController for output rendering.
     *
     * Result shape:
     * {
     *   success:       bool,
     *   slug:          string,
     *   entryId:       int|null,
     *   entryFound:    bool,
     *   seoFieldCount: int,
     *   blocks:        [{type, fields[], skipped}],
     *   images:        [{filename, reused}],
     *   warnings:      string[],
     *   error:         string|null,
     * }
     *
     * @param array $data    Decoded top-level JSON object for a single page.
     * @param bool  $dryRun  If true, validate and report without writing anything.
     * @param bool  $verbose Unused here — verbose block logging is in the controller.
     * @return array
     */
    public function importPage(array $data, bool $dryRun = false, bool $verbose = false): array
    {
        $result = $this->_emptyResult();

        try {
            // -----------------------------------------------------------------------
            // 1. Resolve config (once per process — cached after first call).
            // -----------------------------------------------------------------------
            $config = $this->_getConfig();

            // -----------------------------------------------------------------------
            // 2. Validate JSON structure.
            // -----------------------------------------------------------------------
            if (!isset($data['document']['slug'])) {
                return $this->_fatal($result, 'JSON is missing document.slug.');
            }

            $slug  = $data['document']['slug'];
            $title = $data['document']['title'] ?? $slug;
            $result['slug'] = $slug;

            // -----------------------------------------------------------------------
            // 3. Resolve section and entry type.
            // -----------------------------------------------------------------------
            $sectionHandle   = $config['section'] ?? 'pages';
            $entryTypeHandle = $config['entryType'] ?? 'page';

            $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
            if ($section === null) {
                return $this->_fatal($result, "Section '{$sectionHandle}' not found in Craft. Check 'section' in config/copydeck.php.");
            }

            $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
            if ($entryType === null) {
                return $this->_fatal($result, "Entry type '{$entryTypeHandle}' not found in Craft. Check 'entryType' in config/copydeck.php.");
            }

            // -----------------------------------------------------------------------
            // 4. Prepare ImageImportService (resolves volume + folder once).
            // -----------------------------------------------------------------------
            $volumeHandle = $config['assetVolume'] ?? 'images';
            $folderPath   = $config['assetFolder'] ?? 'copydeck';

            CopydeckImporter::$plugin->images->prepare($volumeHandle, $folderPath);

            // -----------------------------------------------------------------------
            // 5. Prepare MatrixBuilder (builds merged mapping once).
            // -----------------------------------------------------------------------
            CopydeckImporter::$plugin->matrixBuilder->prepare($config);

            // -----------------------------------------------------------------------
            // 6. Build Matrix field data from blocks.
            //    Hero blocks are handled separately (entry.hero field, not contentBlocks).
            // -----------------------------------------------------------------------
            $blocks = $data['blocks'] ?? [];

            // Extract hero block before passing to MatrixBuilder.
            // CTA blocks pass through MatrixBuilder (it skips them with a report)
            // and are resolved separately after the entry is available.
            $heroBlock     = null;
            $ctaBlocks     = [];
            $contentBlocks = [];
            foreach ($blocks as $block) {
                $blockType = $block['type'] ?? '';
                if ($blockType === 'hero') {
                    $heroBlock = $block;
                } else {
                    if ($blockType === 'call_to_action') {
                        $ctaBlocks[] = $block;
                    }
                    $contentBlocks[] = $block;
                }
            }

            $built = CopydeckImporter::$plugin->matrixBuilder->build($contentBlocks, $dryRun);

            $result['blocks']      = $built['blockReport'];
            $result['images']      = $built['imageReport'];

            // -----------------------------------------------------------------------
            // 7. Resolve SEO field values and hero field.
            // -----------------------------------------------------------------------
            $seoValues    = $this->_resolveSeoFields($data['seo'] ?? [], $config, $dryRun);
            $seoPopulated = array_filter($seoValues, fn($v) => $v !== '' && $v !== null && $v !== []);
            $result['seoFieldCount'] = count($seoPopulated);

            $heroData = $heroBlock !== null ? $this->_buildHeroField($heroBlock, $dryRun) : null;

            // -----------------------------------------------------------------------
            // 8. Find existing entry by slug.
            // -----------------------------------------------------------------------
            $existing = Entry::find()
                ->section($sectionHandle)
                ->slug($slug)
                ->status(null)
                ->one();

            if ($existing !== null) {
                $result['entryFound'] = true;
                $result['entryId']    = $existing->id;
            }

            // -----------------------------------------------------------------------
            // 9. Dry run — stop before any writes.
            // -----------------------------------------------------------------------
            if ($dryRun) {
                $result['success'] = true;

                return $result;
            }

            // -----------------------------------------------------------------------
            // 10. Resolve CTA blocks → create callToActionEntry entries and
            //     patch their IDs into the matrixData placeholders.
            // -----------------------------------------------------------------------
            $matrixData = $built['matrixData'];
            $ctaIndex   = 0;

            foreach ($matrixData as $key => &$entry) {
                if (empty($entry['_cta'])) {
                    continue;
                }

                unset($entry['_cta']);

                if (!isset($ctaBlocks[$ctaIndex])) {
                    $ctaIndex++;
                    continue;
                }

                $ctaEntryId = $this->_resolveCtaEntry(
                    $ctaBlocks[$ctaIndex],
                    $result,
                    $dryRun,
                );
                $ctaIndex++;

                if ($ctaEntryId !== null) {
                    $entry['fields']['chooseCallToAction'] = [$ctaEntryId];
                }
            }
            unset($entry);

            // -----------------------------------------------------------------------
            // 11. Build the complete field values array.
            // -----------------------------------------------------------------------
            $matrixHandle = $config['matrixField'] ?? 'contentBlocks';

            $fieldValues = array_merge(
                [$matrixHandle => $matrixData],
                $heroData ?? [],
                $seoValues,
            );

            // -----------------------------------------------------------------------
            // 11a. Existing entry → overwrite content directly.
            // -----------------------------------------------------------------------
            if ($existing !== null) {
                $filteredValues = $this->_filterToValidFields($fieldValues, $existing->getFieldLayout(), $result);
                $result['seoFieldCount'] = $this->_countSeoFields($filteredValues, $config);

                $existing->title = $title;
                $existing->setFieldValues($filteredValues);

                if (!Craft::$app->getElements()->saveElement($existing, false)) {
                    $errors = implode(', ', $existing->getFirstErrors());

                    return $this->_fatal($result, "Failed to save entry: {$errors}");
                }

                $result['entryId'] = $existing->id;
                $result['success'] = true;

                return $result;
            }

            // -----------------------------------------------------------------------
            // 11b. No existing entry → create and publish directly.
            // -----------------------------------------------------------------------
            $entry = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId    = $entryType->id;
            $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
            $entry->title     = $title;
            $entry->slug      = $slug;

            $filteredValues = $this->_filterToValidFields($fieldValues, $entryType->getFieldLayout(), $result);
            $result['seoFieldCount'] = $this->_countSeoFields($filteredValues, $config);

            $entry->setFieldValues($filteredValues);

            $saved = Craft::$app->getElements()->saveElement($entry, false);

            if (!$saved) {
                $errors = implode(', ', $entry->getFirstErrors());
                $this->_logNestedErrors($entry);

                return $this->_fatal($result, "Failed to save entry: {$errors}");
            }

            $result['entryId'] = $entry->id;
            $result['success'] = true;
        } catch (Throwable $e) {
            Craft::error("CopydeckImporter exception: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

            return $this->_fatal($result, 'Exception: ' . $e->getMessage());
        }

        return $result;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns an empty result skeleton.
     *
     * @return array
     */
    private function _emptyResult(): array
    {
        return [
            'success'       => false,
            'slug'          => '',
            'entryId'       => null,
            'entryFound'    => false,
            'seoFieldCount' => 0,
            'blocks'        => [],
            'images'        => [],
            'warnings'      => [],
            'error'         => null,
        ];
    }

    /**
     * Marks the result as a fatal error and returns it.
     *
     * @param array  $result
     * @param string $message
     * @return array
     */
    private function _fatal(array $result, string $message): array
    {
        Craft::error("CopydeckImporter: $message", __METHOD__);
        $result['success'] = false;
        $result['error']   = $message;

        return $result;
    }

    /**
     * Loads and merges the copydeck config (project config + defaults).
     *
     * Cached after the first call — called once per PHP process.
     *
     * @return array
     */
    private function _getConfig(): array
    {
        if ($this->_config !== null) {
            return $this->_config;
        }

        $defaults = [
            'section'        => 'pages',
            'entryType'      => 'pages',
            'assetVolume'    => 'images',
            'assetFolder'    => 'copydeck',
            'matrixField'    => 'contentBlocks',
            'seoField'       => 'seo',
            'blockOverrides' => [],
        ];

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('copydeck');
        $merged        = array_replace_recursive($defaults, is_array($projectConfig) ? $projectConfig : []);

        $this->_config = $merged;

        return $merged;
    }

    /**
     * Resolves Copydeck SEO data into a SEOmatic SeoSettings field value array.
     *
     * SEOmatic stores all SEO data in a single field (handle: 'seo') as a
     * structured array. String values go in metaGlobalVars; source flags and
     * asset IDs go in metaBundleSettings.
     *
     * @param array $seo    The seo object from the Copydeck JSON.
     * @param array $config Merged copydeck config.
     * @param bool  $dryRun
     * @return array<string, mixed>
     */
    private function _resolveSeoFields(array $seo, array $config, bool $dryRun): array
    {
        $fieldHandle = $config['seoField'] ?? 'seo';

        $metaGlobalVars = [
            'seoTitle'       => (string)($seo['title'] ?? ''),
            'seoDescription' => (string)($seo['description'] ?? ''),
            'ogTitle'        => (string)($seo['og_title'] ?? ''),
            'ogDescription'  => (string)($seo['og_description'] ?? ''),
            'canonicalUrl'   => (string)($seo['canonical'] ?? ''),
        ];

        $metaBundleSettings = [
            'seoTitleSource'       => 'fromCustom',
            'seoDescriptionSource' => 'fromCustom',
        ];

        // og_image — import as asset and register in both seoImage and ogImage slots.
        $ogImageData = $seo['og_image'] ?? null;
        if (is_array($ogImageData) && !empty($ogImageData['url'])) {
            $imageResult = CopydeckImporter::$plugin->images->importFromField($ogImageData, $dryRun);
            if ($imageResult !== null && $imageResult['id'] !== null) {
                $metaBundleSettings['seoImageSource'] = 'fromAsset';
                $metaBundleSettings['seoImageIds']    = [$imageResult['id']];
                $metaBundleSettings['ogImageSource']  = 'fromAsset';
                $metaBundleSettings['ogImageIds']     = [$imageResult['id']];
            }
        }

        return [
            $fieldHandle => [
                'metaGlobalVars'     => $metaGlobalVars,
                'metaBundleSettings' => $metaBundleSettings,
            ],
        ];
    }

    /**
     * Filters a field values array to only handles that exist on the given field layout.
     *
     * Any handle that does not exist on the layout is dropped and a warning is added
     * to the result. This prevents "Setting unknown property" exceptions when SEO or
     * other configured field handles are not present on the entry type.
     *
     * @param array            $fieldValues
     * @param \craft\models\FieldLayout|null $fieldLayout
     * @param array            &$result     Result array, mutated to add warnings.
     * @return array
     */
    private function _filterToValidFields(array $fieldValues, ?FieldLayout $fieldLayout, array &$result): array
    {
        if ($fieldLayout === null) {
            return $fieldValues;
        }

        $validHandles = array_map(
            fn(FieldInterface $f) => $f->handle,
            $fieldLayout->getCustomFields(),
        );

        $filtered = [];

        foreach ($fieldValues as $handle => $value) {
            if (in_array($handle, $validHandles, true)) {
                $filtered[$handle] = $value;
            } else {
                $result['warnings'][] = "Field handle '{$handle}' not found on entry type field layout — skipped.";
                Craft::warning("CopydeckImporter: field '{$handle}' skipped (not in field layout).", __METHOD__);
            }
        }

        return $filtered;
    }

    /**
     * Builds a flat field values array from a Copydeck hero block.
     *
     * The pages entry type has hero fields directly on the entry (not in a Matrix):
     *   heading → heroTitle    (CKEditor, wrapped in <h1>)
     *   body    → heroRichText (CKEditor, wrapped in <p>)
     *   image   → heroDesktopImage (asset)
     *   (also sets enableHero → true so the hero is visible)
     *
     * Returns null if no mappable fields are found.
     *
     * @param array $heroBlock Copydeck hero block (the full block object).
     * @param bool  $dryRun
     * @return array<string, mixed>|null
     */
    private function _buildHeroField(array $heroBlock, bool $dryRun): ?array
    {
        $fields     = $heroBlock['fields'] ?? [];
        $heroFields = [];

        // heroTitle — heading (h1 allowed for hero, unlike content block headings).
        if (isset($fields['heading'])) {
            $heading = $fields['heading'];

            if (is_array($heading) && isset($heading['text'])) {
                $level = max(1, min(6, (int)($heading['level'] ?? 1)));
                $text  = htmlspecialchars((string)$heading['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $heroFields['heroTitle'] = "<h{$level}>{$text}</h{$level}>";
            } elseif (is_string($heading) && $heading !== '') {
                $text = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $heroFields['heroTitle'] = "<h1>{$text}</h1>";
            }
        }

        // heroRichText — body text (handle override on pages entry type field layout).
        if (isset($fields['body']) && is_string($fields['body']) && $fields['body'] !== '') {
            $escaped = htmlspecialchars($fields['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $heroFields['heroRichText'] = "<p>{$escaped}</p>";
        }

        // heroDesktopImage — primary image.
        if (isset($fields['image']) && is_array($fields['image']) && !empty($fields['image']['url'])) {
            $imageResult = CopydeckImporter::$plugin->images->importFromField($fields['image'], $dryRun);
            $heroFields['heroDesktopImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        }

        if (empty($heroFields)) {
            return null;
        }

        // Enable the hero so it is visible on the page.
        $heroFields['enableHero'] = true;

        return $heroFields;
    }

    /**
     * Logs detailed validation errors from an element's field values for debugging.
     *
     * Recursively surfaces errors from nested Matrix entries that would otherwise
     * only appear as a generic "Validation errors found in N nested entries" message.
     *
     * @param \craft\base\Element $element
     * @param string              $prefix
     * @return void
     */
    private function _logNestedErrors(\craft\base\Element $element, string $prefix = ''): void
    {
        foreach ($element->getErrors() as $attribute => $errors) {
            foreach ($errors as $error) {
                Craft::error("{$prefix}[{$attribute}] {$error}", __METHOD__);
            }
        }

        // Recurse into nested Matrix field entries via cached results.
        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if (!$field instanceof \craft\fields\Matrix) {
                continue;
            }

            $handle = $field->handle;
            $value  = $element->getFieldValue($handle);

            if (!method_exists($value, 'getCachedResult')) {
                continue;
            }

            $innerEntries = $value->getCachedResult() ?? [];

            foreach ($innerEntries as $innerEntry) {
                if (!($innerEntry instanceof \craft\base\Element) || !$innerEntry->hasErrors()) {
                    continue;
                }

                $typeHandle = $innerEntry->getType()->handle ?? 'unknown';
                Craft::error("{$prefix}  Nested [{$handle}/{$typeHandle}]:", __METHOD__);
                $this->_logNestedErrors($innerEntry, $prefix . '    ');
            }
        }
    }

    /**
     * Counts how many SEO metaGlobalVars values are non-empty.
     *
     * @param array $fieldValues Filtered field values after layout check.
     * @param array $config      Merged copydeck config.
     * @return int
     */
    private function _countSeoFields(array $fieldValues, array $config): int
    {
        $fieldHandle = $config['seoField'] ?? 'seo';
        $seoValue    = $fieldValues[$fieldHandle] ?? null;

        if (!is_array($seoValue)) {
            return 0;
        }

        $count = 0;

        foreach ($seoValue['metaGlobalVars'] ?? [] as $value) {
            if ($value !== '' && $value !== null) {
                $count++;
            }
        }

        // Count image if present.
        if (!empty($seoValue['metaBundleSettings']['seoImageIds'])) {
            $count++;
        }

        return $count;
    }

    /**
     * Creates (or finds) a callToActionEntry from a Copydeck CTA block.
     *
     * Extracts title from the first heading node, renders richText from nodes,
     * imports image if present, and builds actionButtons Matrix entries from
     * the buttons array.
     *
     * Idempotent: matches existing entries by title to avoid duplicates.
     *
     * @param array  $ctaBlock  The raw CTA block from the JSON.
     * @param array  &$result   Result array, mutated to add warnings/images.
     * @param bool   $dryRun
     * @return int|null The CTA entry ID, or null on failure.
     */
    private function _resolveCtaEntry(array $ctaBlock, array &$result, bool $dryRun): ?int
    {
        $fields = $ctaBlock['fields'] ?? [];
        $nodes  = $fields['nodes'] ?? [];

        // Extract title from first heading node.
        $title = 'Call to Action';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'heading' && !empty($node['text'])) {
                $title = (string)$node['text'];
                break;
            }
        }

        // Idempotency — find existing CTA entry by title.
        $existing = Entry::find()
            ->section('callsToAction')
            ->title($title)
            ->status(null)
            ->one();

        if ($existing !== null) {
            return $existing->id;
        }

        if ($dryRun) {
            return null;
        }

        // Resolve section and entry type.
        $section = Craft::$app->entries->getSectionByHandle('callsToAction');
        if ($section === null) {
            $result['warnings'][] = "Section 'callsToAction' not found — CTA block skipped.";

            return null;
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle('callToActionEntry');
        if ($entryType === null) {
            $result['warnings'][] = "Entry type 'callToActionEntry' not found — CTA block skipped.";

            return null;
        }

        // Build field values.
        $ctaFieldValues = [];

        // richText — render nodes to HTML.
        $ctaFieldValues['richText'] = CopydeckImporter::$plugin->nodes->render(
            is_array($nodes) ? $nodes : [],
        );

        // image — import if present.
        $imageData = $fields['image'] ?? null;
        if (is_array($imageData) && !empty($imageData['url'])) {
            $imageResult = CopydeckImporter::$plugin->images->importFromField($imageData, $dryRun);
            if ($imageResult !== null && $imageResult['id'] !== null) {
                $ctaFieldValues['image'] = [$imageResult['id']];
                $result['images'][] = ['filename' => $imageResult['filename'], 'reused' => $imageResult['reused']];
            }
        }

        // actionButtons — Matrix field with actionButton inner entries.
        $buttons = $fields['buttons'] ?? [];
        if (!empty($buttons) && is_array($buttons)) {
            $ctaFieldValues['actionButtons'] = $this->_buildActionButtons($buttons);
        }

        // Create the CTA entry.
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId    = $entryType->id;
        $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title     = $title;

        $entry->setFieldValues($ctaFieldValues);

        $saved = Craft::$app->getElements()->saveElement($entry, false);

        if (!$saved) {
            $errors = implode(', ', $entry->getFirstErrors());
            $result['warnings'][] = "Failed to create CTA entry '{$title}': {$errors}";
            Craft::warning("CopydeckImporter: CTA entry save failed: {$errors}", __METHOD__);

            return null;
        }

        return $entry->id;
    }

    /**
     * Builds the actionButtons Matrix field data from a Copydeck buttons array.
     *
     * Each button becomes an actionButton entry with a Hyper actionButton field.
     * Buttons without a URL are skipped.
     *
     * @param array $buttons [{label, url}, ...]
     * @return array<string, array> Matrix data keyed by 'new1', 'new2', etc.
     */
    private function _buildActionButtons(array $buttons): array
    {
        $matrixData = [];
        $counter    = 0;

        foreach ($buttons as $button) {
            if (!is_array($button)) {
                continue;
            }

            $label = (string)($button['label'] ?? '');
            $url   = $button['url'] ?? null;

            // Skip buttons without a URL — they can't be linked.
            if (empty($url)) {
                continue;
            }

            // Hyper field serialized format: array of link objects.
            $hyperValue = [
                [
                    'type'      => 'verbb\\hyper\\links\\Url',
                    'handle'    => 'default-verbb-hyper-links-url',
                    'linkValue' => (string)$url,
                    'linkText'  => $label,
                ],
            ];

            $matrixData['new' . (++$counter)] = [
                'type'   => 'actionButton',
                'fields' => [
                    'actionButton' => $hyperValue,
                ],
            ];
        }

        return $matrixData;
    }
}

<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\LinkHelper;
use yii\base\Component;

/**
 * Builds the Matrix field data array from ContentIQ blocks.
 *
 * Each block maps to an outer Craft contentBlocks entry type that contains a nested
 * inner Matrix field. The mapping is defined in config/defaults.php and can be
 * overridden per-project via blockOverrides in config/contentiq.php.
 *
 * Structure per block mapping:
 *   outerType   — handle of the Craft contentBlocks entry type
 *   outerFields — fields set directly on the outer entry (e.g. layout dropdowns)
 *   innerMatrix — nested Matrix: outerField, innerType, mode (single|repeated|grouped), fields
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class MatrixBuilder extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Resolved mapping (defaults merged with any blockOverrides), built once.
     *
     * @var array<string, array>|null
     */
    private ?array $_mapping = null;

    /**
     * User-facing warnings raised while building the current blocks array.
     *
     * Reset at the start of every {@see build()} call and returned under the
     * `warnings` key so ImportService can merge them into the page result.
     *
     * @var string[]
     */
    private array $_warnings = [];

    // Public Methods
    // =========================================================================

    /**
     * Initialises the builder with the merged block mapping.
     *
     * @param array $config Merged contentiq config (defaults + project overrides).
     * @return void
     */
    public function prepare(array $config): void
    {
        $defaults  = require dirname(__DIR__) . '/config/defaults.php';
        $overrides = $config['blockOverrides'] ?? [];

        // content_types is page-routing config, not a block mapping — drop it here.
        unset($defaults['content_types']);

        // Overrides replace entire block definitions — not merged at field level.
        $this->_mapping = array_replace($defaults, $overrides);
    }

    /**
     * Builds the Matrix field data array and the block report from a ContentIQ blocks array.
     *
     * @param array[] $blocks       ContentIQ `blocks` array from the JSON.
     * @param bool    $dryRun       If true, skips image downloads.
     * @param string  $hostPageSlug The slug of the page being imported (for children mode parent==host detection).
     * @return array{
     *   matrixData: array<string, array>,
     *   blockReport: array<int, array{type: string, fields: string[], skipped: bool}>,
     *   imageReport: array<int, array{filename: string, reused: bool}>,
     *   cardRefs: array<int, array{blockIndex: int, mode: string, refs?: array, parent?: array}>,
     *   warnings: string[]
     * }
     */
    public function build(array $blocks, bool $dryRun = false, string $hostPageSlug = ''): array
    {
        $matrixData     = [];
        $blockReport    = [];
        $imageReport    = [];
        $cardRefs       = [];  // Deferred card references (in-memory, never persisted to Craft)
        $counter        = 0;
        $this->_warnings = [];

        // Pre-process: group consecutive blocks that use 'grouped' mode.
        $processedBlocks = $this->_groupConsecutiveBlocks($blocks);

        foreach ($processedBlocks as $item) {
            $type = $item['type'] ?? '';

            // CTA blocks are handled by ImportService after MatrixBuilder runs.
            // We emit them as placeholders in matrixData so positioning is preserved.
            if ($type === 'call_to_action') {
                $key = 'new' . (++$counter);
                $matrixData[$key] = [
                    'type'   => 'callToAction',
                    'fields' => [],
                    '_cta'   => true,
                ];
                $blockReport[] = ['type' => $type, 'fields' => ['chooseCallToAction'], 'skipped' => false];
                continue;
            }

            if (!isset($this->_mapping[$type])) {
                Craft::warning("Unknown ContentIQ block type '{$type}' — skipping.", __METHOD__);
                $blockReport[] = ['type' => $type, 'fields' => [], 'skipped' => true];
                continue;
            }

            $mapping = $this->_mapping[$type];

            // Grouped blocks arrive as a single item with a '_groupedBlocks' array.
            if (isset($item['_groupedBlocks'])) {
                [$outerFields, $innerMatrixData, $reportedFields] = $this->_buildGroupedBlock(
                    $mapping,
                    $item['_groupedBlocks'],
                    $imageReport,
                    $dryRun,
                );
            } else {
                $sourceFields = $item['fields'] ?? [];

                [$outerFields, $innerMatrixData, $reportedFields, $blockCardRefs] = $this->_buildBlock(
                    $type,
                    $mapping,
                    $sourceFields,
                    $imageReport,
                    $dryRun,
                    $hostPageSlug,
                );

                // Collect deferred card references (kept in memory, never persisted to Craft)
                if (!empty($blockCardRefs)) {
                    $cardRefs[$counter] = array_merge(['blockIndex' => $counter], $blockCardRefs);
                }
            }

            $allFields = array_merge($outerFields, $innerMatrixData);

            // Populate contentiqNotes from the block-level notes key if present.
            // For grouped blocks, combine notes from all blocks in the group.
            $notesSources = isset($item['_groupedBlocks'])
                ? array_column($item['_groupedBlocks'], 'notes')
                : [$item['notes'] ?? ''];
            $notes = implode("\n\n", array_filter(array_map('trim', $notesSources)));
            if ($notes !== '') {
                $allFields['contentiqNotes'] = $notes;
            }

            $key              = 'new' . (++$counter);
            $matrixData[$key] = ['type' => $mapping['outerType'], 'fields' => $allFields];

            $innerCount = isset($item['_groupedBlocks']) ? count($item['_groupedBlocks']) : 1;
            $blockReport[] = [
                'type'   => $type,
                'fields' => $reportedFields,
                'skipped' => false,
                'innerCount' => $innerCount,
            ];
        }

        return [
            'matrixData'  => $matrixData,
            'blockReport' => $blockReport,
            'imageReport' => $imageReport,
            'cardRefs'    => $cardRefs,  // Deferred card refs (passed to ImportService, then SyncJob)
            'warnings'    => $this->_warnings,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Groups consecutive blocks that use 'grouped' mode into a single entry.
     *
     * Consecutive blocks of the same type where the mapping has mode 'grouped'
     * are merged into one item with a '_groupedBlocks' array. All other blocks
     * pass through unchanged.
     *
     * @param array[] $blocks
     * @return array[]
     */
    private function _groupConsecutiveBlocks(array $blocks): array
    {
        $result     = [];
        $groupBuffer = [];
        $groupType   = null;

        foreach ($blocks as $block) {
            $type    = $block['type'] ?? '';
            $mapping = $this->_mapping[$type] ?? null;
            $mode    = $mapping['innerMatrix']['mode'] ?? null;

            if ($mode === 'grouped' && $type === $groupType) {
                // Continue the current group.
                $groupBuffer[] = $block;
                continue;
            }

            // Flush any pending group.
            if (!empty($groupBuffer)) {
                $result[] = [
                    'type'            => $groupType,
                    '_groupedBlocks'  => $groupBuffer,
                ];
                $groupBuffer = [];
                $groupType   = null;
            }

            if ($mode === 'grouped') {
                // Start a new group.
                $groupBuffer = [$block];
                $groupType   = $type;
            } else {
                $result[] = $block;
            }
        }

        // Flush final group.
        if (!empty($groupBuffer)) {
            $result[] = [
                'type'            => $groupType,
                '_groupedBlocks'  => $groupBuffer,
            ];
        }

        return $result;
    }

    /**
     * Builds outer fields and inner Matrix data for a grouped set of blocks.
     *
     * Each block in the group becomes one inner entry. Outer fields are taken
     * from the first block only (they're typically the same across the group).
     *
     * @param array   $mapping       Block mapping definition from defaults.php.
     * @param array[] $groupedBlocks Array of ContentIQ blocks to merge.
     * @param array   &$imageReport
     * @param bool    $dryRun
     * @return array{0: array, 1: array, 2: string[]}
     */
    private function _buildGroupedBlock(
        array $mapping,
        array $groupedBlocks,
        array &$imageReport,
        bool $dryRun,
    ): array {
        $outerFields    = [];
        $reportedFields = [];

        // Resolve outer fields from the first block only.
        $firstSourceFields = $groupedBlocks[0]['fields'] ?? [];
        foreach ($mapping['outerFields'] ?? [] as $contentiqKey => [$craftHandle, $handlerType]) {
            $value    = $firstSourceFields[$contentiqKey] ?? null;
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                $outerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        $innerConfig = $mapping['innerMatrix'] ?? null;

        if ($innerConfig === null) {
            return [$outerFields, [], $reportedFields];
        }

        $outerField   = $innerConfig['outerField'];
        $innerEntries = [];
        $innerCounter = 0;

        foreach ($groupedBlocks as $block) {
            $sourceFields = $block['fields'] ?? [];

            [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                $innerConfig,
                $sourceFields,
                $imageReport,
                $dryRun,
            );

            $innerKey                = 'new' . (++$innerCounter);
            $innerEntries[$innerKey] = [
                'type'   => $innerConfig['innerType'],
                'fields' => $innerFields,
            ];

            // Report field names from the first item only.
            if ($innerCounter === 1) {
                $reportedFields = array_merge($reportedFields, $innerReportedFields);
            }
        }

        $innerMatrixData = [$outerField => $innerEntries];

        return [$outerFields, $innerMatrixData, $reportedFields, []];
    }

    /**
     * Builds the outer fields and inner Matrix data for a single block.
     *
     * Returns a tuple of [outerFields, innerMatrixData, reportedFields, cardRefs].
     * cardRefs is non-empty only for cards blocks in pages/children modes.
     *
     * @param string $blockType     ContentIQ block type ('cards', 'text', etc).
     * @param array  $mapping       Block mapping definition from defaults.php.
     * @param array  $sourceFields  ContentIQ fields for this block.
     * @param array  &$imageReport  Image report array, mutated by image handlers.
     * @param bool   $dryRun
     * @param string $hostPageSlug  The slug of the page being imported (for children mode).
     * @return array{0: array, 1: array, 2: string[], 3: array}
     *         [outerFields, innerMatrixData, reportedFields, cardRefs]
     */
    private function _buildBlock(
        string $blockType,
        array $mapping,
        array $sourceFields,
        array &$imageReport,
        bool $dryRun,
        string $hostPageSlug,
    ): array {
        $outerFields    = [];
        $reportedFields = [];
        $cardRefs       = [];  // Deferred refs for pages/children modes

        // Resolve outer fields (e.g. layout dropdown on the outer entry type).
        foreach ($mapping['outerFields'] ?? [] as $contentiqKey => [$craftHandle, $handlerType]) {
            // '_block' is a special key meaning "pass the entire source fields to the handler".
            $value   = $contentiqKey === '_block' ? $sourceFields : ($sourceFields[$contentiqKey] ?? null);
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                // Internal keys (prefixed _) are injected into sourceFields for
                // inner matrix resolution — they are not Craft field values.
                if (str_starts_with($handle, '_')) {
                    $sourceFields[$handle] = $fieldValue;
                    continue;
                }
                $outerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        // Special handling for cards block mode branching (pages/children modes).
        // For these modes, return deferred card refs and skip inner matrix building.
        if ($blockType === 'cards') {
            $cardMode = $sourceFields['mode'] ?? 'detected';
            if ($cardMode === 'pages' || $cardMode === 'children') {
                [$cardOuterFields, $cardRefs] = $this->_buildCardsByMode(
                    $cardMode,
                    $sourceFields,
                    $outerFields,
                    $reportedFields,
                    $imageReport,
                    $dryRun,
                    $hostPageSlug,
                );
                // Merge card mode fields into outerFields
                foreach ($cardOuterFields as $handle => $value) {
                    if (!isset($outerFields[$handle])) {
                        $outerFields[$handle] = $value;
                        $reportedFields[] = $handle;
                    }
                }
                // Return without inner matrix — deferred refs travel in memory via $cardRefs
                return [$outerFields, [], $reportedFields, $cardRefs];
            }
            // For detected mode, set cardsInThisBlock='manual' and continue to inner card matrix
            $outerFields['cardsInThisBlock'] = 'manual';
            $reportedFields[] = 'cardsInThisBlock';
            $outerFields['useChildPages'] = false;
            $reportedFields[] = 'useChildPages';
        }

        // Build inner Matrix data.
        $innerConfig = $mapping['innerMatrix'] ?? null;

        if ($innerConfig === null) {
            return [$outerFields, [], $reportedFields, []];  // Empty cardRefs for non-card blocks
        }

        $outerField    = $innerConfig['outerField'];
        $mode          = $innerConfig['mode'] ?? 'single';
        $innerEntries  = [];

        if ($mode === 'single') {
            [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                $innerConfig,
                $sourceFields,
                $imageReport,
                $dryRun,
            );

            $innerEntries['new1'] = [
                'type'   => $innerConfig['innerType'],
                'fields' => $innerFields,
            ];

            $reportedFields = array_merge($reportedFields, $innerReportedFields);
        } elseif ($mode === 'text_columns') {
            $columns = $sourceFields['columns'] ?? 'singleColumn';
            $nodes   = $sourceFields['nodes'] ?? [];

            // Find the first heading node to determine the split point.
            $headingIndex = -1;
            if ($columns === 'twoColumns') {
                foreach ($nodes as $i => $node) {
                    if (($node['type'] ?? '') === 'heading') {
                        $headingIndex = $i;
                        break;
                    }
                }
            }

            if ($columns === 'twoColumns' && $headingIndex !== -1) {
                // Left column: nodes up to and including the first heading.
                $firstNodes  = array_values(array_slice($nodes, 0, $headingIndex + 1));
                $secondNodes = array_values(array_slice($nodes, $headingIndex + 1));

                [$innerFields1, $innerReportedFields] = $this->_buildSingleInnerEntry(
                    $innerConfig,
                    array_merge($sourceFields, ['nodes' => $firstNodes]),
                    $imageReport,
                    $dryRun,
                );
                $innerEntries['new1'] = [
                    'type'   => $innerConfig['innerType'],
                    'fields' => $innerFields1,
                ];

                if (!empty($secondNodes)) {
                    [$innerFields2] = $this->_buildSingleInnerEntry(
                        $innerConfig,
                        array_merge($sourceFields, ['nodes' => $secondNodes]),
                        $imageReport,
                        $dryRun,
                    );
                    $innerEntries['new2'] = [
                        'type'   => $innerConfig['innerType'],
                        'fields' => $innerFields2,
                    ];
                }

                $reportedFields = array_merge($reportedFields, $innerReportedFields);
            } else {
                // singleColumn or twoColumns with no heading — one inner entry.
                [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                    $innerConfig,
                    $sourceFields,
                    $imageReport,
                    $dryRun,
                );
                $innerEntries['new1'] = [
                    'type'   => $innerConfig['innerType'],
                    'fields' => $innerFields,
                ];
                $reportedFields = array_merge($reportedFields, $innerReportedFields);
            }
        } else {
            $sourceKey   = $innerConfig['sourceKey'] ?? '';
            $items       = $sourceFields[$sourceKey] ?? [];

            // Fallback source key — used when primary is empty. FAQ items now
            // come from _faqItems (merged from fields.nodes' faq_items nodes via
            // faqNodes); the flat fields.items array is legacy — present only on
            // exports from older ContentIQ instances.
            if (empty($items) && isset($innerConfig['fallbackSourceKey'])) {
                $items = $sourceFields[$innerConfig['fallbackSourceKey']] ?? [];
            }
            $innerCounter = 0;

            foreach ($items as $item) {
                [$innerFields, $innerReportedFields] = $this->_buildSingleInnerEntry(
                    $innerConfig,
                    is_array($item) ? $item : [],
                    $imageReport,
                    $dryRun,
                );

                $innerKey                  = 'new' . (++$innerCounter);
                $innerEntries[$innerKey]   = [
                    'type'   => $innerConfig['innerType'],
                    'fields' => $innerFields,
                ];

                // Only report field names from the first item — they're all the same.
                if ($innerCounter === 1) {
                    $reportedFields = array_merge($reportedFields, $innerReportedFields);
                }
            }
        }

        $innerMatrixData = [$outerField => $innerEntries];

        return [$outerFields, $innerMatrixData, $reportedFields, []];
    }

    /**
     * Resolves a single inner entry's fields from a source fields array.
     *
     * Used for both 'single' mode (resolves from the block's top-level fields)
     * and 'repeated' mode (resolves from each item in the source array).
     *
     * @param array  $innerConfig  The innerMatrix config.
     * @param array  $sourceFields The source data (block fields or item from repeated array).
     * @param array  &$imageReport
     * @param bool   $dryRun
     * @return array{0: array<string, mixed>, 1: string[]}
     */
    private function _buildSingleInnerEntry(
        array $innerConfig,
        array $sourceFields,
        array &$imageReport,
        bool $dryRun,
    ): array {
        $innerFields    = [];
        $reportedFields = [];

        foreach ($innerConfig['fields'] as $contentiqKey => [$craftHandle, $handlerType]) {
            // '_block' passes the entire source fields to the handler (e.g. when the
            // output is derived from multiple keys, like mediaType + image routing).
            $value    = $contentiqKey === '_block' ? $sourceFields : ($sourceFields[$contentiqKey] ?? null);
            $resolved = $this->_resolveFieldByHandler($handlerType, $craftHandle, $value, $imageReport, $dryRun);

            foreach ($resolved as $handle => $fieldValue) {
                $innerFields[$handle] = $fieldValue;
                $reportedFields[]     = $handle;
            }
        }

        return [$innerFields, $reportedFields];
    }

    /**
     * Resolves a single field value using the specified handler type.
     *
     * Returns an associative array keyed by Craft field handle(s).
     *
     * @param string     $handlerType  Handler type string from the block mapping.
     * @param string     $craftHandle  Destination Craft field handle.
     * @param mixed      $value        Raw value from the ContentIQ source data.
     * @param array      &$imageReport Image report array, mutated by image handlers.
     * @param bool       $dryRun
     * @return array<string, mixed>
     */
    private function _resolveFieldByHandler(
        string $handlerType,
        string $craftHandle,
        mixed $value,
        array &$imageReport,
        bool $dryRun,
    ): array {
        return match ($handlerType) {
            'nodes'                  => $this->_handleNodes($craftHandle, $value),
            'mediaNodes'             => $this->_handleMediaNodes($craftHandle, $value),
            'textMediaMedia'         => $this->_handleTextMediaMedia($value, $imageReport, $dryRun),
            'image'                  => $this->_handleImage($craftHandle, $value, $imageReport, $dryRun),
            'images'                 => $this->_handleImages($craftHandle, $value, $imageReport, $dryRun),
            'heading'                => $this->_handleHeading($craftHandle, $value),
            'body'                   => $this->_handleBody($craftHandle, $value),
            'layout'                 => $this->_handlePassThrough($craftHandle, $value),
            'textMediaLayout'        => $this->_handleTextMediaLayout($craftHandle, $value),
            'tableHtml'              => $this->_handleTableHtml($craftHandle, $value),
            'hyperButton'            => $this->_handleHyperButton($craftHandle, $value),
            'buttonLabel'            => $this->_handleButtonLabel($craftHandle, $value),
            'faqNodes'               => $this->_handleFaqNodes($craftHandle, $value),
            'buttonNodes'            => $this->_handleButtonNodes($craftHandle, $value),
            'uspContent'             => $this->_handleUspContent($craftHandle, $value),
            'collectionSection'      => $this->_handleCollectionSection($craftHandle, $value),
            'collectionListingNodes' => $this->_handleCollectionListingNodes($craftHandle, $value),
            default                  => $this->_handlePassThrough($craftHandle, $value),
        };
    }

    /**
     * Renders a ContentIQ nodes array to an HTML string.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleNodes(string $handle, mixed $value): array
    {
        $html = ContentIQImporter::$plugin->nodes->render(is_array($value) ? $value : []);

        return [$handle => $html];
    }

    /**
     * Renders a nodes array to richText while lifting ctaButton nodes into an actionButtons Matrix.
     *
     * Used by blocks (e.g. text_and_media) whose entry type has both a richText
     * field and an actionButtons Matrix. ctaButton nodes are removed from the
     * rendered HTML and emitted as managed Action Button entries instead of raw
     * <a> tags — matching the behaviour of the faq and price_list blocks.
     *
     * @param string $handle The richText field handle.
     * @param mixed  $value  ContentNode[] that may contain ctaButton nodes.
     * @return array<string, mixed>
     */
    private function _handleMediaNodes(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            $value = [];
        }

        $contentNodes = array_values(array_filter(
            $value,
            static fn($node) => ($node['type'] ?? '') !== 'ctaButton',
        ));

        $result = [$handle => ContentIQImporter::$plugin->nodes->render($contentNodes)];

        $actionButtons = $this->_buildActionButtonsMatrix($value);
        if (!empty($actionButtons)) {
            $result['actionButtons'] = $actionButtons;
        }

        return $result;
    }

    /**
     * Resolves the mediaType and image fields for a text_and_media inner block.
     *
     * The block's `layout` selects the Craft media treatment:
     *   - 'background' → mediaType 'backgroundImage'; the images render as a
     *     full-bleed CSS background, so they are routed to the desktop/mobile
     *     background image fields the template reads (NOT the standard image field).
     *     `image` is the desktop background; the optional `mobile_image` is the
     *     mobile background, falling back to the desktop image when not supplied.
     *   - anything else → mediaType 'image'; the image is set on the image field
     *     (the left/right position comes from the outer entry's blockLayout).
     *
     * Receives the entire inner block fields array (via the '_block' source key).
     *
     * @param mixed $value        The inner block fields array ({nodes, image, mobile_image, layout}).
     * @param array &$imageReport Image report array, mutated by the image handler.
     * @param bool  $dryRun
     * @return array<string, mixed>
     */
    private function _handleTextMediaMedia(mixed $value, array &$imageReport, bool $dryRun): array
    {
        $fields = is_array($value) ? $value : [];
        $layout = (string)($fields['layout'] ?? '');
        $image  = $fields['image'] ?? null;

        if ($layout === 'background') {
            $desktopIds = $this->_handleImage('desktopBackgroundImage', $image, $imageReport, $dryRun)['desktopBackgroundImage'];

            // Optional mobile background — fall back to the desktop image when absent
            // so mobile doesn't drop to the global placeholder.
            $mobileImage = $fields['mobile_image'] ?? null;
            $mobileIds   = is_array($mobileImage) && !empty($mobileImage['url'])
                ? $this->_handleImage('mobileBackgroundImage', $mobileImage, $imageReport, $dryRun)['mobileBackgroundImage']
                : $desktopIds;

            return [
                'mediaType'              => 'backgroundImage',
                'desktopBackgroundImage' => $desktopIds,
                'mobileBackgroundImage'  => $mobileIds,
            ];
        }

        return ['mediaType' => 'image'] + $this->_handleImage('image', $image, $imageReport, $dryRun);
    }

    /**
     * Builds CKEditor HTML from a USP block's fields.
     *
     * The ContentIQ USP block sends content as two separate keys:
     *   heading → {level: int, text: string} → <hN>text</hN>
     *   items   → string[]                  → <ul><li>…</li></ul>
     *
     * Falls back to rendering a 'nodes' array if present (legacy / future format).
     *
     * @param string $handle
     * @param mixed  $value  Entire block fields array (passed via '_block' contentiqKey).
     * @return array<string, string>
     */
    private function _handleUspContent(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            return [$handle => ''];
        }

        // Fallback: if a 'nodes' array is present, render it via NodesRenderer.
        if (!empty($value['nodes']) && is_array($value['nodes'])) {
            $html = ContentIQImporter::$plugin->nodes->render($value['nodes']);
            return [$handle => $html];
        }

        $html = '';

        // heading — {level, text}
        $heading = $value['heading'] ?? null;
        if (is_array($heading) && isset($heading['text']) && (string)$heading['text'] !== '') {
            $level = max(1, min(6, (int)($heading['level'] ?? 2)));
            $text  = htmlspecialchars((string)$heading['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= "<h{$level}>{$text}</h{$level}>";
        }

        // items — flat string array → <ul>
        $items = $value['items'] ?? [];
        if (!empty($items) && is_array($items)) {
            $lis = '';
            foreach ($items as $item) {
                $text  = htmlspecialchars((string)$item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lis  .= "<li>{$text}</li>";
            }
            $html .= "<ul>{$lis}</ul>";
        }

        return [$handle => $html];
    }

    /**
     * Resolves a ContentIQ collection slug to a Craft section handle.
     *
     * The wire value is a collection slug from the same vocabulary as
     * `document.content_type` (e.g. `case_studies`). It is resolved to the
     * Craft section handle through the content_types routing map. An unmapped
     * slug is stored raw and raises a user-facing warning (mirroring the
     * treatment of unmapped content types).
     *
     * @param string $handle Destination Craft field handle (e.g. 'listingSection').
     * @param mixed  $value  ContentIQ collection slug string.
     * @return array<string, string>
     */
    private function _handleCollectionSection(string $handle, mixed $value): array
    {
        $slug = is_string($value) ? trim($value) : '';

        if ($slug === '') {
            return [$handle => ''];
        }

        $section = ContentIQImporter::$plugin->imports->getContentTypesMap()[$slug]['section'] ?? null;

        if ($section === null) {
            Craft::warning("ContentIQ collection_listing references unknown collection '{$slug}' — storing raw slug.", __METHOD__);
            $this->_warnings[] = "Collection Listing references unknown collection '{$slug}' — stored the raw slug; map it under ContentiQ → Mappings (or add a content_types override in config/contentiq.php).";

            return [$handle => $slug];
        }

        return [$handle => $section];
    }

    /**
     * Renders Collection Listing intro nodes, dropping bracketed listing
     * placeholders first.
     *
     * ContentiQ authors mark where the listing sits with a paragraph like
     * "[Blog Listing]", "[Team Listing]" or "[Listing Grid]". The rendered
     * listing takes that space in Craft, so any paragraph whose entire text is
     * square-bracketed and contains the word "listing" or "grid" is dropped
     * before the remaining nodes render to HTML.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleCollectionListingNodes(string $handle, mixed $value): array
    {
        $nodes = is_array($value) ? $value : [];

        $nodes = array_values(array_filter($nodes, function (mixed $node): bool {
            if (!is_array($node) || ($node['type'] ?? '') !== 'paragraph') {
                return true;
            }

            $text = is_scalar($node['text'] ?? null) ? trim((string)$node['text']) : '';

            return !preg_match('/^\[[^\[\]]*\b(listings?|grids?)\b[^\[\]]*\]$/i', $text);
        }));

        return [$handle => ContentIQImporter::$plugin->nodes->render($nodes)];
    }

    /**
     * Downloads (or dry-runs) an image and returns an asset ID array.
     *
     * Mutates $imageReport with a record of the image for output display.
     *
     * @param string $handle
     * @param mixed  $value
     * @param array  &$imageReport
     * @param bool   $dryRun
     * @return array<string, int[]>
     */
    private function _handleImage(string $handle, mixed $value, array &$imageReport, bool $dryRun): array
    {
        if (!is_array($value) || empty($value['url'])) {
            return [$handle => []];
        }

        $result = ContentIQImporter::$plugin->images->importFromField($value, $dryRun);

        if ($result !== null) {
            $imageReport[] = ['filename' => $result['filename'], 'reused' => $result['reused']];

            return [$handle => $result['id'] !== null ? [$result['id']] : []];
        }

        return [$handle => []];
    }

    /**
     * Imports an array of images (Custom block).
     *
     * Iterates the images array, imports each asset via ImageImportService,
     * and collects the IDs. Individual failures are non-fatal — a warning is
     * logged and the image is skipped so the rest of the block still imports.
     *
     * @param string $handle
     * @param mixed  $value   Array of {key, url, alt} objects
     * @param array  $imageReport
     * @param bool   $dryRun
     * @return array<string, array>
     */
    private function _handleImages(string $handle, mixed $value, array &$imageReport, bool $dryRun): array
    {
        if (!is_array($value) || empty($value)) {
            return [$handle => []];
        }

        $ids = [];

        foreach ($value as $img) {
            if (!is_array($img) || empty($img['url'])) {
                continue;
            }

            try {
                $result = ContentIQImporter::$plugin->images->importFromField($img, $dryRun);

                if ($result !== null) {
                    $imageReport[] = ['filename' => $result['filename'], 'reused' => $result['reused']];

                    if ($result['id'] !== null) {
                        $ids[] = $result['id'];
                    }
                }
            } catch (\Throwable $e) {
                \Craft::warning('ContentIQ: failed to import custom image "' . ($img['key'] ?? '') . '": ' . $e->getMessage(), __METHOD__);
            }
        }

        return [$handle => $ids];
    }

    /**
     * Converts a ContentIQ heading value to an HTML heading string.
     *
     * Accepts either a {level, text} object or a plain string.
     * Output is wrapped in the appropriate <hN> tag for CKEditor fields.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleHeading(string $handle, mixed $value): array
    {
        if (is_array($value) && isset($value['text'])) {
            $level = max(2, min(6, (int)($value['level'] ?? 3)));

            if (isset($value['content']) && is_array($value['content'])) {
                $inner = ContentIQImporter::$plugin->nodes->renderInlineContent($value['content']);
            } else {
                $inner = htmlspecialchars((string)$value['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            return [$handle => "<h{$level}>{$inner}</h{$level}>"];
        }

        if (is_string($value) && $value !== '') {
            $text = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return [$handle => "<h3>{$text}</h3>"];
        }

        return [$handle => ''];
    }

    /**
     * Wraps a plain string in a paragraph tag for CKEditor rich text fields.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleBody(string $handle, mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [$handle => ''];
        }

        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return [$handle => "<p>{$escaped}</p>"];
    }

    /**
     * Passes a value through unchanged.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, mixed>
     */
    private function _handlePassThrough(string $handle, mixed $value): array
    {
        return [$handle => $value];
    }

    /**
     * Maps a ContentIQ text-and-media layout string to a Craft dropdown value.
     *
     * ContentIQ layout values and their Craft equivalents:
     *   'image_right' → 'text-left'  (text on left, image on right — Craft default)
     *   'image_left'  → 'image-left' (image on left, text on right)
     *
     * Unmapped values fall back to 'text-left'.
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleTextMediaLayout(string $handle, mixed $value): array
    {
        $layoutMap = [
            'image_right' => 'text-left',
            'image_left'  => 'image-left',
        ];

        $craftValue = $layoutMap[(string)$value] ?? 'text-left';

        return [$handle => $craftValue];
    }

    /**
     * Converts a ContentIQ rows array to an HTML table string for CKEditor.
     *
     * Rows format: [{isHeader: bool, cells: [string, ...]}, ...]
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, string>
     */
    private function _handleTableHtml(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return [$handle => ''];
        }

        $thead = '';
        $tbody = '';

        foreach ($value as $row) {
            if (!is_array($row) || !isset($row['cells'])) {
                continue;
            }

            $cells = $row['cells'];
            $isHeader = !empty($row['isHeader']);
            $tag = $isHeader ? 'th' : 'td';

            $rowHtml = '<tr>';
            foreach ($cells as $cell) {
                $escaped = htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rowHtml .= "<{$tag}>{$escaped}</{$tag}>";
            }
            $rowHtml .= '</tr>';

            if ($isHeader) {
                $thead .= $rowHtml;
            } else {
                $tbody .= $rowHtml;
            }
        }

        $html = '<figure class="table"><table>';
        if ($thead !== '') {
            $html .= "<thead>{$thead}</thead>";
        }
        if ($tbody !== '') {
            $html .= "<tbody>{$tbody}</tbody>";
        }
        $html .= '</table></figure>';

        return [$handle => $html];
    }

    /**
     * Converts a ContentIQ button object to a Hyper link field value.
     *
     * Expects {label, url}. Buttons without a URL are skipped (returns empty array).
     *
     * @param string $handle
     * @param mixed  $value
     * @return array<string, array>
     */
    private function _handleHyperButton(string $handle, mixed $value): array
    {
        if (!is_array($value)) {
            return [$handle => []];
        }

        $label = (string)($value['label'] ?? '');
        $url   = (string)($value['url'] ?? '');

        if ($label === '' && $url === '') {
            return [$handle => [], 'showLinkAsSeparateButton' => false];
        }

        return [
            $handle => [$this->_buildActionButtonLink($label, $url)],
            'showLinkAsSeparateButton' => true,
        ];
    }

    /**
     * Extracts the label from a {label, url} button object and renders it as CKEditor HTML.
     *
     * @param string $handle
     * @param mixed  $value  {label: string, url: string} or null
     * @return array<string, string>
     */
    private function _handleButtonLabel(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value['label'])) {
            return [$handle => ''];
        }

        return [$handle => (string)$value['label']];
    }

    /**
     * Splits a FAQ nodes array into richText (before), accordion items, and extraRichText (after).
     *
     * The nodes array may contain headings, paragraphs, and one or more faq_items
     * nodes — ContentIQ emits one faq_items node per run of consecutive accordion
     * items, so content interleaved between two runs of accordion items (e.g. a
     * paragraph splitting one FAQ list into two) produces two separate faq_items
     * nodes rather than one.
     *
     * Content before the FIRST faq_items node → richText (rendered as HTML).
     * Every faq_items node's items are merged, in document order, into a single
     * accordion list — returned under the _faqItems key for the caller to handle.
     * Everything from the first faq_items node onwards that isn't itself a
     * faq_items node (including any content sitting between two runs) →
     * extraRichText (rendered as HTML). This is the least-surprising rule: once
     * the accordion starts, only accordion items are pulled out of the flow —
     * surrounding prose stays together in extraRichText rather than being
     * scattered back in between accordion runs.
     * ctaButton nodes are skipped throughout.
     *
     * @param string $handle Ignored — this handler returns multiple fixed handles.
     * @param mixed  $value  The nodes array from the ContentIQ FAQ block.
     * @return array<string, mixed>
     */
    private function _handleFaqNodes(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return ['richText' => '', 'extraRichText' => '', '_faqItems' => []];
        }

        $beforeNodes    = [];
        $afterNodes     = [];
        $faqItems       = [];
        $foundFaqItems  = false;

        foreach ($value as $node) {
            $type = $node['type'] ?? '';

            if ($type === 'faq_items') {
                // Merge in document order — a run's items are appended to any
                // already collected from an earlier faq_items node, rather than
                // replacing them.
                $faqItems      = array_merge($faqItems, $node['faqItems'] ?? []);
                $foundFaqItems = true;
                continue;
            }

            // ctaButton nodes are lifted into the actionButtons Matrix below.
            if ($type === 'ctaButton') {
                continue;
            }

            if ($foundFaqItems) {
                $afterNodes[] = $node;
            } else {
                $beforeNodes[] = $node;
            }
        }

        $renderer = ContentIQImporter::$plugin->nodes;

        $result = [
            'richText'      => $renderer->render($beforeNodes),
            'extraRichText' => $renderer->render($afterNodes),
            '_faqItems'     => $faqItems,
        ];

        $actionButtons = $this->_buildActionButtonsMatrix($value);
        if (!empty($actionButtons)) {
            $result['actionButtons'] = $actionButtons;
        }

        return $result;
    }

    /**
     * Converts a ContentIQ postNodes array (ContentNode[]) to an actionButtons Matrix field value.
     *
     * Only ctaButton nodes are processed — all other node types are silently ignored.
     * Nodes with no label and no URL are skipped.
     *
     * @param string $handle Craft Matrix field handle (e.g. 'actionButtons').
     * @param mixed  $value  ContentNode[] from the ContentIQ postNodes array.
     * @return array<string, array>
     */
    private function _handleButtonNodes(string $handle, mixed $value): array
    {
        if (!is_array($value) || empty($value)) {
            return [$handle => []];
        }

        return [$handle => $this->_buildActionButtonsMatrix($value)];
    }

    /**
     * Builds an actionButtons Matrix value from the ctaButton nodes in a nodes array.
     *
     * Non-ctaButton nodes are ignored, so the full block nodes array can be passed
     * in directly. Buttons with neither a label nor a URL are skipped. Returns an
     * empty array when no buttons qualify.
     *
     * @param array $nodes ContentNode[] that may contain ctaButton nodes.
     * @return array<string, array> Matrix data keyed by 'new1', 'new2', …
     */
    private function _buildActionButtonsMatrix(array $nodes): array
    {
        $actionButtonsData = [];
        $btnCounter        = 0;

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'ctaButton') {
                continue;
            }

            $label = (string)($node['label'] ?? '');
            $url   = (string)($node['url'] ?? '');

            if ($label === '' && $url === '') {
                continue;
            }

            $actionButtonsData['new' . (++$btnCounter)] = [
                'type'   => 'actionButton',
                'fields' => [
                    'actionButton' => [$this->_buildActionButtonLink($label, $url)],
                ],
            ];
        }

        return $actionButtonsData;
    }

    /**
     * Builds cards block in 'pages' or 'children' mode.
     *
     * Returns outer fields and deferred card references that will be resolved
     * by SyncJob pass 2 (kept in memory, never persisted to Craft).
     *
     * For single-page selections in pages mode, creates a manual card row instead
     * of automatic mode (D6 caveat: single-entry auto-expansion in automatic mode
     * would unintentionally expand the page to its children).
     *
     * @param string $cardMode       'pages' or 'children'.
     * @param array  $sourceFields   Block fields from ContentiQ export.
     * @param array  $outerFields    Existing outer fields (modified in place).
     * @param array  $reportedFields Field names being reported (modified in place).
     * @param array  &$imageReport   Image report array.
     * @param bool   $dryRun         If true, skips image processing.
     * @param string $hostPageSlug   Slug of the page being imported.
     * @return array{0: array, 1: array}
     *         [additionalOuterFields, cardRefs] where cardRefs = ['mode' => mode, 'refs'|'parent' => ...]
     */
    private function _buildCardsByMode(
        string $cardMode,
        array $sourceFields,
        array &$outerFields,
        array &$reportedFields,
        array &$imageReport,
        bool $dryRun,
        string $hostPageSlug,
    ): array {
        $additionalFields = [];
        $cardRefs = ['mode' => $cardMode];

        if ($cardMode === 'pages') {
            // Resolve intro field
            if (!isset($outerFields['richText']) && isset($sourceFields['intro'])) {
                $resolved = $this->_handleNodes('richText', $sourceFields['intro'] ?? []);
                foreach ($resolved as $handle => $fieldValue) {
                    $additionalFields[$handle] = $fieldValue;
                    $reportedFields[] = $handle;
                }
            }

            $cards = $sourceFields['cards'] ?? [];

            // D6 CAVEAT: Single-page selections must NOT trigger auto-expansion.
            // Map single-page selections to manual mode with one card row.
            if (count($cards) === 1) {
                $card = $cards[0];
                $page = $card['page'] ?? [];
                $slug = $page['slug'] ?? null;
                $entryId = $page['id'] ?? null;

                if ($slug) {
                    // Manual mode with one card row using entry relation + useEntryCardDetails:true
                    $additionalFields['cardsInThisBlock'] = 'manual';
                    $reportedFields[] = 'cardsInThisBlock';
                    $additionalFields['useChildPages'] = false;
                    $reportedFields[] = 'useChildPages';

                    // Deferred ref: pass 2 will create the inner card row
                    $cardRefs['singlePageMode'] = true;
                    $cardRefs['refs'] = [['slug' => $slug, 'id' => $entryId]];
                    return [$additionalFields, $cardRefs];
                }
            }

            // Multiple pages: use automatic mode with deferred refs
            $additionalFields['cardsInThisBlock'] = 'automatic';
            $reportedFields[] = 'cardsInThisBlock';
            $additionalFields['useChildPages'] = false;
            $reportedFields[] = 'useChildPages';

            $pageRefs = [];
            foreach ($cards as $card) {
                if (isset($card['page']['slug'])) {
                    $pageRefs[] = [
                        'slug' => $card['page']['slug'],
                        'id' => $card['page']['id'] ?? null,
                    ];
                }
            }
            $cardRefs['refs'] = $pageRefs;

        } elseif ($cardMode === 'children') {
            // Resolve intro field
            if (!isset($outerFields['richText']) && isset($sourceFields['intro'])) {
                $resolved = $this->_handleNodes('richText', $sourceFields['intro'] ?? []);
                foreach ($resolved as $handle => $fieldValue) {
                    $additionalFields[$handle] = $fieldValue;
                    $reportedFields[] = $handle;
                }
            }

            $parent = $sourceFields['parent'] ?? null;
            $parentSlug = $parent['slug'] ?? null;
            $parentId = $parent['id'] ?? null;

            $additionalFields['cardsInThisBlock'] = 'automatic';
            $reportedFields[] = 'cardsInThisBlock';

            // Check if parent equals host page
            if ($parentSlug && $parentSlug === $hostPageSlug) {
                // Parent is the host page — use live children query
                $additionalFields['useChildPages'] = true;
                $reportedFields[] = 'useChildPages';
                // No deferred ref needed
            } else {
                // Arbitrary parent — defer resolution to pass 2
                $additionalFields['useChildPages'] = false;
                $reportedFields[] = 'useChildPages';
                $cardRefs['parent'] = [
                    'slug' => $parentSlug ?? '',
                    'id' => $parentId,
                ];
            }
        }

        return [$additionalFields, $cardRefs];
    }

    /**
     * Builds a single Verbb Hyper Url link array for an action button.
     *
     * Inert URLs (empty, null, '#', or a bare scheme) fall back to the
     * 'https://' placeholder so editors can set the destination in the CMS
     * after import — see {@see LinkHelper::hyperInertUrl()}.
     *
     * @param string $label Button text.
     * @param string $url   Destination URL (may be empty).
     * @return array<string, string>
     */
    private function _buildActionButtonLink(string $label, string $url): array
    {
        return [
            'type'      => 'verbb\\hyper\\links\\Url',
            'handle'    => 'default-verbb-hyper-links-url',
            'linkValue' => LinkHelper::hyperInertUrl($url),
            'linkText'  => $label,
            'linkClass' => 'btn btn-primary',
        ];
    }
}

<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\models\FieldLayout;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\LinkHelper;
use Throwable;
use yii\base\Component;

/**
 * Orchestrates the full import pipeline for a single ContentIQ page.
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

    /**
     * Resolved content_types routing map (defaults.php merged with project override).
     *
     * @var array<string, array>|null
     */
    private ?array $_contentTypesMap = null;

    // Public Methods
    // =========================================================================

    /**
     * Runs the full import pipeline for a single ContentIQ page export.
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
            $result['slug']  = $slug;
            $result['title'] = $title;

            $isHomepage = (bool)($data['document']['is_homepage'] ?? false);

            // -----------------------------------------------------------------------
            // 2a. Collection children carry a content_type and a raw `content` field
            //     instead of a blocks array. Route them to their configured section
            //     and import via a separate, simpler path. (Collection parents are
            //     excluded server-side and never reach the importer.)
            // -----------------------------------------------------------------------
            $contentType = $data['document']['content_type'] ?? null;
            if ($contentType !== null) {
                return $this->_importCollectionChild($data, $contentType, $config, $dryRun, $result);
            }

            // -----------------------------------------------------------------------
            // 3. Resolve section and entry type.
            // -----------------------------------------------------------------------
            if ($isHomepage) {
                $sectionHandle   = $config['homepageSection'] ?? 'homepage';
                $entryTypeHandle = $config['homepageEntryType'] ?? 'homepage';
            } else {
                $sectionHandle   = $config['section'] ?? 'pages';
                $entryTypeHandle = $config['entryType'] ?? 'pages';
            }

            $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
            if ($section === null) {
                return $this->_fatal($result, "Section '{$sectionHandle}' not found in Craft. Check config/contentiq.php.");
            }

            $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
            if ($entryType === null) {
                return $this->_fatal($result, "Entry type '{$entryTypeHandle}' not found in Craft. Check config/contentiq.php.");
            }

            // -----------------------------------------------------------------------
            // 4. Prepare ImageImportService (resolves volume + folder once).
            // -----------------------------------------------------------------------
            $volumeHandle = $config['assetVolume'] ?? 'images';
            $folderPath   = $config['assetFolder'] ?? 'contentiq';

            ContentIQImporter::$plugin->images->prepare($volumeHandle, $folderPath);

            // -----------------------------------------------------------------------
            // 5. Prepare MatrixBuilder (builds merged mapping once).
            // -----------------------------------------------------------------------
            ContentIQImporter::$plugin->matrixBuilder->prepare($config);

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

            // Collect block notes for the sidebar widget.
            $noteLines = [];
            foreach ($blocks as $block) {
                $note = $block['notes'] ?? '';
                if (is_string($note) && trim($note) !== '') {
                    $blockType = $block['type'] ?? 'unknown';
                    $acronyms = ['usp' => 'USP', 'faq' => 'FAQ', 'cta' => 'CTA'];
                    $blockLabel = ucwords(str_replace('_', ' ', $blockType));
                    $blockLabel = strtr($blockLabel, array_combine(
                        array_map('ucfirst', array_keys($acronyms)),
                        array_values($acronyms),
                    ));
                    $noteLines[] = $blockLabel . "\n" . trim($note);
                }
            }
            $result['blockNotes'] = implode("\n\n", $noteLines);

            $built = ContentIQImporter::$plugin->matrixBuilder->build($contentBlocks, $dryRun, $slug);

            $result['blocks']      = $built['blockReport'];
            $result['images']      = $built['imageReport'];
            $result['cardRefs']    = $built['cardRefs'] ?? [];  // Deferred card refs for SyncJob pass 2
            $result['warnings']    = array_merge($result['warnings'], $built['warnings'] ?? []);

            // -----------------------------------------------------------------------
            // 7. Resolve SEO field values, card fields, and hero field.
            // -----------------------------------------------------------------------
            $seoValues    = $this->_resolveSeoFields($data['seo'] ?? [], $config, $dryRun);
            $seoPopulated = array_filter($seoValues, fn($v) => $v !== '' && $v !== null && $v !== []);
            $result['seoFieldCount'] = count($seoPopulated);

            $cardValues = $this->_resolveCardFields($data['document']['card'] ?? null, $dryRun);

            // Both pages and homepage use the same hero ContentBlock field.
            $heroData = $heroBlock !== null
                ? $this->_buildHeroField($heroBlock, $dryRun)
                : null;

            // -----------------------------------------------------------------------
            // 8. Find existing entry (Singles always exist; Structures match by slug).
            // -----------------------------------------------------------------------
            if ($isHomepage) {
                $existing = Entry::find()
                    ->section($sectionHandle)
                    ->status(null)
                    ->one();
            } else {
                $existing = Entry::find()
                    ->section($sectionHandle)
                    ->slug($slug)
                    ->status(null)
                    ->one();
            }

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
                $cardValues,
                $seoValues,
            );

            // -----------------------------------------------------------------------
            // 11a. Existing entry → overwrite content directly.
            // -----------------------------------------------------------------------
            if ($existing !== null) {
                $filteredValues = $this->_filterToValidFields($fieldValues, $existing->getFieldLayout(), $result);
                $result['seoFieldCount'] = $this->_countSeoFields($filteredValues, $config);

                if (!$isHomepage) {
                    $existing->title = $title;
                }
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
            Craft::error("ContentIQImporter exception: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);

            return $this->_fatal($result, 'Exception: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Resolves the Craft routing config for a ContentIQ content_type slug.
     *
     * Returns ['section' => ..., 'entryType' => ..., 'contentField' => ...] when the
     * slug is mapped, or null when the slug is null or has no mapping. Used by both
     * importPage() (routing) and SyncJob (section-aware lock check / skipping
     * structure positioning for collection children).
     *
     * @param string|null $contentType
     * @return array{section: string, entryType: string, contentField: string}|null
     */
    public function getContentTypeRoute(?string $contentType): ?array
    {
        if ($contentType === null) {
            return null;
        }

        return $this->_getContentTypesMap()[$contentType] ?? null;
    }

    /**
     * Returns the full resolved content_types routing map (defaults.php merged
     * with the project config override).
     *
     * Used by the sync screen to enumerate every collection section so its
     * entries can be listed alongside Pages.
     *
     * @return array<string, array{section: string, entryType: string, contentField: string}>
     */
    public function getContentTypesMap(): array
    {
        return $this->_getContentTypesMap();
    }

    /**
     * PASS 2: Resolves deferred card references in entryCards blocks.
     *
     * After all pages in a run are imported (so the slug→entry ID map is
     * complete), each deferred ref set recorded by MatrixBuilder is applied to
     * its block:
     *   - pages (2+ refs): populate the block's `entries` relation in order.
     *   - pages (single ref, D6): write one manual `card` row with the `entry`
     *     relation + useEntryCardDetails, so Craft's single-entry auto-expansion
     *     never fires (pages mode is literal).
     *   - children (arbitrary parent): `entries` = [parent] — the template's
     *     single-entry expansion renders the parent's children. If the parent
     *     cannot be resolved (not in the batch, not in Craft), fall back to
     *     manual cards parsed from the block's own intro prose.
     *   - children (parent == host page): nothing deferred (useChildPages set
     *     in pass 1).
     *
     * Blocks are located by blockIndex — the 0-based position among the matrix
     * blocks MatrixBuilder emitted, which matches the saved contentBlocks order.
     * Nested matrix blocks are elements, so each modified block is saved
     * directly; the owner entry is never re-saved.
     *
     * Shared by every import entry point (SyncJob, CLI import, CP upload).
     *
     * @param array $allCardRefs   Deferred ref sets keyed by entryId; each value is a LIST
     *                             of {blockIndex, mode, refs?|parent?, intro?, singlePageMode?}.
     * @param array $slugToEntryId In-run slug→entry ID map from pass 1.
     * @param bool  $dryRun        If true, resolve and warn but write nothing.
     * @return array<int, string[]> Warnings keyed by owner entry ID.
     */
    public function resolveCardReferences(array $allCardRefs, array $slugToEntryId, bool $dryRun = false): array
    {
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';

        // Resolve a page slug to an entry ID: in-run map first, then DB fallback.
        $resolve = function (string $slug) use ($slugToEntryId, $sectionHandle): ?int {
            if (isset($slugToEntryId[$slug])) {
                return $slugToEntryId[$slug];
            }

            return Entry::find()
                ->section($sectionHandle)
                ->slug($slug)
                ->status(null)
                ->one()?->id;
        };

        $warningsByEntry = [];

        foreach ($allCardRefs as $entryId => $refSets) {
            $warn = function (string $message) use (&$warningsByEntry, $entryId): void {
                $warningsByEntry[$entryId][] = $message;
            };

            $entry = Entry::find()->id($entryId)->status(null)->one();
            if (!$entry) {
                continue;
            }

            // Ordered nested blocks — positions match MatrixBuilder's emitted order.
            $blocks = $entry->getFieldValue('contentBlocks')->status(null)->all();

            foreach ($refSets as $refSet) {
                $blockIndex = $refSet['blockIndex'] ?? null;
                $block      = $blockIndex !== null ? ($blocks[$blockIndex] ?? null) : null;

                if (!$block || $block->getType()->handle !== 'entryCards') {
                    $warn('Cards block at position '
                        . var_export($blockIndex, true) . ' not found — card references skipped.');
                    continue;
                }

                $mode  = $refSet['mode'] ?? '';
                $dirty = false;

                if ($mode === 'pages' && !empty($refSet['singlePageMode'])) {
                    // D6: one literal page — a single manual card row rendering
                    // from the referenced entry's own card fields.
                    $slug       = $refSet['refs'][0]['slug'] ?? '';
                    $resolvedId = $slug !== '' ? $resolve($slug) : null;

                    if ($resolvedId !== null) {
                        $block->setFieldValue('entryCards', [
                            'new1' => [
                                'type'   => 'card',
                                'fields' => [
                                    'entry'               => [$resolvedId],
                                    'useEntryCardDetails' => true,
                                ],
                            ],
                        ]);
                        $dirty = true;
                    } else {
                        $warn("Card reference slug '{$slug}' not found — card left empty.");
                    }
                } elseif ($mode === 'pages') {
                    $entryIds = [];

                    foreach ($refSet['refs'] ?? [] as $ref) {
                        $slug = $ref['slug'] ?? '';
                        if ($slug === '') {
                            continue;
                        }

                        $resolvedId = $resolve($slug);
                        if ($resolvedId !== null) {
                            $entryIds[] = $resolvedId;
                        } else {
                            $warn("Card reference slug '{$slug}' not found — card skipped.");
                        }
                    }

                    $block->setFieldValue('entries', $entryIds);
                    $dirty = true;
                } elseif ($mode === 'children') {
                    $slug = $refSet['parent']['slug'] ?? '';
                    if ($slug === '') {
                        // Host-page children — useChildPages was set in pass 1.
                        continue;
                    }

                    $parentEntryId = $resolve($slug);
                    if ($parentEntryId !== null) {
                        $block->setFieldValue('entries', [$parentEntryId]);
                        $dirty = true;
                    } else {
                        // Parent page isn't in this run or in Craft (not exported
                        // yet). Fall back to manual cards parsed from the block's
                        // own prose so it renders real cards instead of nothing.
                        // The next sync rebuilds the block in automatic mode, so
                        // the fallback self-heals once the parent is exported.
                        $fallback = ContentIQImporter::$plugin->matrixBuilder
                            ->buildChildrenFallbackCards($refSet['intro'] ?? []);

                        if ($fallback['cardCount'] > 0) {
                            $block->setFieldValue('cardsInThisBlock', 'manual');
                            $block->setFieldValue('entryCards', $fallback['cardRows']);
                            $block->setFieldValue('richText', $fallback['introHtml']);
                            $dirty = true;
                            $warn("Children parent slug '{$slug}' not found — created {$fallback['cardCount']} manual cards "
                                . "from the block content instead. Unlock and re-sync after '{$slug}' is exported "
                                . 'to switch to automatic child cards.');
                        } else {
                            $warn("Children parent slug '{$slug}' not found — children cards not populated.");
                        }
                    }
                }

                if ($dirty && !$dryRun) {
                    try {
                        // Nested matrix blocks are elements — saving the block
                        // persists its field changes; the owner needs no re-save.
                        if (!Craft::$app->elements->saveElement($block)) {
                            $warn('Could not save card references: '
                                . implode('; ', $block->getFirstErrors()));
                        }
                    } catch (Throwable $e) {
                        $warn('Could not save card references: ' . $e->getMessage());
                    }
                }
            }
        }

        return $warningsByEntry;
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads the content_types routing map, per-slug replace at each layer:
     *
     *   defaults.php  ←  settings.collectionMappings (CP Mappings screen)  ←  config/contentiq.php 'content_types'
     *
     * The config file stays the dev escape hatch and wins over the UI. Settings
     * rows with an empty/missing `section` are filtered out before merging, so an
     * empty collectionMappings leaves the result byte-identical to the old
     * defaults ← file merge.
     *
     * Cached after the first call.
     *
     * @return array<string, array>
     */
    private function _getContentTypesMap(): array
    {
        if ($this->_contentTypesMap !== null) {
            return $this->_contentTypesMap;
        }

        $defaults    = require dirname(__DIR__) . '/config/defaults.php';
        $defaultMap  = is_array($defaults['content_types'] ?? null) ? $defaults['content_types'] : [];

        // CP-managed mappings (project config) — drop rows with no section.
        $settings    = ContentIQImporter::$plugin->getSettings();
        $settingsMap = [];

        foreach ($settings->collectionMappings as $slug => $row) {
            if (!is_array($row) || empty($row['section'])) {
                continue;
            }

            $settingsMap[$slug] = $row;
        }

        // config/contentiq.php 'content_types' — the dev escape hatch, wins over the UI.
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileOverride  = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        // Per-slug replace — a later layer replaces a slug's whole definition.
        $this->_contentTypesMap = array_replace($defaultMap, $settingsMap, $fileOverride);

        return $this->_contentTypesMap;
    }

    /**
     * Imports a collection child (a page with document.content_type set).
     *
     * Collection children differ from standard pages: they carry a raw `content`
     * ProseMirror field (not a blocks array), route to their configured section,
     * never have a Craft parent (their collection parent is excluded from export),
     * and only carry SEO for portal-on collections. Content is serialised to HTML
     * and written to the configured contentField.
     *
     * Returns the standard result shape with `contentType`/`sectionLabel` set, or a
     * non-fatal skip (success, skipped=true, warning) when the content_type is unmapped.
     *
     * @param array  $data
     * @param string $contentType
     * @param array  $config
     * @param bool   $dryRun
     * @param array  $result Pre-populated result skeleton (slug/title already set).
     * @return array
     */
    private function _importCollectionChild(array $data, string $contentType, array $config, bool $dryRun, array $result): array
    {
        $result['contentType'] = $contentType;

        $slug  = $result['slug'];
        $title = $result['title'];

        // Resolve routing — unmapped content_type is skipped (non-fatal).
        $route = $this->getContentTypeRoute($contentType);
        if ($route === null) {
            $result['skipped']      = true;
            $result['success']      = true;
            $result['sectionLabel'] = $contentType;
            $result['warnings'][]   = "Content type '{$contentType}' has no mapping — page skipped. Map it under ContentiQ → Mappings (or add a content_types override in config/contentiq.php).";
            Craft::warning("ContentIQImporter: unmapped content_type '{$contentType}' for page '{$slug}' — skipped.", __METHOD__);

            return $result;
        }

        $sectionHandle      = $route['section'];
        $entryTypeHandle    = $route['entryType'];
        $contentFieldHandle = $route['contentField'];
        $headingFieldHandle = $route['headingField'] ?? null;

        $section = Craft::$app->entries->getSectionByHandle($sectionHandle);
        if ($section === null) {
            return $this->_fatal($result, "Section '{$sectionHandle}' (content type '{$contentType}') not found in Craft. Check config/contentiq.php.");
        }

        $entryType = Craft::$app->entries->getEntryTypeByHandle($entryTypeHandle);
        if ($entryType === null) {
            return $this->_fatal($result, "Entry type '{$entryTypeHandle}' (content type '{$contentType}') not found in Craft. Check config/contentiq.php.");
        }

        $result['sectionLabel'] = $section->name ?: $contentType;

        // Prepare image service (used by SEO og_image import).
        $volumeHandle = $config['assetVolume'] ?? 'images';
        $folderPath   = $config['assetFolder'] ?? 'contentiq';
        ContentIQImporter::$plugin->images->prepare($volumeHandle, $folderPath);

        // Serialise the raw ProseMirror content to HTML for the content field.
        $content     = $data['content'] ?? [];
        $fieldValues = [];

        // Optionally lift the H1 (level-1 heading) into a dedicated heading field,
        // stripping it from the body so it isn't rendered twice. Only when the
        // route configures a 'headingField' and the content actually has an H1.
        if ($headingFieldHandle !== null) {
            $extracted = ContentIQImporter::$plugin->nodes->extractHeading($content, 1);

            if (($extracted['text'] ?? '') !== '') {
                $fieldValues[$headingFieldHandle] = $extracted['text'];
                $content = $extracted['doc'];
            }
        }

        $fieldValues[$contentFieldHandle] = ContentIQImporter::$plugin->nodes->renderDocument($content);

        // SEO is only present for portal-on collections — set it only when supplied.
        if (!empty($data['seo'])) {
            $fieldValues = array_merge($fieldValues, $this->_resolveSeoFields($data['seo'], $config, $dryRun));
        }

        // Find existing entry by slug within the routed section.
        $existing = Entry::find()
            ->section($sectionHandle)
            ->slug($slug)
            ->status(null)
            ->one();

        if ($existing !== null) {
            $result['entryFound'] = true;
            $result['entryId']    = $existing->id;
        }

        if ($dryRun) {
            $result['success'] = true;

            return $result;
        }

        if ($existing !== null) {
            $filtered = $this->_filterToValidFields($fieldValues, $existing->getFieldLayout(), $result);
            $result['seoFieldCount'] = $this->_countSeoFields($filtered, $config);
            $existing->title = $title;
            $existing->setFieldValues($filtered);

            if (!Craft::$app->getElements()->saveElement($existing, false)) {
                $errors = implode(', ', $existing->getFirstErrors());

                return $this->_fatal($result, "Failed to save entry: {$errors}");
            }

            $this->_refreshUri($existing, $result);

            $result['entryId'] = $existing->id;
            $result['success'] = true;

            return $result;
        }

        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->typeId    = $entryType->id;
        $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
        $entry->title     = $title;
        $entry->slug      = $slug;

        $filtered = $this->_filterToValidFields($fieldValues, $entryType->getFieldLayout(), $result);
        $result['seoFieldCount'] = $this->_countSeoFields($filtered, $config);
        $entry->setFieldValues($filtered);

        if (!Craft::$app->getElements()->saveElement($entry, false)) {
            $errors = implode(', ', $entry->getFirstErrors());
            $this->_logNestedErrors($entry);

            return $this->_fatal($result, "Failed to save entry: {$errors}");
        }

        $this->_refreshUri($entry, $result);

        $result['entryId'] = $entry->id;
        $result['success'] = true;

        return $result;
    }

    /**
     * Generates and persists the entry's URI after a validation-skipped save.
     *
     * The importer saves collection children with saveElement($entry, false) to
     * bypass nested-Matrix validation, but URI generation in Craft is a
     * validation-time concern (ElementUriValidator), so a skipped save leaves
     * uri = null — the entry has no front-end URL (no globe link in the CP).
     * updateElementSlugAndUri() regenerates and writes slug + URI directly
     * without re-running element validation.
     *
     * Pages get this for free via Structures::append() → afterMoveInStructure();
     * collection children are channel entries with no structure step, so the
     * importer must trigger it explicitly. No-op when the section has no URLs
     * (setElementUri just leaves uri = null).
     *
     * @param Entry $entry
     * @param array $result
     */
    private function _refreshUri(Entry $entry, array &$result): void
    {
        try {
            Craft::$app->getElements()->updateElementSlugAndUri($entry, true, false);
        } catch (\Throwable $e) {
            $result['warnings'][] = 'Could not generate a front-end URL: ' . $e->getMessage();
        }
    }

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
            'blockNotes'    => '',
            'warnings'      => [],
            'error'         => null,
            'skipped'       => false,
            'contentType'   => null,
            'sectionLabel'  => null,
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
        Craft::error("ContentIQImporter: $message", __METHOD__);
        $result['success'] = false;
        $result['error']   = $message;

        return $result;
    }

    /**
     * Loads and merges the contentiq config (project config + defaults).
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
            'assetFolder'    => 'contentiq',
            'matrixField'    => 'contentBlocks',
            'seoField'       => 'seo',
            'blockOverrides' => [],
        ];

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $merged        = array_replace_recursive($defaults, is_array($projectConfig) ? $projectConfig : []);

        $this->_config = $merged;

        return $merged;
    }

    /**
     * Resolves ContentIQ SEO data into a SEOmatic SeoSettings field value array.
     *
     * SEOmatic stores all SEO data in a single field (handle: 'seo') as a
     * structured array. String values go in metaGlobalVars; source flags and
     * asset IDs go in metaBundleSettings.
     *
     * @param array $seo    The seo object from the ContentIQ JSON.
     * @param array $config Merged contentiq config.
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
            $imageResult = ContentIQImporter::$plugin->images->importFromField($ogImageData, $dryRun);
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
     * Resolves ContentIQ card data into Craft card field values.
     *
     * Card data is optional on the page; when absent or null, returns empty array
     * (skip writing). When present, writes all three fields: cardTitle (from title),
     * cardText (from summary), and cardImage (from image via ImageImportService).
     *
     * All three fields are always written when card data is present — null/empty
     * values clear the field — so re-imports propagate removals made in ContentIQ.
     *
     * @param array|null $card    The card object from document.card, or null if absent.
     * @param bool       $dryRun
     * @return array<string, mixed>
     */
    private function _resolveCardFields(?array $card, bool $dryRun): array
    {
        // Card is absent or null — skip writing any card fields.
        if ($card === null || !is_array($card)) {
            return [];
        }

        $cardValues = [
            'cardTitle' => (string)($card['title'] ?? ''),
            'cardText'  => (string)($card['summary'] ?? ''), // ContentIQ calls this field "summary"
        ];

        // cardImage — from card.image via ImageImportService.importFromField().
        // Returns an array of asset IDs (or empty array if null/missing).
        $imageData = $card['image'] ?? null;
        if (is_array($imageData) && !empty($imageData['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($imageData, $dryRun);
            $cardValues['cardImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        } else {
            // No image, or image is null/empty — set empty array.
            $cardValues['cardImage'] = [];
        }

        return $cardValues;
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
                Craft::warning("ContentIQImporter: field '{$handle}' skipped (not in field layout).", __METHOD__);
            }
        }

        return $filtered;
    }

    /**
     * Builds the `hero` ContentBlock field value from a ContentIQ hero block.
     *
     * Both pages and homepage use the same craft\fields\ContentBlock field.
     * Returns the ContentBlock shape wrapping the inner hero fields:
     *   [
     *     'enableHero' => true,             // sibling lightswitch on the entry type
     *     'hero' => ['fields' => [
     *       'heading'       => '<h1>…</h1>',  // CKEditor
     *       'richText'      => '<h2>…</h2><p>…</p>',  // subheading + body
     *       'desktopImage'  => [$assetId],   // Assets
     *       'mobileImage'   => [$assetId],   // Assets (optional)
     *       'actionButtons' => [...],        // Matrix of actionButton entries
     *     ]],
     *   ]
     *
     * The inner fields are built by _buildHeroInnerFields(). Returns null when
     * no mappable inner fields are found.
     *
     * @param array $heroBlock ContentIQ hero block (the full block object).
     * @param bool  $dryRun
     * @return array<string, mixed>|null
     */
    private function _buildHeroField(array $heroBlock, bool $dryRun): ?array
    {
        $innerFields = $this->_buildHeroInnerFields($heroBlock, $dryRun);

        if (empty($innerFields)) {
            return null;
        }

        // hero is a ContentBlock field (handle override on heroContent).
        // enableHero is a separate lightswitch on the pages entry type.
        return [
            'enableHero' => true,
            'hero' => [
                'fields' => $innerFields,
            ],
        ];
    }

    /**
     * Builds the inner field values for a hero ContentBlock.
     *
     * Used by _buildHeroField for both pages and homepage (same ContentBlock).
     * Inner field handles:
     *   heading      → CKEditor (Page Heading H1, handle override)
     *   richText     → CKEditor (subheading + body)
     *   desktopImage → Assets
     *   actionButtons → Matrix of actionButton entries
     *
     * @param array $heroBlock Raw hero block from the JSON.
     * @param bool  $dryRun
     * @return array Inner fields array (empty if nothing to set).
     */
    private function _buildHeroInnerFields(array $heroBlock, bool $dryRun): array
    {
        $fields      = $heroBlock['fields'] ?? [];
        $innerFields = [];

        // heading → <h1> CKEditor HTML
        if (isset($fields['heading'])) {
            $heading = $fields['heading'];

            if (is_array($heading) && isset($heading['text'])) {
                $level = max(1, min(6, (int)($heading['level'] ?? 1)));
                $inner = isset($heading['content']) && is_array($heading['content'])
                    ? ContentIQImporter::$plugin->nodes->renderInlineContent($heading['content'])
                    : htmlspecialchars((string)$heading['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $innerFields['heading'] = "<h{$level}>{$inner}</h{$level}>";
            } elseif (is_string($heading) && $heading !== '') {
                $text = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $innerFields['heading'] = "<h1>{$text}</h1>";
            }
        }

        // richText → optional subheading + body text.
        $richTextParts = [];

        if (isset($fields['subheading'])) {
            $sub = $fields['subheading'];
            if (is_array($sub) && isset($sub['text']) && $sub['text'] !== '') {
                $subLevel = max(2, min(6, (int)($sub['level'] ?? 2)));
                $inner    = isset($sub['content']) && is_array($sub['content'])
                    ? ContentIQImporter::$plugin->nodes->renderInlineContent($sub['content'])
                    : htmlspecialchars((string)$sub['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $richTextParts[] = "<h{$subLevel}>{$inner}</h{$subLevel}>";
            }
        }

        if (isset($fields['body']) && is_string($fields['body']) && $fields['body'] !== '') {
            $escaped = htmlspecialchars($fields['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $richTextParts[] = "<p>{$escaped}</p>";
        }

        if (!empty($richTextParts)) {
            $innerFields['richText'] = implode('', $richTextParts);
        }

        // desktopImage — primary image.
        if (isset($fields['image']) && is_array($fields['image']) && !empty($fields['image']['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($fields['image'], $dryRun);
            $innerFields['desktopImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        }

        // mobileImage — optional mobile-specific hero image.
        if (isset($fields['mobile_image']) && is_array($fields['mobile_image']) && !empty($fields['mobile_image']['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($fields['mobile_image'], $dryRun);
            $innerFields['mobileImage'] = ($imageResult !== null && $imageResult['id'] !== null)
                ? [$imageResult['id']]
                : [];
        }

        // actionButtons — Matrix of actionButton entries with Hyper fields.
        $buttons = $fields['buttons'] ?? [];
        if (!empty($buttons) && is_array($buttons)) {
            $actionButtonsData = [];
            $btnCounter = 0;

            foreach ($buttons as $button) {
                // ContentIQ hero buttons use 'text' for the display label.
                $label = (string)($button['text'] ?? $button['label'] ?? '');
                $url   = (string)($button['url'] ?? '');

                if ($label === '' && $url === '') {
                    continue;
                }

                $actionButtonsData['new' . (++$btnCounter)] = [
                    'type'   => 'actionButton',
                    'fields' => [
                        'actionButton' => [
                            [
                                'type'      => 'verbb\\hyper\\links\\Url',
                                'handle'    => 'default-verbb-hyper-links-url',
                                'linkValue' => LinkHelper::hyperInertUrl($url),
                                'linkText'  => $label,
                                'linkClass' => 'btn btn-primary',
                            ],
                        ],
                    ],
                ];
            }

            if (!empty($actionButtonsData)) {
                $innerFields['actionButtons'] = $actionButtonsData;
            }
        }

        return $innerFields;
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
     * @param array $config      Merged contentiq config.
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
     * Creates (or updates) a callToActionEntry from a ContentIQ CTA block.
     *
     * Extracts title from the first heading node, renders richText from non-button
     * nodes, imports image if present, and builds actionButtons Matrix entries from
     * ctaButton nodes in the nodes array (or falls back to a flat fields.buttons array).
     *
     * Idempotent by title: existing entries are updated so re-imports fix stale data.
     *
     * @param array  $ctaBlock  The raw CTA block from the JSON.
     * @param array  &$result   Result array, mutated to add warnings/images.
     * @param bool   $dryRun
     * @return int|null The CTA entry ID, or null on failure.
     */
    private function _resolveCtaEntry(array $ctaBlock, array &$result, bool $dryRun): ?int
    {
        $fields = $ctaBlock['fields'] ?? [];
        $nodes  = is_array($fields['nodes'] ?? null) ? $fields['nodes'] : [];

        // Extract title from first heading node.
        $title = 'Call to Action';
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'heading' && !empty($node['text'])) {
                $title = (string)$node['text'];
                break;
            }
        }

        if ($dryRun) {
            // Idempotency check for dry run — return existing ID if found.
            $existing = Entry::find()->section('callsToAction')->title($title)->status(null)->one();

            return $existing?->id;
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

        // Separate ctaButton nodes (→ actionButtons Matrix) from content nodes (→ richText).
        // ContentIQ sends buttons as ctaButton nodes in fields.nodes (same pattern as faq/price_list).
        $contentNodes      = [];
        $actionButtonsData = [];
        $btnCounter        = 0;

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'ctaButton') {
                $label = (string)($node['label'] ?? '');
                $url   = (string)($node['url'] ?? '');

                if ($label !== '' || $url !== '') {
                    $actionButtonsData['new' . (++$btnCounter)] = [
                        'type'   => 'actionButton',
                        'fields' => [
                            'actionButton' => [[
                                'type'      => 'verbb\\hyper\\links\\Url',
                                'handle'    => 'default-verbb-hyper-links-url',
                                'linkValue' => LinkHelper::hyperInertUrl($url),
                                'linkText'  => $label,
                                'linkClass' => 'btn btn-primary',
                            ]],
                        ],
                    ];
                }
            } else {
                $contentNodes[] = $node;
            }
        }

        // Build field values.
        $ctaFieldValues = [];

        // richText — render content nodes only (ctaButton nodes excluded above).
        $ctaFieldValues['richText'] = ContentIQImporter::$plugin->nodes->render($contentNodes);

        // image — import if present.
        $imageData = $fields['image'] ?? null;
        if (is_array($imageData) && !empty($imageData['url'])) {
            $imageResult = ContentIQImporter::$plugin->images->importFromField($imageData, $dryRun);
            if ($imageResult !== null && $imageResult['id'] !== null) {
                $ctaFieldValues['image'] = [$imageResult['id']];
                $result['images'][] = ['filename' => $imageResult['filename'], 'reused' => $imageResult['reused']];
            }
        }

        // desktopBackgroundImage — import background_image if present.
        $bgImageData = $fields['background_image'] ?? null;
        if (is_array($bgImageData) && !empty($bgImageData['url'])) {
            $bgResult = ContentIQImporter::$plugin->images->importFromField($bgImageData, $dryRun);
            if ($bgResult !== null && $bgResult['id'] !== null) {
                $ctaFieldValues['desktopBackgroundImage'] = [$bgResult['id']];
                $result['images'][] = ['filename' => $bgResult['filename'], 'reused' => $bgResult['reused']];
            }
        }

        // actionButtons — prefer ctaButton nodes; fall back to legacy flat fields.buttons array.
        if (empty($actionButtonsData)) {
            $actionButtonsData = $this->_buildActionButtons($fields['buttons'] ?? []);
        }

        if (!empty($actionButtonsData)) {
            $ctaFieldValues['actionButtons'] = $actionButtonsData;
        }

        // Idempotency — update existing entry rather than creating a duplicate.
        $existing = Entry::find()->section('callsToAction')->title($title)->status(null)->one();

        if ($existing !== null) {
            $existing->setFieldValues($ctaFieldValues);

            if (!Craft::$app->getElements()->saveElement($existing, false)) {
                $errors = implode(', ', $existing->getFirstErrors());
                Craft::warning("ContentIQImporter: CTA entry update failed for '{$title}': {$errors}", __METHOD__);
            }

            return $existing->id;
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
            Craft::warning("ContentIQImporter: CTA entry save failed: {$errors}", __METHOD__);

            return null;
        }

        return $entry->id;
    }

    /**
     * Builds the actionButtons Matrix field data from a ContentIQ flat buttons array.
     *
     * Each button becomes an actionButton entry with a Hyper actionButton field.
     * Buttons where both label and URL are empty are skipped.
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
            $url   = (string)($button['url'] ?? '');

            if ($label === '' && $url === '') {
                continue;
            }

            $matrixData['new' . (++$counter)] = [
                'type'   => 'actionButton',
                'fields' => [
                    'actionButton' => [[
                        'type'      => 'verbb\\hyper\\links\\Url',
                        'handle'    => 'default-verbb-hyper-links-url',
                        'linkValue' => LinkHelper::hyperInertUrl($url),
                        'linkText'  => $label,
                        'linkClass' => 'btn btn-primary',
                    ]],
                ],
            ];
        }

        return $matrixData;
    }
}

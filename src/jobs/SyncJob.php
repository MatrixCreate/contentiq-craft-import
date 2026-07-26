<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use matrixcreate\contentiqimporter\ContentIQImporter;
use Throwable;

/**
 * Queue job that runs a full ContentIQ API sync.
 *
 * Fetches the export from the ContentIQ API, then imports each page
 * through the existing ImportService pipeline. Saves the result to
 * the import history table for the sync report screen.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class SyncJob extends BaseJob
{
    /**
     * The import run ID (pre-created with status 'pending' by the controller).
     *
     * @var int
     */
    public int $runId;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Web requests already run from webroot, but queue workers run from
        // the project root — match the CLI behaviour.
        chdir(Craft::getAlias('@webroot'));

        $plugin = ContentIQImporter::$plugin;

        // 1. Fetch export from API.
        $apiResult = $plugin->api->fetchExport();

        if (!$apiResult['success']) {
            $this->_failRun($apiResult['error'] ?? 'API request failed.');
            return;
        }

        $data = $apiResult['data'];

        // 2. Determine pages array.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];
        $total   = count($pages);

        // 3. PASS 1: Import all pages and build slug → entry ID map.
        $importService = $plugin->imports;
        $pageResults   = [];
        $totalImages   = 0;
        $hasErrors     = false;
        $hasWarnings   = false;

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        // Build slug → entry ID map for hierarchy and card ref resolution.
        $slugToEntryId = [];
        $allCardRefs   = [];  // Collected from all pages for pass 2

        foreach ($pages as $i => $pageData) {
            $this->setProgress($queue, $i / $total, "Importing page " . ($i + 1) . " of {$total}");

            // Collection children route to their configured section, not Pages.
            $contentType   = $pageData['document']['content_type'] ?? null;
            $route         = $importService->getContentTypeRoute($contentType);
            $lookupSection = $route['section'] ?? $sectionHandle;

            // Skip locked entries — look them up in their actual (routed) section.
            $pageSlug = $pageData['document']['slug'] ?? '';
            $existingEntry = \craft\elements\Entry::find()
                ->section($lookupSection)
                ->slug($pageSlug)
                ->status(null)
                ->one();

            if ($existingEntry !== null) {
                $isLocked = (new Query())
                    ->select(['locked'])
                    ->from('{{%contentiq_entry_syncs}}')
                    ->where(['element_id' => $existingEntry->id])
                    ->scalar();

                if ($isLocked) {
                    $pageResults[] = [
                        'success' => true,
                        'slug' => $pageSlug,
                        'entryId' => $existingEntry->id,
                        'entryFound' => true,
                        'title' => $pageData['document']['title'] ?? $pageSlug,
                        'depth' => $pageData['document']['depth'] ?? 0,
                        'parentSlug' => $pageData['document']['parent_slug'] ?? null,
                        'blocks' => [],
                        'images' => [],
                        'blockNotes' => '',
                        'seoFieldCount' => 0,
                        'warnings' => ['Skipped — entry is locked.'],
                        'error' => null,
                        'contentType' => $contentType,
                        'sectionLabel' => $contentType !== null
                            ? (Craft::$app->entries->getSectionByHandle($lookupSection)?->name ?? $contentType)
                            : null,
                    ];

                    if ($pageSlug !== '') {
                        $slugToEntryId[$pageSlug] = $existingEntry->id;
                    }
                    continue;
                }
            }

            $result = $importService->importPage($pageData, dryRun: false);

            // Handle hierarchy: always re-apply parent and position on every run.
            $parentSlug = $pageData['document']['parent_slug'] ?? null;
            $entryId    = $result['entryId'] ?? null;
            $slug       = $result['slug'] ?? '';
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            // Collection children are routed to their own section and have no Craft
            // parent (their collection parent is excluded from export) — skip the
            // Pages structure positioning / parent resolution for them entirely.
            if ($contentType === null && $entryId !== null && $structureId !== null && !$isHomepage) {
                $entry = \craft\elements\Entry::find()->id($entryId)->status(null)->one();

                if ($entry !== null) {
                    try {
                        if ($parentSlug !== null && $parentSlug !== '') {
                            // Try current-batch map first, then fall back to a Craft query
                            // so re-imports correctly place children under existing parents.
                            $parentId = $slugToEntryId[$parentSlug] ?? null;

                            if ($parentId === null) {
                                $parentEntry = \craft\elements\Entry::find()
                                    ->section($sectionHandle)
                                    ->slug($parentSlug)
                                    ->status(null)
                                    ->one();
                                $parentId = $parentEntry?->id;
                            }

                            if ($parentId !== null) {
                                $structures->append($structureId, $entry, $parentId);
                            } else {
                                $structures->appendToRoot($structureId, $entry);
                                $result['warnings'][] = "Parent slug '{$parentSlug}' not found — entry saved at root level.";
                            }
                        } else {
                            $structures->appendToRoot($structureId, $entry);
                        }
                    } catch (\Throwable $e) {
                        $result['warnings'][] = 'Could not update structure position: ' . $e->getMessage();
                    }
                }
            }

            // Track slug → entry ID for card ref lookups in pass 2.
            if ($entryId !== null && $slug !== '') {
                $slugToEntryId[$slug] = $entryId;
            }

            // Collect deferred card ref sets from this page for pass 2 resolution.
            // Kept as a plain LIST of ref sets — each already carries its blockIndex,
            // so a page with several Cards blocks resolves each independently.
            if ($entryId !== null && !empty($result['cardRefs'])) {
                $allCardRefs[$entryId] = array_values($result['cardRefs']);
            }

            // Attach hierarchy metadata for the report template.
            $result['title']      = $pageData['document']['title'] ?? $slug;
            $result['depth']      = $pageData['document']['depth'] ?? 0;
            $result['parentSlug'] = $pageData['document']['parent_slug'] ?? null;

            $pageResults[] = $result;
            $totalImages  += count($result['images'] ?? []);

            if (!$result['success']) {
                $hasErrors = true;
            }
            if (!empty($result['warnings'])) {
                $hasWarnings = true;
            }
        }

        $this->setProgress($queue, 1);

        // 4. PASS 2: Resolve deferred card references.
        // Note: dryRun applies to pass 1 only (skips image downloads). Pass 2 always
        // resolves and reports, but only writes if not dry-run.
        $this->_resolveCardReferencesPass2($pageResults, $allCardRefs, $slugToEntryId, false);

        // 5. Import globals (envelope may carry a `globals` key). URL-prefix drift
        //    is advisory and runs read-only regardless of the lock; the write
        //    path only runs when the user has consented (row unlocked this run).
        //    The pages path above is completely untouched by any of this.
        $globalsReport = null;

        if (isset($data['globals']) && is_array($data['globals'])) {
            $globals = $data['globals'];
            $drift   = $plugin->globals->checkUrlPrefixDrift($globals['collections'] ?? []);

            if ($this->_globalsLocked()) {
                $globalsReport = [
                    'locked'  => true,
                    'skipped' => 'Skipped — globals are locked.',
                    'drift'   => $drift,
                ];
            } else {
                $globalsReport          = $plugin->globals->import($globals, dryRun: false);
                $globalsReport['drift'] = $drift;
                $totalImages           += $globalsReport['imageCount'] ?? 0;

                // Relock and stamp on success.
                $this->_relockGlobals();
            }

            if (!empty($drift)
                || !empty($globalsReport['warnings'] ?? [])
                || !empty($globalsReport['fieldNotes'] ?? [])) {
                $hasWarnings = true;
            }
        }

        // 6. Determine overall status.
        if ($hasErrors) {
            $status = 'errors';
        } elseif ($hasWarnings) {
            $status = 'warnings';
        } else {
            $status = 'success';
        }

        // 7. Update the pre-created run record. The result is a shape-tagged
        //    object ({pages, globals}); old runs remain flat arrays.
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'pageCount'   => count($pageResults),
                'imageCount'  => $totalImages,
                'status'      => $status,
                'result'      => Json::encode(['pages' => $pageResults, 'globals' => $globalsReport]),
                'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->runId],
        )->execute();

        // 8. Auto-lock all successfully synced entries.
        // After every sync, imported entries are locked so subsequent syncs
        // won't overwrite them unless the user explicitly unlocks them.
        $db  = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($pageResults as $result) {
            if (!($result['success'] ?? false) || !($result['entryId'] ?? null)) {
                continue;
            }

            $entryId = $result['entryId'];

            $exists = (new Query())
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $entryId])
                ->exists();

            if ($exists) {
                $db->createCommand()->update('{{%contentiq_entry_syncs}}', [
                    'locked'    => true,
                    'synced_at' => $now,
                    'notes'     => $result['blockNotes'] ?? '',
                ], ['element_id' => $entryId])->execute();
            } else {
                $db->createCommand()->insert('{{%contentiq_entry_syncs}}', [
                    'element_id' => $entryId,
                    'locked'     => true,
                    'synced_at'  => $now,
                    'notes'      => $result['blockNotes'] ?? '',
                ])->execute();
            }
        }
    }

    /**
     * PASS 2: Resolve deferred card references in entryCards blocks.
     *
     * After all pages are imported (so the slug→entry ID map is complete), each
     * deferred ref set recorded by MatrixBuilder is applied to its block:
     *   - pages (2+ refs): populate the block's `entries` relation in order.
     *   - pages (single ref, D6): write one manual `card` row with the `entry`
     *     relation + useEntryCardDetails, so Craft's single-entry auto-expansion
     *     never fires (pages mode is literal).
     *   - children (arbitrary parent): `entries` = [parent] — the template's
     *     single-entry expansion renders the parent's children.
     *   - children (parent == host page): nothing deferred (useChildPages set
     *     in pass 1).
     *
     * Blocks are located by blockIndex — the 0-based position among the matrix
     * blocks MatrixBuilder emitted, which matches the saved contentBlocks order.
     * Nested matrix blocks are elements, so each modified block is saved
     * directly; the owner entry is never re-saved. Unresolvable slugs degrade
     * to a warning on the page result.
     *
     * @param array $pageResults   Page result array, updated with resolution warnings.
     * @param array $allCardRefs   Deferred ref sets keyed by entryId; each value is a LIST
     *                             of {blockIndex, mode, refs?|parent?, singlePageMode?}.
     * @param array $slugToEntryId In-batch slug→entry ID map from pass 1.
     * @param bool  $dryRun        If true, resolve and warn but write nothing.
     * @return void
     */
    private function _resolveCardReferencesPass2(array &$pageResults, array $allCardRefs, array $slugToEntryId, bool $dryRun): void
    {
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';

        // Resolve a page slug to an entry ID: in-batch map first, then DB fallback.
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

        foreach ($allCardRefs as $entryId => $refSets) {
            // Locate this entry's page result so warnings land on the right row.
            $resultIdx = null;
            foreach ($pageResults as $i => $r) {
                if (($r['entryId'] ?? null) === $entryId) {
                    $resultIdx = $i;
                    break;
                }
            }

            if ($resultIdx === null) {
                continue;
            }

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
                    $pageResults[$resultIdx]['warnings'][] = 'Cards block at position '
                        . var_export($blockIndex, true) . ' not found — card references skipped.';
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
                        $pageResults[$resultIdx]['warnings'][] = "Card reference slug '{$slug}' not found — card left empty.";
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
                            $pageResults[$resultIdx]['warnings'][] = "Card reference slug '{$slug}' not found — card skipped.";
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
                        $pageResults[$resultIdx]['warnings'][] = "Children parent slug '{$slug}' not found — children cards not populated.";
                    }
                }

                if ($dirty && !$dryRun) {
                    try {
                        // Nested matrix blocks are elements — saving the block
                        // persists its field changes; the owner needs no re-save.
                        if (!Craft::$app->elements->saveElement($block)) {
                            $pageResults[$resultIdx]['warnings'][] = 'Could not save card references: '
                                . implode('; ', $block->getFirstErrors());
                        }
                    } catch (Throwable $e) {
                        $pageResults[$resultIdx]['warnings'][] = 'Could not save card references: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('contentiq-importer', 'Syncing content from ContentiQ');
    }

    /**
     * Whether the single globals-sync row is locked. A missing row is treated
     * as locked (the safe default).
     *
     * @return bool
     */
    private function _globalsLocked(): bool
    {
        $row = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();

        if ($row === null) {
            return true;
        }

        return (bool)$row['locked'];
    }

    /**
     * Relocks the globals-sync row and stamps synced_at, creating the row if
     * absent.
     *
     * @return void
     */
    private function _relockGlobals(): void
    {
        $db  = Craft::$app->getDb();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true, 'synced_at' => $now])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert('{{%contentiq_globals_sync}}', ['locked' => true, 'synced_at' => $now])
            ->execute();
    }

    /**
     * Marks the run as failed with an error message.
     *
     * @param string $error
     */
    private function _failRun(string $error): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'status'      => 'errors',
                'result'      => Json::encode([['success' => false, 'slug' => '', 'error' => $error, 'warnings' => []]]),
                'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->runId],
        )->execute();

        // Relock globals so a persisted unlock consent does not survive a failed
        // run and silently drive the next sync. Only touch an existing row.
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            Craft::$app->getDb()->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true])
                ->execute();
        }
    }
}

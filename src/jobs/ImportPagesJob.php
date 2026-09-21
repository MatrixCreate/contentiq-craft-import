<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseBatchedJob;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\models\SyncRun;
use matrixcreate\contentiqimporter\services\SyncRunService;
use Throwable;
use yii\queue\PushEvent;
use yii\queue\Queue as YiiQueue;
use yii\queue\RetryableJobInterface;

/**
 * Second job in the batched sync pipeline — phase `importing`.
 *
 * Runs `ImportService::importPage()` (or the locked/deselected skip paths)
 * over every planned page, one `contentiq_sync_pages` row at a time,
 * checkpointing after each row so a killed batch resumes without
 * re-importing what already succeeded.
 *
 * Ported from the per-page body of `SyncJob::execute()`'s PASS 1
 * (SyncJob.php ~197-442) — content-type routing, the lock check + assets-only
 * path, the deselected-"New" check, `importPage()`, parent resolution and
 * Structure positioning, and every key that used to land in
 * `$pageResults[]` now lands unchanged in the row's `result` JSON. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.2/§3.4.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class ImportPagesJob extends BaseBatchedJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================

    /**
     * The contentiq_import_runs id this job imports pages for.
     *
     * @var int
     */
    public int $runId;

    // Private Properties
    // =========================================================================

    /**
     * The queue this job is currently executing under — stashed by
     * {@see execute()} so {@see processItem()} (which BaseBatchedJob calls
     * without a $queue argument) can still report a custom progress label.
     *
     * @var mixed
     */
    private mixed $_queue = null;

    /**
     * This run's slug → entry ID map, rebuilt fresh every batch by
     * {@see beforeBatch()} — see CRAFT-IMPORT-QUEUE-SPEC.md §3.4 (state that
     * used to live in one PHP process across the whole run must be rebuilt
     * per job/batch instead).
     *
     * @var array<string, int>
     */
    private array $_slugToEntryId = [];

    /**
     * This run's Pages section handle, resolved once by StageRunJob and read
     * back from `run.state` at the start of every batch.
     *
     * @var string|null
     */
    private ?string $_sectionHandle = null;

    /**
     * This run's Pages section Structure id, resolved once by StageRunJob
     * and read back from `run.state` at the start of every batch.
     *
     * @var int|null
     */
    private ?int $_structureId = null;

    /**
     * ContentIQ page ids for "New" (never-imported) rows the user
     * deselected on the Sync screen — `run.options.newSelections`, read back
     * once per batch. See SyncJob::$excludeNewIds for the same concept under
     * its pre-pipeline name.
     *
     * @var int[]
     */
    private array $_newSelections = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->batchSize = SyncQueueConfig::pageBatchSize();
        $this->ttr        = SyncQueueConfig::ttr();
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $this->_queue = $queue;

        $plugin = ContentIQImporter::$plugin;
        $run    = $plugin->syncRuns->loadRun($this->runId);

        if ($run === null || $run->phase !== SyncRun::PHASE_IMPORTING) {
            Craft::info(
                'ContentIQImporter: ImportPagesJob skipped for run ' . $this->runId . ' — phase is '
                . ($run?->phase ?? 'missing') . ', not importing (stale retry or double push).',
                __METHOD__,
            );
            return;
        }

        // Web requests already run from webroot; queue workers run from the
        // project root — match SyncJob's own chdir (SyncJob.php ~81) so
        // asset writes (relative local-filesystem volume paths) resolve
        // correctly from inside importPage()/importPageAssetsOnly().
        chdir(Craft::getAlias('@webroot'));

        // BaseBatchedJob::execute() pushes a clone of this job for the next
        // batch and discards the new queue id. The run row must always point
        // at the LIVE job (the CP staleness check and sync/run --wait both
        // read run.queueJobId), so capture the clone's id from the queue's
        // after-push event while the parent runs. Only same-class pushes for
        // this run are recorded; after() pushes the next phase's job and
        // records that id itself.
        $syncRuns = $plugin->syncRuns;
        $runId    = $this->runId;
        $onPush   = static function (PushEvent $event) use ($syncRuns, $runId): void {
            if ($event->job instanceof self && $event->job->runId === $runId && $event->id !== null) {
                $syncRuns->setQueueJobId($runId, (int)$event->id);
            }
        };
        $queue->on(YiiQueue::EVENT_AFTER_PUSH, $onPush);

        try {
            parent::execute($queue);
        } catch (Throwable $e) {
            Craft::error(
                'ContentIQImporter ImportPagesJob exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                __METHOD__,
            );
            $plugin->syncRuns->fail($this->runId, 'Unexpected error: ' . $e->getMessage());
            $plugin->locks->relockGlobals('Unexpected error: ' . $e->getMessage());
            throw $e;
        } finally {
            $queue->off(YiiQueue::EVENT_AFTER_PUSH, $onPush);
        }
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return SyncQueueConfig::ttr();
    }

    /**
     * @inheritdoc
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt <= 3;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Translation::prep('contentiq-importer', 'Importing pages from ContentIQ');
    }

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        // No status filter — every row of the run, regardless of status.
        // See SyncRunService::pageBatchQuery()'s docblock for why a
        // shrinking "pending only" set would make QueryBatcher's offset skip
        // rows between batches. processItem() below is what actually
        // enforces "only touch pending rows".
        return new QueryBatcher(ContentIQImporter::$plugin->syncRuns->pageBatchQuery($this->runId));
    }

    /**
     * @inheritdoc
     */
    protected function beforeBatch(): void
    {
        $plugin  = ContentIQImporter::$plugin;
        $service = $plugin->syncRuns;

        // Resets per-run state (currently just the "first global CTA block
        // wins" tracking) so this batch never inherits state left over from
        // a previous run executed earlier in the same PHP process — see
        // ImportService::beginRun(). Restored immediately after with this
        // run's own persisted state (§3.4) — beginRun() must run first, or
        // it would wipe out what restoreRunState() just set.
        $plugin->imports->beginRun();

        $run = $service->loadRun($this->runId);
        $plugin->imports->restoreRunState($run->state ?? []);

        $this->_slugToEntryId = $service->slugToEntryIdMap($this->runId);
        $this->_sectionHandle = $run->state['sectionHandle'] ?? null;
        $this->_structureId   = $run->state['structureId'] ?? null;

        $newSelections        = $run->options['newSelections'] ?? [];
        $this->_newSelections = is_array($newSelections)
            ? array_values(array_map('intval', array_filter($newSelections, 'is_scalar')))
            : [];
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        $step  = $this->itemOffset + 1;
        $total = $this->totalItems();

        $this->setProgress($this->_queue, $total > 0 ? $step / $total : 1, "Importing page {$step} of {$total}");

        $service = ContentIQImporter::$plugin->syncRuns;
        $row     = $service->loadPage((int)$item['id']);

        // Idempotency: a row a previous (killed) execution already finished
        // is left untouched on retry — only `pending` rows are ever
        // imported.
        if ($row === null || $row['status'] !== SyncRunService::STATUS_PENDING) {
            $service->touch($this->runId);
            return;
        }

        try {
            $this->_processPage($row);
        } catch (Throwable $e) {
            Craft::error(
                'ContentIQImporter: import failed for contentiq_sync_pages #' . $row['id'] . ': ' . $e->getMessage(),
                __METHOD__,
            );

            $service->markPage($row['id'], SyncRunService::STATUS_FAILED, null, [
                'success'  => false,
                'slug'     => $row['slug'] ?? '',
                'warnings' => [$e->getMessage()],
            ]);
        }

        $service->touch($this->runId);
    }

    /**
     * @inheritdoc
     */
    protected function afterBatch(): void
    {
        $service = ContentIQImporter::$plugin->syncRuns;

        $service->setState($this->runId, array_merge(
            $service->getState($this->runId),
            ContentIQImporter::$plugin->imports->exportRunState(),
        ));
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        $plugin = ContentIQImporter::$plugin;

        $plugin->syncRuns->advance($this->runId, SyncRun::PHASE_POSTPASS);

        $jobId = QueueHelper::push(new PostPassJob(['runId' => $this->runId]), null, 0, SyncQueueConfig::ttr());
        $plugin->syncRuns->setQueueJobId($this->runId, $jobId !== null ? (int)$jobId : null);
    }

    // Private Methods
    // =========================================================================

    /**
     * Imports (or skips) one page — the per-page body of `SyncJob::
     * execute()`'s PASS 1, ported to work against a single sync_pages row
     * instead of a loop over the whole export. Every branch marks the row's
     * final status/entry_id/result itself; the caller's try/catch handles
     * only genuine Throwables.
     *
     * @param array $row A `pending` contentiq_sync_pages row, as returned by
     *   `SyncRunService::loadPage()` (`payload` already decoded).
     * @return void
     */
    private function _processPage(array $row): void
    {
        $plugin        = ContentIQImporter::$plugin;
        $service       = $plugin->syncRuns;
        $importService = $plugin->imports;

        $payload = $row['payload'];

        if (!is_array($payload)) {
            // Defensive — SyncPlanner never leaves a `pending` row without a
            // payload, but a missing one degrades to a report-visible
            // failure rather than a fatal TypeError inside importPage().
            $service->markPage($row['id'], SyncRunService::STATUS_FAILED, null, [
                'success'  => false,
                'slug'     => $row['slug'] ?? '',
                'warnings' => ['Row has no payload to import.'],
            ]);
            return;
        }

        // Collection children route to their configured section, not Pages.
        $contentType   = $payload['document']['content_type'] ?? null;
        $route         = $importService->getContentTypeRoute($contentType);
        $lookupSection = $route['section'] ?? $this->_sectionHandle;

        // Skip locked entries. Resolution goes through the same shared
        // resolver ImportService uses internally (findExistingEntry), so
        // the lock check and the import it's gating always agree on which
        // entry a page maps to.
        $pageSlug        = $payload['document']['slug'] ?? '';
        $existingEntry   = $importService->findExistingEntry($payload);
        $isDuplicateSlug = (bool)($row['duplicate_slug'] ?? false);

        if ($existingEntry !== null) {
            // A missing sync row means this entry has never been through a
            // ContentIQ sync — treat it as locked (the safe default), so a
            // hand-built entry that collides with a synced slug/section is
            // never silently overwritten just because it has no row yet.
            $syncRow = (new Query())
                ->select(['locked', 'notes'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $existingEntry->id])
                ->one();

            $isLocked = $syncRow === null ? true : (bool)$syncRow['locked'];

            if ($isLocked) {
                // Asset filing never touches entry content, so it's exempt
                // from the lock — file this page's assets[]/files[] even
                // though nothing else runs for it.
                $assetsOnly = $importService->importPageAssetsOnly($payload);

                $lockedWarnings = array_merge(['Skipped — entry is locked.'], $assetsOnly['warnings']);

                if ($isDuplicateSlug) {
                    $lockedWarnings[] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
                }

                $result = [
                    'success'       => true,
                    'slug'          => $pageSlug,
                    'entryId'       => $existingEntry->id,
                    'entryFound'    => true,
                    'title'         => $payload['document']['title'] ?? $pageSlug,
                    'depth'         => $payload['document']['depth'] ?? 0,
                    'parentSlug'    => $payload['document']['parent_slug'] ?? null,
                    'blocks'        => [],
                    'images'        => [],
                    'pageAssets'    => $assetsOnly['pageAssets'],
                    'pageFiles'     => $assetsOnly['pageFiles'],
                    // Nothing is written this run for a locked entry, so
                    // 'blocks' stays genuinely empty. blockNotes has a real
                    // stored value from the last successful sync — surface
                    // it rather than a hardcoded '' so the report doesn't
                    // imply the entry has no content.
                    'blockNotes'    => (string)($syncRow['notes'] ?? ''),
                    'seoFieldCount' => 0,
                    'warnings'      => $lockedWarnings,
                    'error'         => null,
                    'contentType'   => $contentType,
                    'sectionLabel'  => $contentType !== null
                        ? (Craft::$app->entries->getSectionByHandle($lookupSection)?->name ?? $contentType)
                        : null,
                    // Tells the auto-lock step (FinaliseRunJob) to leave
                    // this entry's sync row untouched — it was skipped, not
                    // synced.
                    'skippedLocked' => true,
                ];

                if ($pageSlug !== '') {
                    $this->_slugToEntryId[$pageSlug] = $existingEntry->id;
                }

                $service->markPage($row['id'], SyncRunService::STATUS_SKIPPED_LOCKED, $existingEntry->id, $result);
                return;
            }
        }

        // Skip pages the user deselected on the Sync screen's "New" rows.
        // Only applies when the page genuinely doesn't exist yet
        // ($existingEntry === null, resolved via the same
        // findExistingEntry() call above) — an id that raced to become an
        // existing entry between page-load and this run must still go
        // through the normal import/lock path, never be silently skipped as
        // if it were still new.
        $contentiqPageId = $row['contentiq_page_id'] !== null ? (int)$row['contentiq_page_id'] : null;
        $isDeselectedNew = $existingEntry === null
            && $contentiqPageId !== null
            && in_array($contentiqPageId, $this->_newSelections, true);

        if ($isDeselectedNew) {
            $deselectedWarnings = ['Skipped — deselected.'];

            if ($isDuplicateSlug) {
                $deselectedWarnings[] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
            }

            $result = [
                'success'       => true,
                'slug'          => $pageSlug,
                'entryId'       => null,
                'entryFound'    => false,
                'title'         => $payload['document']['title'] ?? $pageSlug,
                'depth'         => $payload['document']['depth'] ?? 0,
                'parentSlug'    => $payload['document']['parent_slug'] ?? null,
                'blocks'        => [],
                'images'        => [],
                'blockNotes'    => '',
                'seoFieldCount' => 0,
                'warnings'      => $deselectedWarnings,
                'error'         => null,
                'contentType'   => $contentType,
                'sectionLabel'  => $contentType !== null
                    ? (Craft::$app->entries->getSectionByHandle($lookupSection)?->name ?? $contentType)
                    : null,
                // Tells the ack step and auto-lock step (FinaliseRunJob)
                // this page was never written — no entry was created, so
                // there's nothing to acknowledge or lock.
                'skippedDeselected' => true,
            ];

            // Deliberately NOT added to $this->_slugToEntryId — this page
            // was never created, so there's no entry id to record. Any
            // child of this page falls through to the "parent slug not
            // found" warning path below and imports at root level; the next
            // sync (once the parent is no longer deselected) self-heals the
            // hierarchy.
            $service->markPage($row['id'], SyncRunService::STATUS_SKIPPED_DESELECTED, null, $result);
            return;
        }

        $result = $importService->importPage($payload, dryRun: false);

        // Handle hierarchy: always re-apply parent and position on every
        // run.
        $parentSlug = $payload['document']['parent_slug'] ?? null;
        $entryId    = $result['entryId'] ?? null;
        $slug       = $result['slug'] ?? '';
        $isHomepage = (bool)($payload['document']['is_homepage'] ?? false);

        // Thread ContentIQ's own sibling order through to the auto-lock step
        // (FinaliseRunJob), which persists it into contentiq_entry_syncs so
        // future syncs can position this page's siblings correctly even on
        // a run that doesn't re-import this page itself.
        $result['sortOrder'] = (int)($payload['document']['sort_order'] ?? 0);

        // Collection children are routed to their own section and have no
        // Craft parent — skip Pages Structure positioning/parent resolution
        // for them entirely.
        if ($contentType === null && $entryId !== null && $this->_structureId !== null && !$isHomepage) {
            $entry = Entry::find()->id($entryId)->status(null)->one();

            if ($entry !== null) {
                $parentId = null;

                if ($parentSlug !== null && $parentSlug !== '') {
                    // Try current-batch/current-run map first, then fall
                    // back to a Craft query so re-imports (and parents
                    // imported in an earlier batch) correctly place
                    // children.
                    $parentId = $this->_slugToEntryId[$parentSlug] ?? null;

                    if ($parentId === null) {
                        $parentEntry = Entry::find()
                            ->section($this->_sectionHandle)
                            ->slug(Db::escapeParam((string)$parentSlug))
                            ->status(null)
                            ->one();
                        $parentId = $parentEntry?->id;
                    }

                    if ($parentId === null) {
                        $result['warnings'][] = "Parent slug '{$parentSlug}' not found — entry saved at root level.";
                    }
                }

                $importService->positionInStructure(
                    entry: $entry,
                    parentId: $parentId,
                    structureId: $this->_structureId,
                    sortOrder: $result['sortOrder'],
                    contentiqPageId: $contentiqPageId,
                    warnings: $result['warnings'],
                );
            }
        }

        if ($isDuplicateSlug) {
            $result['warnings'][] = "Duplicate slug '{$pageSlug}' — a page earlier in this export already used this slug; hierarchy and card-reference resolution may point at the wrong entry.";
        }

        // Track slug → entry ID for card ref lookups and later-batch parent
        // resolution.
        if ($entryId !== null && $slug !== '') {
            $this->_slugToEntryId[$slug] = $entryId;
        }

        // Attach hierarchy metadata for the report template.
        $result['title']      = $payload['document']['title'] ?? $slug;
        $result['depth']      = $payload['document']['depth'] ?? 0;
        $result['parentSlug'] = $payload['document']['parent_slug'] ?? null;

        // Thread the stable ContentIQ page id through to the auto-lock step
        // (FinaliseRunJob), which persists it into contentiq_entry_syncs so
        // findExistingEntry() can resolve this entry by id next sync, even
        // if its slug changes.
        $result['contentiqPageId'] = $contentiqPageId;

        $service->markPage($row['id'], SyncRunService::STATUS_IMPORTED, $entryId, $result);
    }
}

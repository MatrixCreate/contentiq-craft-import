<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\QueryBatcher;
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
 * Third job in the batched sync pipeline — phase `postpass`.
 *
 * Runs `ImportService::runPostPasses()` (card references, the link sweep,
 * legacy-URL redirects) over every imported page, batch by batch, so a run
 * of any size resolves its cross-page references without holding every
 * page's result in memory at once.
 *
 * Ported from `SyncJob::execute()`'s "5. Post-passes" step (SyncJob.php
 * ~446-465), split across `beforeBatch()`/`processItem()`/`afterBatch()`
 * instead of running once over the whole run's `$pageResults` array. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.2.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class PostPassJob extends BaseBatchedJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================

    /**
     * The contentiq_import_runs id this job post-processes.
     *
     * @var int
     */
    public int $runId;

    // Private Properties
    // =========================================================================

    /**
     * The queue this job is currently executing under — see
     * ImportPagesJob::$_queue for why processItem() needs this stashed.
     *
     * @var mixed
     */
    private mixed $_queue = null;

    /**
     * Full contentiq_sync_pages rows (payload=null, result decoded) this
     * batch's `processItem()` has collected for post-passing — rows whose
     * `postpass_done` flag was still false. Processed and cleared by
     * `afterBatch()`; reset again at the top of every `beforeBatch()` as a
     * defensive belt-and-braces (afterBatch() already empties it, but a
     * batch that throws before reaching afterBatch() must not leak into the
     * next).
     *
     * @var array
     */
    private array $_batchBuffer = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        parent::__construct($config);

        $this->batchSize = SyncQueueConfig::postPassBatchSize();
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

        if ($run === null || $run->phase !== SyncRun::PHASE_POSTPASS) {
            Craft::info(
                'ContentIQImporter: PostPassJob skipped for run ' . $this->runId . ' — phase is '
                . ($run?->phase ?? 'missing') . ', not postpass (stale retry or double push).',
                __METHOD__,
            );
            return;
        }

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
                'ContentIQImporter PostPassJob exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
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
        return Translation::prep('contentiq-importer', 'Resolving links and card references for ContentIQ sync');
    }

    /**
     * @inheritdoc
     */
    protected function loadData(): Batchable
    {
        // Restricted to `imported` — unlike ImportPagesJob's all-statuses
        // query, this set does NOT shrink during the postpass phase:
        // postpass_done is a separate flag, not a status transition, so
        // QueryBatcher's fixed offsets stay valid across batches.
        return new QueryBatcher(ContentIQImporter::$plugin->syncRuns->pageBatchQuery(
            $this->runId,
            [SyncRunService::STATUS_IMPORTED],
        ));
    }

    /**
     * @inheritdoc
     */
    protected function beforeBatch(): void
    {
        $this->_batchBuffer = [];
    }

    /**
     * @inheritdoc
     */
    protected function processItem(mixed $item): void
    {
        $step  = $this->itemOffset + 1;
        $total = $this->totalItems();

        $this->setProgress($this->_queue, $total > 0 ? $step / $total : 1, "Resolving links for page {$step} of {$total}");

        $row = ContentIQImporter::$plugin->syncRuns->loadPage((int)$item['id']);

        if ($row === null || (bool)($row['postpass_done'] ?? false)) {
            // Idempotency: a row a previous (killed) execution already
            // post-passed is left untouched on retry.
            return;
        }

        $this->_batchBuffer[] = $row;
    }

    /**
     * @inheritdoc
     */
    protected function afterBatch(): void
    {
        if (empty($this->_batchBuffer)) {
            return;
        }

        $plugin  = ContentIQImporter::$plugin;
        $service = $plugin->syncRuns;

        // Build $allCardRefs from this batch's buffered results — the same
        // shape SyncJob accumulated in-memory across the whole run
        // (SyncJob.php ~416-418), here scoped to one batch since
        // runPostPasses()'s passes are per-target loops with their own
        // one-query preload and accept a subset just fine.
        $allCardRefs = [];
        $pageResults = [];

        foreach ($this->_batchBuffer as $row) {
            $result = $row['result'];

            if (!is_array($result)) {
                continue;
            }

            $pageResults[] = $result;

            $entryId = $row['entry_id'] !== null ? (int)$row['entry_id'] : null;

            if ($entryId !== null && !empty($result['cardRefs'])) {
                $allCardRefs[$entryId] = array_values($result['cardRefs']);
            }
        }

        // Whole-run slug → entry ID map — pass 2 needs every page's slug,
        // not just this batch's. One query per batch; cheap.
        $slugToEntryId = $service->slugToEntryIdMap($this->runId);

        // IMPORTANT: $pageResults here is exactly this batch's buffered
        // importPage() result rows, unmodified — never hand-built (see
        // AGENTS.md's runPostPasses() hard limit).
        $cardWarnings = $plugin->imports->runPostPasses($allCardRefs, $slugToEntryId, $pageResults);

        foreach ($this->_batchBuffer as $row) {
            $result  = $row['result'];
            $entryId = $row['entry_id'] !== null ? (int)$row['entry_id'] : null;

            if (is_array($result) && $entryId !== null && !empty($cardWarnings[$entryId])) {
                $result['warnings'] = array_merge($result['warnings'] ?? [], $cardWarnings[$entryId]);
                $service->markPage($row['id'], SyncRunService::STATUS_IMPORTED, $entryId, $result);
            }
        }

        $service->markPostPassDone(array_column($this->_batchBuffer, 'id'));

        $this->_batchBuffer = [];
    }

    /**
     * @inheritdoc
     */
    protected function after(): void
    {
        $plugin = ContentIQImporter::$plugin;

        $plugin->syncRuns->advance($this->runId, SyncRun::PHASE_FINALISING, SyncRun::STEP_ACK);

        $jobId = QueueHelper::push(new FinaliseRunJob(['runId' => $this->runId]), null, 0, SyncQueueConfig::finaliseTtr());
        $plugin->syncRuns->setQueueJobId($this->runId, $jobId !== null ? (int)$jobId : null);
    }
}

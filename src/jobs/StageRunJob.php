<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\helpers\Queue as QueueHelper;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\models\SyncRun;
use matrixcreate\contentiqimporter\services\SyncRunService;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * First job in the batched sync pipeline — phase `staging`.
 *
 * Applies this run's lock/consent selections, obtains the export payload
 * (API fetch, or the file a controller/console command already saved for
 * `upload`/`cli`), plans it into one `contentiq_sync_pages` row per page via
 * SyncPlanner/SyncRunService::stageRun(), resolves the section/Structure
 * pages will be positioned against, and hands off to ImportPagesJob.
 *
 * Ported from the setup half of `SyncJob::execute()` — lock/consent
 * application, the export fetch + envelope validation, and the pre-loop
 * section/Structure resolution (SyncJob.php ~92-168). See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.2.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class StageRunJob extends BaseJob implements RetryableJobInterface
{
    // Public Properties
    // =========================================================================

    /**
     * The contentiq_import_runs id this job stages.
     *
     * @var int
     */
    public int $runId;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin  = ContentIQImporter::$plugin;
        $service = $plugin->syncRuns;

        $run = $service->loadRun($this->runId);

        // Idempotency/staleness guard: a run only ever has one StageRunJob
        // meaningfully execute against it. A phase mismatch means either a
        // double-push (Craft re-delivering a message) or a stale retry of a
        // run the staleness fallback has already failed — either way, doing
        // nothing here is correct, not an error.
        if ($run === null || $run->phase !== SyncRun::PHASE_STAGING) {
            Craft::info(
                'ContentIQImporter: StageRunJob skipped for run ' . $this->runId . ' — phase is '
                . ($run?->phase ?? 'missing') . ', not staging (stale retry or double push).',
                __METHOD__,
            );
            return;
        }

        try {
            // One run at a time per site (§3.6) — the run row IS the lock.
            // Re-checked here, inside the job about to apply lock state and
            // start writing, rather than trusting only the controller's
            // request-time check. The OLDER non-terminal run always wins:
            // if any other run with a lower id is still in flight, this run
            // yields (fails itself); a newer run created while this one was
            // queued will yield to us when its own StageRunJob runs. The
            // check is serialised by Craft's mutex for the duration of this
            // execution only — Craft's DB mutex is connection-scoped, so it
            // can never be held across jobs (spec §2).
            $mutex     = Craft::$app->getMutex();
            $mutexName = 'contentiq-sync-stage';
            $locked    = $mutex->acquire($mutexName, 5);

            try {
                $older = $service->olderActiveRun($run->id);
            } finally {
                if ($locked) {
                    $mutex->release($mutexName);
                }
            }

            if ($older !== null) {
                $this->_fail($service, $plugin, "Another sync (run {$older->id}) is still in progress.");
                return;
            }

            // Apply lock/unlock state and globals consent for this run here
            // — at job execution, never at HTTP request time — so a worker
            // that never picks up this job leaves every lock/consent
            // untouched instead of leaking an unlock across a run that never
            // actually executed. Applied before the payload is even fetched,
            // matching SyncJob, so the per-page lock check further down the
            // pipeline reads the intended state.
            $plugin->locks->applyLockState($run->options['unlockIds'] ?? null);
            $plugin->locks->setGlobalsLock(!($run->options['unlockGlobals'] ?? false));

            $fetch = $this->_fetchPayload($run, $service, $plugin);

            if (!$fetch['ok']) {
                // _fetchPayload() already failed the run.
                return;
            }

            $decoded = $fetch['data'];

            // Validate the decoded export is an object before touching it —
            // a non-array/scalar body (null, "ok", a bare number) decodes
            // successfully but would otherwise reach the planner as a
            // scalar and throw outside any per-page catch.
            if (!is_array($decoded)) {
                $this->_fail($service, $plugin, 'Malformed export: expected an object.');
                return;
            }

            $plan = $plugin->planner->plan($decoded);
            unset($decoded); // Free the whole decoded export before pushing on — §3.2.

            $service->stageRun($this->runId, $plan);

            // Resolve section/Structure for the page loop once here, rather
            // than per page/per batch — read back from run.state by
            // ImportPagesJob on every batch.
            $config        = Craft::$app->config->getConfigFromFile('contentiq');
            $sectionHandle = $config['section'] ?? 'pages';
            $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
            $structureId   = $section?->structureId;

            $service->setState($this->runId, array_merge($plan['state'] ?? [], [
                'sectionHandle' => $sectionHandle,
                'structureId'   => $structureId,
            ]));

            $service->advance($this->runId, SyncRun::PHASE_IMPORTING);

            $jobId = QueueHelper::push(new ImportPagesJob(['runId' => $this->runId]), null, 0, SyncQueueConfig::ttr());
            $service->setQueueJobId($this->runId, $jobId !== null ? (int)$jobId : null);
        } catch (Throwable $e) {
            Craft::error(
                'ContentIQImporter StageRunJob exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                __METHOD__,
            );
            $service->fail($this->runId, 'Unexpected error: ' . $e->getMessage());
            $plugin->locks->relockGlobals('Unexpected error: ' . $e->getMessage());
            throw $e;
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
        return Translation::prep('contentiq-importer', 'Staging ContentIQ sync');
    }

    // Private Methods
    // =========================================================================

    /**
     * Obtains the decoded export envelope for this run's `source`.
     *
     * `api` fetches from the ContentIQ API (`ContentIQApiService::
     * fetchExport()`); `upload`/`cli` read + decode the file a
     * controller/console command already saved and recorded at
     * `run.options.file`.
     *
     * @param SyncRun $run
     * @param SyncRunService $service
     * @param ContentIQImporter $plugin
     * @return array{ok: bool, data: mixed} `ok: false` means the run has
     *   already been failed (with an explanatory message) by this method —
     *   the caller's only job is to stop.
     */
    private function _fetchPayload(SyncRun $run, SyncRunService $service, ContentIQImporter $plugin): array
    {
        if ($run->source === 'api') {
            $apiResult = $plugin->api->fetchExport();

            if (!($apiResult['success'] ?? false)) {
                $this->_fail($service, $plugin, $apiResult['error'] ?? 'API request failed.');
                return ['ok' => false, 'data' => null];
            }

            return ['ok' => true, 'data' => $apiResult['data']];
        }

        // upload/cli — the controller/console command saved the raw export
        // JSON to a file and recorded its path in run.options.file.
        $file = $run->options['file'] ?? null;

        if (!is_string($file) || $file === '' || !is_file($file)) {
            $this->_fail($service, $plugin, 'Malformed export: uploaded file not found.');
            return ['ok' => false, 'data' => null];
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            $this->_fail($service, $plugin, 'Malformed export: could not read uploaded file.');
            return ['ok' => false, 'data' => null];
        }

        $decoded = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->_fail($service, $plugin, 'Malformed export: invalid JSON.');
            return ['ok' => false, 'data' => null];
        }

        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * Fails the run and relocks globals — mirrors SyncJob::_failRun(), moved
     * here since every early-return failure path in this job needs it.
     *
     * @param SyncRunService $service
     * @param ContentIQImporter $plugin
     * @param string $message
     * @return void
     */
    private function _fail(SyncRunService $service, ContentIQImporter $plugin, string $message): void
    {
        $service->fail($this->runId, $message);
        $plugin->locks->relockGlobals($message);
    }
}

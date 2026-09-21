<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\models\SyncRun;
use matrixcreate\contentiqimporter\services\SyncRunService;
use Throwable;
use yii\queue\RetryableJobInterface;

/**
 * Fourth and final job in the batched sync pipeline — phase `finalising`,
 * stepped and resumable via `run.step`.
 *
 * Runs the four `finalising` sub-steps in order — `ack` (acknowledge
 * genuinely-written pages back to ContentIQ), `globals` (drift check +
 * consented import), `lock` (the auto-lock upsert loop), `report` (assemble
 * the legacy `result` JSON and finish the run) — advancing `run.step` after
 * each so a retry resumes at the failed step rather than redoing completed
 * work.
 *
 * Ported from the tail of `SyncJob::execute()` (ack ~467-499, globals
 * ~501-531, status determination ~533-540, the run row write ~542-554,
 * auto-lock ~556-614) plus `_failRun()`. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.2.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class FinaliseRunJob extends BaseJob implements RetryableJobInterface
{
    // Constants
    // =========================================================================

    /** @var string[] Step order — a retry resumes at `run.step`'s index in this list. */
    private const STEP_ORDER = [
        SyncRun::STEP_ACK,
        SyncRun::STEP_GLOBALS,
        SyncRun::STEP_LOCK,
        SyncRun::STEP_REPORT,
    ];

    // Public Properties
    // =========================================================================

    /**
     * The contentiq_import_runs id this job finalises.
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

        // The globals step downloads brand/logo images through
        // ImageImportService — a local-filesystem-volume write — so match
        // ImportPagesJob/SyncJob's chdir(@webroot) before any of it runs
        // (AGENTS.md hard limit; relative volume paths break under
        // queue/listen otherwise).
        chdir(Craft::getAlias('@webroot'));

        $run = $service->loadRun($this->runId);

        if ($run === null || $run->phase !== SyncRun::PHASE_FINALISING) {
            Craft::info(
                'ContentIQImporter: FinaliseRunJob skipped for run ' . $this->runId . ' — phase is '
                . ($run?->phase ?? 'missing') . ', not finalising (stale retry or double push).',
                __METHOD__,
            );
            return;
        }

        $startIndex = array_search($run->step, self::STEP_ORDER, true);
        $startIndex = $startIndex === false ? 0 : $startIndex;

        // $ackWarning/$globalsReport are threaded through this one execution
        // for the `report` step to assemble the final result JSON from. A
        // resumed execution that starts mid-way (e.g. at `lock`, because
        // `ack`/`globals` already completed and advanced `run.step` on a
        // PRIOR execution) restores them from run.state instead — each of
        // the `ack`/`globals` step handlers below persists its own outcome
        // there as it finishes, precisely so a later execution of this same
        // job (a fresh PHP process, with no surviving local variables) can
        // still find them.
        $state         = $run->state;
        $ackWarning    = $state['ackWarning'] ?? null;
        $globalsReport = $state['globalsReport'] ?? null;

        try {
            for ($i = $startIndex; $i < count(self::STEP_ORDER); $i++) {
                $step = self::STEP_ORDER[$i];

                switch ($step) {
                    case SyncRun::STEP_ACK:
                        $ackWarning          = $this->_runAckStep($run, $service, $plugin);
                        $state['ackWarning'] = $ackWarning;
                        $service->setState($this->runId, $state);
                        $service->touch($this->runId);
                        break;

                    case SyncRun::STEP_GLOBALS:
                        $globalsReport          = $this->_runGlobalsStep($run, $plugin);
                        $state['globalsReport'] = $globalsReport;
                        $service->setState($this->runId, $state);
                        $service->touch($this->runId);
                        break;

                    case SyncRun::STEP_LOCK:
                        $this->_runLockStep($service);
                        $service->touch($this->runId);
                        break;

                    case SyncRun::STEP_REPORT:
                        $this->_runReportStep($service, $ackWarning, $globalsReport);
                        // finish() already set phase=done — terminal, nothing left to advance.
                        return;
                }

                $nextStep = self::STEP_ORDER[$i + 1] ?? null;
                $service->advance($this->runId, SyncRun::PHASE_FINALISING, $nextStep);
            }
        } catch (Throwable $e) {
            Craft::error(
                'ContentIQImporter FinaliseRunJob exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
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
        return SyncQueueConfig::finaliseTtr();
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
        return Translation::prep('contentiq-importer', 'Finalising ContentIQ sync');
    }

    // Private Methods
    // =========================================================================

    /**
     * `ack` step — acknowledges pages genuinely imported this run so
     * ContentIQ can retire its own pending-import state. Best-effort: a
     * failure here is surfaced as a run warning, never fails the run.
     * Skipped entirely for `upload`/`cli` runs (today's SyncJob/
     * CpController behaviour — there is no ContentIQ-side pending state to
     * retire for a file that didn't come from the API).
     *
     * Chunked at 500 (the API's `max:500`); `acked_at` is stamped per
     * successful chunk, so a resumed ack step only resends the remainder.
     *
     * @param SyncRun $run
     * @param SyncRunService $service
     * @param ContentIQImporter $plugin
     * @return string|null A warning message, or null on full success.
     */
    private function _runAckStep(SyncRun $run, SyncRunService $service, ContentIQImporter $plugin): ?string
    {
        if (in_array($run->source, ['upload', 'cli'], true)) {
            return null;
        }

        $rows = $service->pagesToAck($this->runId);

        if (empty($rows)) {
            return null;
        }

        $warning = null;

        foreach (array_chunk($rows, 500) as $chunk) {
            $pageIds = array_values(array_filter(array_map(
                static fn (array $row): int => (int)$row['contentiq_page_id'],
                $chunk,
            )));

            $ackResult = $plugin->api->ackPages($pageIds);

            if ($ackResult['success'] ?? false) {
                $service->stampAcked(array_column($chunk, 'id'));
            } else {
                $warning = 'Could not acknowledge imported pages with ContentIQ: ' . ($ackResult['error'] ?? 'unknown error');
                Craft::warning("ContentIQImporter: sync ack call failed — {$warning}", __METHOD__);
                // Best-effort — keep going rather than aborting the whole run.
            }
        }

        return $warning;
    }

    /**
     * `globals` step — verbatim from `SyncJob::execute()`'s "6. Import
     * globals" (SyncJob.php ~501-531), reading the run's staged `globals`
     * blob instead of the export envelope directly. URL-prefix drift is
     * advisory and runs read-only regardless of the lock; the write path
     * only runs when the user has consented (row unlocked this run).
     *
     * @param SyncRun $run
     * @param ContentIQImporter $plugin
     * @return array|null The globals report, or null when the export
     *   carried no `globals` key at all.
     */
    private function _runGlobalsStep(SyncRun $run, ContentIQImporter $plugin): ?array
    {
        if ($run->globals === null) {
            return null;
        }

        $globals = $run->globals;
        $drift   = $plugin->globals->checkUrlPrefixDrift($globals['collections'] ?? []);

        if ($plugin->locks->globalsLocked()) {
            return [
                'locked'  => true,
                'skipped' => 'Skipped — globals are locked.',
                'drift'   => $drift,
            ];
        }

        $globalsReport          = $plugin->globals->import($globals, dryRun: false);
        $globalsReport['drift'] = $drift;

        // Relock and stamp on success.
        $plugin->locks->relockGlobals();

        return $globalsReport;
    }

    /**
     * `lock` step — verbatim from `SyncJob::execute()`'s "9. Auto-lock"
     * loop (SyncJob.php ~556-614): after every sync, imported entries are
     * locked so subsequent syncs won't overwrite them unless the user
     * explicitly unlocks them. `SyncRunService::pagesToLock()` already
     * excludes `skippedLocked`/unsuccessful rows and rows this run already
     * locked, so a resumed step only touches the remainder.
     *
     * `locked_at` is stamped per row (not batched at the end) so a killed
     * execution's retry doesn't have to re-upsert entries a prior partial
     * execution already locked.
     *
     * @param SyncRunService $service
     * @return void
     */
    private function _runLockStep(SyncRunService $service): void
    {
        $rows = $service->pagesToLock($this->runId);

        if (empty($rows)) {
            return;
        }

        $db  = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($rows as $row) {
            $result  = $row['result'];
            $entryId = (int)$row['entry_id'];

            $exists = (new Query())
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $entryId])
                ->exists();

            // Record the ContentIQ page id → element_id mapping (when the
            // page carried one) so findExistingEntry() can resolve this
            // entry by id on the next sync even if its slug changes.
            // sort_order persists ContentIQ's own sibling order so a future
            // sync that doesn't re-import this page can still position its
            // siblings correctly — always written (not guarded behind
            // !empty()), since 0 is a legitimate first-sibling value.
            $syncData = [
                'locked'     => true,
                'synced_at'  => $now,
                'notes'      => $result['blockNotes'] ?? '',
                'sort_order' => $result['sortOrder'] ?? 0,
            ];

            if (!empty($result['contentiqPageId'])) {
                $syncData['contentiq_page_id'] = $result['contentiqPageId'];
            }

            if ($exists) {
                $db->createCommand()->update(
                    '{{%contentiq_entry_syncs}}',
                    $syncData,
                    ['element_id' => $entryId],
                )->execute();
            } else {
                $db->createCommand()->insert(
                    '{{%contentiq_entry_syncs}}',
                    array_merge(['element_id' => $entryId], $syncData),
                )->execute();
            }

            $service->stampLocked([$row['id']]);
        }
    }

    /**
     * `report` step — reads every row of the run (all statuses, in export
     * order), assembles the legacy `{pages, globals, ackWarning}` result
     * JSON exactly as SyncJob did, determines the run's overall status, and
     * calls `finish()` (terminal — phase=done).
     *
     * Malformed export entries (`STATUS_SKIPPED_MALFORMED`) never get an
     * individual result row — same as SyncJob, which filtered them out of
     * `$pages` before its loop even started — they're surfaced instead as
     * one combined synthetic entry, prepended to `pages`, matching SyncJob's
     * own single combined warning (SyncJob.php ~176-195).
     *
     * @param SyncRunService $service
     * @param string|null $ackWarning
     * @param array|null $globalsReport
     * @return void
     */
    private function _runReportStep(SyncRunService $service, ?string $ackWarning, ?array $globalsReport): void
    {
        $rows = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $this->runId])
            ->orderBy(['position' => SORT_ASC])
            ->all();

        $pageResults      = [];
        $totalImages      = 0;
        $hasErrors        = false;
        $hasWarnings      = false;
        $skippedMalformed = 0;

        foreach ($rows as $row) {
            if ($row['status'] === SyncRunService::STATUS_SKIPPED_MALFORMED) {
                $skippedMalformed++;
                continue;
            }

            $result = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;

            if (!is_array($result)) {
                // Defensive — every non-malformed row should carry a result
                // by the time importing/postpass have completed.
                continue;
            }

            $pageResults[] = $result;
            $totalImages  += count($result['images'] ?? []);

            if (!($result['success'] ?? false)) {
                $hasErrors = true;
            }

            if (!empty($result['warnings'])) {
                $hasWarnings = true;
            }
        }

        if ($skippedMalformed > 0) {
            $hasWarnings = true;

            array_unshift($pageResults, [
                'success'       => true,
                'slug'          => '',
                'entryId'       => null,
                'entryFound'    => false,
                'title'         => 'Malformed export entries',
                'depth'         => 0,
                'parentSlug'    => null,
                'blocks'        => [],
                'images'        => [],
                'blockNotes'    => '',
                'seoFieldCount' => 0,
                'warnings'      => ["{$skippedMalformed} page(s) in the export were malformed (not an object) and were skipped."],
                'error'         => null,
                'contentType'   => null,
                'sectionLabel'  => null,
            ]);
        }

        if ($globalsReport !== null) {
            $totalImages += $globalsReport['imageCount'] ?? 0;

            if (!empty($globalsReport['drift'])
                || !empty($globalsReport['warnings'] ?? [])
                || !empty($globalsReport['fieldNotes'] ?? [])
            ) {
                $hasWarnings = true;
            }
        }

        if ($ackWarning !== null) {
            $hasWarnings = true;
        }

        if ($hasErrors) {
            $status = 'errors';
        } elseif ($hasWarnings) {
            $status = 'warnings';
        } else {
            $status = 'success';
        }

        $service->setImageCount($this->runId, $totalImages);

        $service->finish($this->runId, $status, [
            'pages'      => $pageResults,
            'globals'    => $globalsReport,
            'ackWarning' => $ackWarning,
        ]);
    }
}

<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\Queue as QueueHelper;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\jobs\FinaliseRunJob;
use matrixcreate\contentiqimporter\jobs\ImportPagesJob;
use matrixcreate\contentiqimporter\jobs\PostPassJob;
use matrixcreate\contentiqimporter\jobs\StageRunJob;
use matrixcreate\contentiqimporter\models\SyncRun;
use matrixcreate\contentiqimporter\services\SyncRunService;
use yii\console\ExitCode;

/**
 * Console entry points for the batched sync pipeline (§3.8).
 *
 * Usage:
 *   php craft contentiq-importer/sync/run
 *   php craft contentiq-importer/sync/run --wait
 *   php craft contentiq-importer/sync/run --file=export.json --wait
 *   php craft contentiq-importer/sync/run --unlock-all --wait
 *   php craft contentiq-importer/sync/resume 42
 *   php craft contentiq-importer/sync/prune --keep-days=30
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'run';

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Path to a ContentIQ JSON export file. Omitted means
     *   `source=api` — fetch from the configured ContentIQ instance, same as
     *   the CP Sync button. Given means `source=cli`.
     */
    public ?string $file = null;

    /**
     * @var bool Drives the job chain in-process instead of leaving it to a
     *   queue worker — see {@see self::_wait()}. Bypasses the queue: no
     *   `craft queue/listen`/`queue/run` is needed while this flag is set,
     *   but nothing here should be assumed running concurrently with a real
     *   worker for the same run (see the report for the residual
     *   orphaned-queue-row caveat this leaves behind).
     */
    public bool $wait = false;

    /**
     * @var bool Unlocks every entry with a contentiq_entry_syncs row for
     *   this run (locks everything else it doesn't touch, same as the Sync
     *   screen's "select all" — see SyncLockService::applyLockState()).
     *   Without this flag `unlockIds` is `null` — lock state is left
     *   untouched, same as an unattended cron sync should do.
     */
    public bool $unlockAll = false;

    /**
     * @var int Runs older than this many days are pruned by `prune`.
     */
    public int $keepDays = 30;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'run':
                $options[] = 'file';
                $options[] = 'wait';
                $options[] = 'unlockAll';
                break;
            case 'resume':
                $options[] = 'wait';
                break;
            case 'prune':
                $options[] = 'keepDays';
                break;
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        $aliases = parent::optionAliases();
        $aliases['f'] = 'file';

        return $aliases;
    }

    /**
     * Stages a sync run (API fetch, or `--file`) and pushes it onto the
     * batched pipeline. Without `--wait`, returns as soon as the run is
     * queued — a worker (`craft queue/listen`, or the web runner) picks it
     * up from there, same as a CP-triggered sync. With `--wait`, drives the
     * chain to completion in this process (§3.8) — see {@see self::_wait()}.
     *
     * @return int
     */
    public function actionRun(): int
    {
        App::maxPowerCaptain();

        $service = ContentIQImporter::$plugin->syncRuns;
        $source  = $this->file !== null ? 'cli' : 'api';
        $filePath = null;

        if ($this->file !== null) {
            $realFile = realpath($this->file) ?: $this->file;

            if (!is_file($realFile)) {
                $this->failure("File not found: `{$this->file}`");

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $filePath = $realFile;
        }

        // CLI runs from the project root; local-filesystem volume paths in
        // project.yaml are relative to the web root — match ImportController's
        // own chdir (AGENTS.md hard limit) before StageRunJob/ImportPagesJob/
        // FinaliseRunJob touch any asset path.
        chdir(Craft::getAlias('@webroot'));

        $unlockIds = null;

        if ($this->unlockAll) {
            $unlockIds = array_column(
                (new Query())->select(['element_id'])->from('{{%contentiq_entry_syncs}}')->all(),
                'element_id',
            );
        }

        $options = [
            'unlockIds'     => $unlockIds,
            'unlockGlobals' => false,
            'newSelections' => [],
            'file'          => $filePath,
            'dryRun'        => false,
        ];

        $run = $service->createRun($source, $options, null, $filePath !== null ? basename($filePath) : 'sync');

        $jobId = QueueHelper::push(new StageRunJob(['runId' => $run->id]), null, 0, SyncQueueConfig::ttr());
        $service->setQueueJobId($run->id, $jobId !== null ? (int)$jobId : null);

        $this->stdout("Sync run #{$run->id} queued (source={$source}).\n", Console::FG_GREEN);

        if (!$this->wait) {
            $this->stdout("Run `craft contentiq-importer/sync/resume {$run->id}` if it stalls, or watch the CP Sync screen.\n");

            return ExitCode::OK;
        }

        return $this->_wait($run->id, $service);
    }

    /**
     * Re-pushes the job for a `failed` run's recorded phase/step (the same
     * `state.failedPhase`/`state.failedStep` {@see \matrixcreate\contentiqimporter\services\SyncRunService::fail()}
     * records — see {@see \matrixcreate\contentiqimporter\controllers\CpController::actionResumeRun()}
     * for the CP equivalent of this same logic).
     *
     * @param int $runId
     * @return int
     */
    public function actionResume(int $runId): int
    {
        $service = ContentIQImporter::$plugin->syncRuns;
        $run     = $service->loadRun($runId);

        if ($run === null) {
            $this->failure("Run #{$runId} not found.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($run->phase !== SyncRun::PHASE_FAILED) {
            $this->failure("Run #{$runId} is not failed (phase={$run->phase}) — nothing to resume.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($service->hasQueuedJob($run->queueJobId)) {
            $this->failure("Run #{$runId} already has a job queued — nothing to do.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $phase = $run->state['failedPhase'] ?? null;
        $step  = $run->state['failedStep'] ?? null;

        $jobClass = match ($phase) {
            SyncRun::PHASE_STAGING    => StageRunJob::class,
            SyncRun::PHASE_IMPORTING  => ImportPagesJob::class,
            SyncRun::PHASE_POSTPASS   => PostPassJob::class,
            SyncRun::PHASE_FINALISING => FinaliseRunJob::class,
            default                   => null,
        };

        if ($jobClass === null) {
            $this->failure("Run #{$runId} has no recorded phase to resume from.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $service->advance($runId, $phase, $step);
        // See CpController::actionResumeRun()'s matching comment — without
        // this, a run resumed after sitting failed for a while looks stale
        // again on the very next status poll.
        $service->touch($runId);

        $ttr   = $phase === SyncRun::PHASE_FINALISING ? SyncQueueConfig::finaliseTtr() : SyncQueueConfig::ttr();
        $jobId = QueueHelper::push(new $jobClass(['runId' => $runId]), null, 0, $ttr);
        $service->setQueueJobId($runId, $jobId !== null ? (int)$jobId : null);

        $label = $step !== null ? "{$phase}/{$step}" : $phase;
        $this->stdout("Run #{$runId} resumed at phase '{$label}' — queued.\n", Console::FG_GREEN);

        if ($this->wait) {
            return $this->_wait($runId, $service);
        }

        return ExitCode::OK;
    }

    /**
     * Deletes terminal runs older than `--keep-days` (default 30) —
     * {@see \matrixcreate\contentiqimporter\services\SyncRunService::prune()}.
     *
     * @return int
     */
    public function actionPrune(): int
    {
        $deleted = ContentIQImporter::$plugin->syncRuns->prune($this->keepDays);

        $this->stdout("Pruned {$deleted} run(s) older than {$this->keepDays} day(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Drives a run's job chain to completion in this process, bypassing the
     * queue worker.
     *
     * Deliberately NOT `Craft::$app->getQueue()->run()` — that drains
     * whatever else is sitting in the queue table, not just this run's own
     * chain. Instead, this constructs the job class matching the run's
     * current `phase` and calls its `execute()` directly.
     *
     * One departure from a literal "always construct at batchIndex/
     * itemOffset 0" reading of the spec: `BaseBatchedJob::execute()` slices
     * by `$this->itemOffset`, mutating it in place as it works through a
     * batch, and only calls its `after()` hook (which advances the run's
     * `phase`) once `itemOffset` reaches the total item count. Reconstructing
     * a fresh job at offset 0 on every loop iteration — instead of reusing
     * the SAME instance across repeat calls within one phase — would re-slice
     * items [0, batchSize) forever and never converge for any run bigger
     * than one batch. This method reuses one job instance per phase instead,
     * which is what actually lets `itemOffset` advance to completion; see
     * this build's report for the full reasoning. `StageRunJob`/
     * `FinaliseRunJob` are unaffected either way — both are plain (non-
     * batched) jobs that fully resolve their one phase within a single
     * `execute()` call.
     *
     * Each batched `execute()` call still pushes its own "next batch" clone
     * onto the REAL queue table before returning (core `BaseBatchedJob`
     * behaviour, not something this method can opt out of) — those rows are
     * never run by this method (it always continues via its own in-process
     * instance instead) and are left behind once the run finishes. A queue
     * worker that later picks one up will find every row it touches already
     * done and skip them quickly (idempotent, if wasteful) — see the report.
     *
     * @param int $runId
     * @param SyncRunService $service
     * @return int
     */
    private function _wait(int $runId, SyncRunService $service): int
    {
        $queue = Craft::$app->getQueue();

        // Drive the chain by executing THIS run's queued job by id through
        // the queue itself. Executing job instances directly does not work:
        // BaseBatchedJob::execute() asks the queue for its executing job id
        // and reservation after every item, and both only exist inside
        // Queue::executeJob() (Craft throws a TypeError otherwise — seen on
        // the first live run). Going through the queue also consumes the
        // clones the batched jobs push for their next batch, so nothing is
        // left behind for another worker.
        $lastJobId = null;
        $sameJobLoops = 0;

        while (true) {
            $run = $service->loadRun($runId);

            if ($run === null) {
                $this->failure("Run #{$runId} vanished mid-wait.");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if ($run->isTerminal()) {
                break;
            }

            $jobId = $run->queueJobId;

            if ($jobId === null) {
                $this->failure("Run #{$runId} is in phase '{$run->phase}' but has no queued job — resume it with sync/resume {$runId}.");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            // A job that neither finishes nor advances the run after two
            // executions is stuck (reserved elsewhere, or failing without
            // recording it) — stop rather than spin.
            if ($jobId === $lastJobId && ++$sameJobLoops > 2) {
                $this->failure("Queue job #{$jobId} for run #{$runId} did not advance the run — is another worker running it?");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if ($jobId !== $lastJobId) {
                $sameJobLoops = 0;
                $lastJobId    = $jobId;
            }

            $counts = $service->counts($runId);
            $label  = $run->step !== null ? "{$run->phase}/{$run->step}" : $run->phase;
            $this->stdout("  {$label} — imported={$counts['imported']} skipped={$counts['skipped']} failed={$counts['failed']} of {$counts['total']}\n");

            if (!$queue->executeJob((string)$jobId)) {
                $this->failure("Queue job #{$jobId} for run #{$runId} was not found or could not be reserved.");
                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        $run = $service->loadRun($runId);

        if ($run === null) {
            $this->failure("Run #{$runId} vanished mid-wait.");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($run->phase === SyncRun::PHASE_FAILED) {
            $this->stderr("Sync run #{$runId} failed: {$run->error}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Sync run #{$runId} done — status {$run->status}.\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}

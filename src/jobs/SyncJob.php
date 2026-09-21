<?php

namespace matrixcreate\contentiqimporter\jobs;

use Craft;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Queue as QueueHelper;
use craft\queue\BaseJob;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\models\SyncRun;

/**
 * One-release compatibility shim for the pre-pipeline monolithic sync job.
 *
 * Craft unserialises a queued job by class name, so any `SyncJob` payload
 * still sitting in the `{{%queue}}` table at deploy time (queued by the old
 * `CpController::actionRunSync()`, before it started using
 * `SyncRunService::createRun()` + `StageRunJob`) needs this class to keep
 * existing — deleting it outright would make Craft's queue worker fatal on
 * that row instead of running it. Its body no longer runs a sync itself;
 * `execute()` converts the run row it was pushed for into a phase=`staging`
 * pipeline run and hands off to `StageRunJob`, so a payload queued before
 * deploy still runs, just on the new chain. Keep the public properties byte-
 * for-byte (Craft deserialises into them by name) so old payloads unserialise
 * cleanly. Remove this file in the following release, once no pre-deploy
 * payload can plausibly still be queued.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class SyncJob extends BaseJob
{
    /**
     * The import run ID (pre-created with status 'pending' by the
     * controller that pushed this job).
     *
     * @var int
     */
    public int $runId;

    /**
     * Entry IDs to unlock for this run (checked = will sync). `null` means
     * "leave lock state untouched" — see {@see \matrixcreate\contentiqimporter\services\SyncLockService::applyLockState()}.
     *
     * @var int[]|null
     */
    public ?array $unlockIds = null;

    /**
     * Whether the user consented to importing globals for this run.
     *
     * @var bool
     */
    public bool $unlockGlobals = false;

    /**
     * ContentIQ page ids (document.id) for "New" (never-imported) rows the
     * user unchecked on the Sync screen — carried into the converted run's
     * `options.newSelections` (§3.2's Options contract renamed this from
     * its pre-pipeline name, `excludeNewIds`, but the semantics are
     * unchanged).
     *
     * @var int[]
     */
    public array $excludeNewIds = [];

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $plugin  = ContentIQImporter::$plugin;
        $service = $plugin->syncRuns;

        $options = [
            'unlockIds'     => $this->unlockIds,
            'unlockGlobals' => $this->unlockGlobals,
            'newSelections' => $this->excludeNewIds,
            'file'          => null,
            'dryRun'        => false,
        ];

        $run = isset($this->runId) ? $service->loadRun($this->runId) : null;

        if ($run !== null && $run->status === 'pending') {
            // The row this job was pushed for, exactly as the pre-pipeline
            // controller left it (status='pending', no phase/options of its
            // own). The m260921_100000 migration's own backfill already
            // flipped `phase` to `failed` for it (it has no
            // `contentiq_sync_pages` rows to resume from under the OLD
            // model's assumptions) — that backfill couldn't know this
            // payload would still run. Converting is keyed on `status`
            // (never touched by that backfill) rather than `phase` for
            // exactly this reason: it's the one field that still tells the
            // two cases apart.
            Craft::$app->getDb()->createCommand()->update('{{%contentiq_import_runs}}', [
                'source'      => 'api',
                'phase'       => SyncRun::PHASE_STAGING,
                'step'        => null,
                'options'     => Json::encode($options),
                'error'       => null,
                'heartbeat'   => Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ], ['id' => $this->runId])->execute();

            $runId = $this->runId;
        } elseif ($run !== null && !$run->isTerminal()) {
            // Already converted (or advanced) by an earlier partial
            // execution of this same payload — e.g. Craft redelivering the
            // message after a lost reservation. Nothing left to convert,
            // just hand off again.
            $runId = $this->runId;
        } elseif ($run === null) {
            // No run row survived at all (pruned, or the id was never
            // valid) — start a fresh one from this job's own properties
            // rather than silently doing nothing with a real payload.
            $runId = $service->createRun('api', $options, null, 'sync')->id;
        } else {
            // A real, unrelated terminal run — finished, or failed for its
            // own reasons, before this shim ever got a chance to run.
            // Nothing to resurrect.
            Craft::info(
                'ContentIQImporter: SyncJob shim skipped for run ' . $this->runId
                    . ' — already terminal (phase=' . $run->phase . ') and not a legacy pending row.',
                __METHOD__,
            );
            return;
        }

        $jobId = QueueHelper::push(new StageRunJob(['runId' => $runId]), null, 0, SyncQueueConfig::ttr());
        $service->setQueueJobId($runId, $jobId !== null ? (int)$jobId : null);
    }
}

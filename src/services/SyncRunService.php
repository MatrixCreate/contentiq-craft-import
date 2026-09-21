<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use matrixcreate\contentiqimporter\models\SyncRun;
use RuntimeException;
use yii\base\Component;

/**
 * Database access for the batched sync pipeline: contentiq_import_runs
 * (one row per run) and contentiq_sync_pages (one row per page).
 *
 * Every write here is deliberately narrow — one column group per method —
 * so the job chain (§3.2 of CRAFT-IMPORT-QUEUE-SPEC.md, not built in this
 * slice) can checkpoint after each unit of work instead of writing a run row
 * once at the end. See SyncRun for the phase/step constants and SyncPlanner
 * for the pure planning step whose output {@see self::stageRun()} persists.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncRunService extends Component
{
    // Constants
    // =========================================================================

    /** Row not yet processed. */
    public const STATUS_PENDING = 'pending';

    /** importPage() ran and wrote/matched a Craft entry. */
    public const STATUS_IMPORTED = 'imported';

    /** Skipped — the existing entry's contentiq_entry_syncs row is locked. */
    public const STATUS_SKIPPED_LOCKED = 'skipped_locked';

    /** Skipped — a never-imported ("New") row the user deselected on the Sync screen. */
    public const STATUS_SKIPPED_DESELECTED = 'skipped_deselected';

    /** Skipped — the export entry itself was malformed (see SyncPlanner::plan()). */
    public const STATUS_SKIPPED_MALFORMED = 'skipped_malformed';

    /** importPage() (or positioning) threw — see the row's `result.warnings`. */
    public const STATUS_FAILED = 'failed';

    // Public Methods
    // =========================================================================

    /**
     * Inserts a new run row in phase `staging`.
     *
     * @param string $source `api`|`upload`|`cli`|`widget`.
     * @param array $options Captured at request time — see SyncRun::$options.
     * @param int|null $importedBy
     * @param string $filename
     * @return SyncRun
     */
    public function createRun(string $source, array $options, ?int $importedBy, string $filename): SyncRun
    {
        $db  = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->insert('{{%contentiq_import_runs}}', [
            'importedBy'  => $importedBy,
            'filename'    => $filename,
            // Every createRun()-produced run (source api/upload/cli alike)
            // is finalised by FinaliseRunJob's `report` step into the same
            // {pages, globals, ackWarning} shape (§3.2) — the legacy
            // `type` column is what CpController::actionResult()/
            // history.twig still key off to route to the sync report
            // screen instead of the old flat-array `result.twig` (which
            // predates this shape and can't render it — see that
            // controller's own docblock). Always 'sync' here, regardless
            // of $source, keeps that routing correct for uploads/CLI runs
            // too; the `source` column (shown alongside it in history.twig)
            // is what actually distinguishes them for display.
            'type'        => 'sync',
            'source'      => $source,
            'status'      => 'pending',
            'phase'       => SyncRun::PHASE_STAGING,
            'options'     => Json::encode($options),
            'heartbeat'   => $now,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid'         => StringHelper::UUID(),
        ])->execute();

        $run = $this->loadRun((int)$db->getLastInsertID());

        if ($run === null) {
            // Cannot happen outside a concurrent hard-delete of the row we
            // just inserted — guards the non-nullable return type instead of
            // silently handing back null.
            throw new RuntimeException('Failed to load sync run immediately after creating it.');
        }

        return $run;
    }

    /**
     * Loads a run by id.
     *
     * @param int $id
     * @return SyncRun|null
     */
    public function loadRun(int $id): ?SyncRun
    {
        $row = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $id])
            ->one();

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /**
     * Persists a SyncPlanner::plan() result against a run: the `globals`
     * blob, the `state` seeds, `pageCount`, and one contentiq_sync_pages row
     * per planned page (inserted in chunks of 100).
     *
     * @param int $runId
     * @param array $plan The return value of SyncPlanner::plan().
     * @return void
     */
    public function stageRun(int $runId, array $plan): void
    {
        $db  = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        $db->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'globals'     => $plan['globals'] !== null ? Json::encode($plan['globals']) : null,
                'state'       => Json::encode($plan['state'] ?? []),
                'pageCount'   => count($plan['pages']),
                'dateUpdated' => $now,
            ],
            ['id' => $runId],
        )->execute();

        $columns = [
            'run_id', 'position', 'contentiq_page_id', 'slug', 'parent_slug', 'depth',
            'is_homepage', 'content_type', 'duplicate_slug', 'payload', 'status',
            'dateCreated', 'dateUpdated', 'uid',
        ];

        foreach (array_chunk($plan['pages'], 100) as $chunk) {
            $rows = [];

            foreach ($chunk as $page) {
                $rows[] = [
                    $runId,
                    $page['position'],
                    $page['contentiq_page_id'] ?? null,
                    $page['slug'] ?? '',
                    $page['parent_slug'] ?? null,
                    $page['depth'] ?? 0,
                    (bool)($page['is_homepage'] ?? false),
                    $page['content_type'] ?? null,
                    (bool)($page['duplicate_slug'] ?? false),
                    $page['payload'] !== null ? Json::encode($page['payload']) : null,
                    $page['status'] ?? self::STATUS_PENDING,
                    $now,
                    $now,
                    StringHelper::UUID(),
                ];
            }

            Db::batchInsert('{{%contentiq_sync_pages}}', $columns, $rows, $db);
        }
    }

    /**
     * Advances a run to a new phase (and, inside `finalising`, a new step).
     *
     * @param int $runId
     * @param string $phase One of SyncRun::PHASE_*.
     * @param string|null $step One of SyncRun::STEP_*, or null outside `finalising`.
     * @return void
     */
    public function advance(int $runId, string $phase, ?string $step = null): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'error'        => null,
            'dateFinished' => null,
            'phase'       => $phase,
                'step'        => $step,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * Touches a run's heartbeat — the staleness fallback's only signal that
     * a job is still alive.
     *
     * @param int $runId
     * @return void
     */
    public function touch(int $runId): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            ['heartbeat' => Db::prepareDateForDb(new \DateTime())],
            ['id' => $runId],
        )->execute();
    }

    /**
     * Overwrites a run's `state` blob wholesale — callers read the current
     * state via {@see self::getState()} first if they need to merge rather
     * than replace.
     *
     * @param int $runId
     * @param array $state
     * @return void
     */
    public function setState(int $runId, array $state): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'state'       => Json::encode($state),
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * Reads a run's current `state` blob.
     *
     * @param int $runId
     * @return array Empty array if the run has no state (or doesn't exist).
     */
    public function getState(int $runId): array
    {
        $value = (new Query())
            ->select(['state'])
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->scalar();

        if (!$value) {
            return [];
        }

        $decoded = Json::decodeIfJson($value);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Marks a run failed — sets phase `failed`, records the error, and
     * stamps `dateFinished`. Does NOT relock globals; that's the caller's
     * responsibility (a job's own catch block, or the status poller's
     * staleness fallback), since only the caller knows whether globals were
     * ever unlocked for this run in the first place.
     *
     * Also records the phase/step the run was in immediately before this
     * call as `state.failedPhase`/`state.failedStep` — the Resume feature's
     * only way to know which job to re-push, since `phase`/`step` on the row
     * itself get overwritten to `failed`/null right below. Read BEFORE the
     * overwrite, not from the (possibly stale) SyncRun a caller already has
     * in hand, so a fail() called well after a phase advanced elsewhere
     * still records the truth. Left unset when the row was already terminal
     * (nothing to resume) — this also covers SyncJob's one-release shim
     * (§3.9): a legacy `status='pending'` row the pipeline migration
     * backfilled to `phase='failed'` before any queued SyncJob got a chance
     * to run has no `failedPhase` yet, and never will from this call alone.
     *
     * @param int $runId
     * @param string $error
     * @return void
     */
    public function fail(int $runId, string $error): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        $current = (new Query())
            ->select(['status'       => 'errors',
            'phase', 'step', 'state'])
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        $state = [];

        if ($current !== null && !empty($current['state'])) {
            $decoded = Json::decodeIfJson($current['state']);
            $state   = is_array($decoded) ? $decoded : [];
        }

        if ($current !== null && !in_array($current['phase'], [SyncRun::PHASE_DONE, SyncRun::PHASE_FAILED], true)) {
            $state['failedPhase'] = $current['phase'];
            $state['failedStep']  = $current['step'];
        }

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'phase'        => SyncRun::PHASE_FAILED,
                'state'        => Json::encode($state),
                'error'        => $error,
                'dateFinished' => $now,
                'dateUpdated'  => $now,
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * Marks a run done — sets phase `done`, the legacy report `status`
     * (`success`|`warnings`|`errors`), the assembled legacy `result` JSON,
     * and stamps `dateFinished`.
     *
     * @param int $runId
     * @param string $status
     * @param array $result
     * @return void
     */
    public function finish(int $runId, string $status, array $result): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'error'       => null,
            'phase'        => SyncRun::PHASE_DONE,
                'status'       => $status,
                'result'       => Json::encode($result),
                'dateFinished' => $now,
                'dateUpdated'  => $now,
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * Records the Craft queue row id of the currently queued job for a run
     * (or clears it with null once no job is queued for it).
     *
     * @param int $runId
     * @param int|null $queueJobId
     * @return void
     */
    public function setQueueJobId(int $runId, ?int $queueJobId): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'queueJobId'  => $queueJobId,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * A Batchable-ready query over a run's pages, ordered by export
     * position — the shape craft\db\QueryBatcher wraps for
     * ImportPagesJob/PostPassJob.
     *
     * Defaults to NO status filter (every row of the run). This is
     * deliberate, not an oversight: QueryBatcher slices by a fixed
     * `(offset, limit)` pair, so the set it's wrapping must not shrink while
     * a job is paging through it — a query restricted to e.g. `pending`
     * would shrink by exactly `batchSize` after every batch (each batch
     * moves its rows out of `pending`), causing the NEXT batch's offset to
     * skip over rows it never actually saw. ImportPagesJob relies on the
     * no-filter default and checks each row's status itself in
     * `processItem()`. PostPassJob passes `[self::STATUS_IMPORTED]`
     * explicitly instead — that set does NOT shrink during the postpass
     * phase (postpass_done is a separate flag, not a status transition), so
     * restricting to it there is safe and cheaper.
     *
     * @param int $runId
     * @param string[] $statuses Restrict to these statuses, or `[]` (the
     *   default) for every row regardless of status.
     * @return Query
     */
    public function pageBatchQuery(int $runId, array $statuses = []): Query
    {
        $query = (new Query())
            ->select(['id', 'position', 'status'])
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId])
            ->orderBy(['position' => SORT_ASC]);

        if (!empty($statuses)) {
            $query->andWhere(['status' => $statuses]);
        }

        return $query;
    }

    /**
     * Loads one contentiq_sync_pages row, with `payload`/`result` decoded.
     *
     * @param int $pageId
     * @return array|null
     */
    public function loadPage(int $pageId): ?array
    {
        $row = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['id' => $pageId])
            ->one();

        if ($row === null) {
            return null;
        }

        $row['payload'] = $row['payload'] !== null ? Json::decodeIfJson($row['payload']) : null;
        $row['result']  = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;

        return $row;
    }

    /**
     * Records the outcome of processing one page: status, the Craft entry it
     * was written to/matched (if any), and its full importPage() result row
     * (never a hand-built subset — see AGENTS.md's runPostPasses() rule,
     * which reads this same `result` back out of writtenPages()).
     *
     * Nulls `payload` for every non-pending status — the row is done with it
     * (§3.1's retention model: a retained run costs only its result JSON).
     *
     * @param int $pageId
     * @param string $status One of self::STATUS_*.
     * @param int|null $entryId
     * @param array|null $result
     * @return void
     */
    public function markPage(int $pageId, string $status, ?int $entryId, ?array $result): void
    {
        $values = [
            'status'      => $status,
            'entry_id'    => $entryId,
            'result'      => $result !== null ? Json::encode($result) : null,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ];

        if ($status !== self::STATUS_PENDING) {
            $values['payload'] = null;
        }

        Craft::$app->getDb()->createCommand()
            ->update('{{%contentiq_sync_pages}}', $values, ['id' => $pageId])
            ->execute();
    }

    /**
     * Bulk-stamps `postpass_done` for a batch of rows.
     *
     * @param int[] $pageIds
     * @return void
     */
    public function markPostPassDone(array $pageIds): void
    {
        if (empty($pageIds)) {
            return;
        }

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_sync_pages}}',
            [
                'postpass_done' => true,
                'dateUpdated'   => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $pageIds],
        )->execute();
    }

    /**
     * Bulk-stamps `acked_at` for a batch of rows — makes a resumed ack step
     * skip rows a prior chunk already acked successfully.
     *
     * @param int[] $pageIds
     * @return void
     */
    public function stampAcked(array $pageIds): void
    {
        if (empty($pageIds)) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_sync_pages}}',
            ['acked_at' => $now, 'dateUpdated' => $now],
            ['id' => $pageIds],
        )->execute();
    }

    /**
     * Bulk-stamps `locked_at` for a batch of rows — makes a resumed auto-lock
     * step skip rows a prior chunk already locked.
     *
     * @param int[] $pageIds
     * @return void
     */
    public function stampLocked(array $pageIds): void
    {
        if (empty($pageIds)) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_sync_pages}}',
            ['locked_at' => $now, 'dateUpdated' => $now],
            ['id' => $pageIds],
        )->execute();
    }

    /**
     * Row-status counts for a run's progress display.
     *
     * `total` and the per-status keys are computed with one grouped query;
     * `postpassDone`/`acked`/`locked` are separate counts because they're
     * booleans/timestamps orthogonal to `status`, not values of it. Image
     * counts are omitted — summing them means decoding every row's `result`,
     * which isn't cheap at run scale; see SyncRun::$imageCount for the
     * cumulative total maintained elsewhere instead.
     *
     * @param int $runId
     * @return array{total: int, pending: int, imported: int, skipped: int, failed: int, postpassDone: int, acked: int, locked: int}
     */
    public function counts(int $runId): array
    {
        $rows = (new Query())
            ->select(['status', 'COUNT(*) AS total'])
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId])
            ->groupBy(['status'])
            ->all();

        $byStatus = [];

        foreach ($rows as $row) {
            $byStatus[$row['status']] = (int)$row['total'];
        }

        $skipped = 0;

        foreach ($byStatus as $status => $count) {
            if (str_starts_with($status, 'skipped_')) {
                $skipped += $count;
            }
        }

        return [
            'total'        => array_sum($byStatus),
            'pending'      => $byStatus[self::STATUS_PENDING] ?? 0,
            'imported'     => $byStatus[self::STATUS_IMPORTED] ?? 0,
            'skipped'      => $skipped,
            'failed'       => $byStatus[self::STATUS_FAILED] ?? 0,
            'postpassDone' => (int)(new Query())
                ->from('{{%contentiq_sync_pages}}')
                ->where(['run_id' => $runId, 'postpass_done' => true])
                ->count(),
            'acked' => (int)(new Query())
                ->from('{{%contentiq_sync_pages}}')
                ->where(['run_id' => $runId])
                ->andWhere(['not', ['acked_at' => null]])
                ->count(),
            'locked' => (int)(new Query())
                ->from('{{%contentiq_sync_pages}}')
                ->where(['run_id' => $runId])
                ->andWhere(['not', ['locked_at' => null]])
                ->count(),
        ];
    }

    /**
     * A slug → entry_id map from this run's imported rows — the map
     * ImportPagesJob/PostPassJob rebuild per batch (§3.4 of
     * CRAFT-IMPORT-QUEUE-SPEC.md) instead of carrying it in memory across
     * job boundaries.
     *
     * @param int $runId
     * @return array<string, int>
     */
    public function slugToEntryIdMap(int $runId): array
    {
        $rows = (new Query())
            ->select(['slug', 'entry_id'])
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId, 'status' => self::STATUS_IMPORTED])
            ->andWhere(['not', ['entry_id' => null]])
            ->all();

        $map = [];

        foreach ($rows as $row) {
            $map[$row['slug']] = (int)$row['entry_id'];
        }

        return $map;
    }

    /**
     * One page of a run's contentiq_sync_pages rows, in export position
     * order, with `result` decoded — what `actionSyncResult()` reads
     * instead of the assembled legacy `result` JSON blob, so the report
     * renders (and paginates) the same way whether the run is still going
     * or already `done` (a `done` pipeline run's `result` is itself just
     * these same rows, assembled once by FinaliseRunJob's `report` step —
     * reading the rows directly is simpler than re-decoding that blob just
     * to slice a page out of it, and is the only option at all while the
     * run hasn't reached `report` yet).
     *
     * `skipped_malformed` rows are excluded — they carry no individual
     * `result` row (see FinaliseRunJob's `_runReportStep()`); the caller is
     * expected to surface their count as one synthetic combined row itself,
     * same as that method does.
     *
     * @param int $runId
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function pageRows(int $runId, int $offset, int $limit): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId])
            ->andWhere(['!=', 'status', self::STATUS_SKIPPED_MALFORMED])
            ->orderBy(['position' => SORT_ASC])
            ->offset($offset)
            ->limit($limit)
            ->all();

        foreach ($rows as &$row) {
            $row['result'] = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;
        }

        unset($row);

        return $rows;
    }

    /**
     * Row count {@see self::pageRows()} paginates over — same
     * `skipped_malformed` exclusion, so the two agree on how many pages of
     * results exist.
     *
     * @param int $runId
     * @return int
     */
    public function pageRowCount(int $runId): int
    {
        return (int)(new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId])
            ->andWhere(['!=', 'status', self::STATUS_SKIPPED_MALFORMED])
            ->count();
    }

    /**
     * Count of `skipped_malformed` rows for a run — the report's single
     * synthetic combined warning row (see FinaliseRunJob's
     * `_runReportStep()`) needs this count without loading every malformed
     * row's (nonexistent) `result`.
     *
     * @param int $runId
     * @return int
     */
    public function skippedMalformedCount(int $runId): int
    {
        return (int)(new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId, 'status' => self::STATUS_SKIPPED_MALFORMED])
            ->count();
    }

    /**
     * Whether a run has ANY contentiq_sync_pages rows — the concrete signal
     * that it went through the batched pipeline (StageRunJob is the only
     * writer of that table) rather than being a pre-pipeline/legacy run
     * (widget syncs, and any row that predates the m260921_100000 migration).
     *
     * @param int $runId
     * @return bool
     */
    public function hasSyncPages(int $runId): bool
    {
        return (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId])
            ->exists();
    }

    /**
     * Whether a queued/reserved (non-failed) row still exists in Craft's own
     * `{{%queue}}` table for the given queue row id — the concrete signal
     * that a run's currently-pushed job hasn't finished or died yet. Shared
     * by the staleness check (`CpController::actionSyncStatus()`) and the
     * resume guards (`CpController::actionResumeRun()`,
     * `SyncController::actionResume()`) — see CRAFT-IMPORT-QUEUE-SPEC.md
     * §3.6. Only the default DB-backed queue driver is cheaply queryable
     * this way; any other driver, or any query failure, is treated as
     * "can't tell" and falls through to `false` — this is never a hard
     * requirement, only a booster (same reasoning as the pre-pipeline
     * `_hasActiveSyncJob()` this replaces).
     *
     * @param int|null $queueJobId
     * @return bool
     */
    public function hasQueuedJob(?int $queueJobId): bool
    {
        if ($queueJobId === null || !Craft::$app->getQueue() instanceof \craft\queue\Queue) {
            return false;
        }

        try {
            return (new Query())
                ->from('{{%queue}}')
                ->where(['id' => $queueJobId, 'fail' => false])
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Rows genuinely written this run — status `imported` with a real Craft
     * entry — in export order, with `result` decoded. This is the "genuinely
     * written" set the ack contract and the post-passes both key off (see
     * AGENTS.md's hard limits on `runPostPasses()`/`ackPages()`).
     *
     * @param int $runId
     * @return array
     */
    public function writtenPages(int $runId): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId, 'status' => self::STATUS_IMPORTED])
            ->andWhere(['not', ['entry_id' => null]])
            ->orderBy(['position' => SORT_ASC])
            ->all();

        foreach ($rows as &$row) {
            $row['result'] = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;
        }

        unset($row);

        return $rows;
    }

    /**
     * Rows eligible for FinaliseRunJob's `ack` step: `status=imported`, a
     * real `entry_id` and `contentiq_page_id`, never acked before
     * (`acked_at IS NULL`), and — decoded from `result` — the same
     * "genuinely written" flags `writtenPages()`/`ImportService::
     * _writtenPages()` check (`success` true, none of `skippedLocked`/
     * `skippedDeselected`/`skipped`). `status=imported` already excludes a
     * locked/deselected page (they get their own distinct statuses), but
     * `success` and the generic `skipped` flag (e.g. an unmapped
     * content_type) are NOT guaranteed by status alone — `importPage()` can
     * return either without throwing, which is exactly why ImportPagesJob
     * still marks the row `imported` — so this method re-checks them,
     * exactly mirroring SyncJob's own ack-candidate predicate
     * (SyncJob.php ~477-487).
     *
     * Rows are returned undecoded (raw `result` JSON text) — callers here
     * only need `id`/`contentiq_page_id`, not the full result.
     *
     * @param int $runId
     * @return array
     */
    public function pagesToAck(int $runId): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId, 'status' => self::STATUS_IMPORTED])
            ->andWhere(['not', ['entry_id' => null]])
            ->andWhere(['not', ['contentiq_page_id' => null]])
            ->andWhere(['acked_at' => null])
            ->orderBy(['position' => SORT_ASC])
            ->all();

        return array_values(array_filter($rows, function (array $row): bool {
            $result = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;

            return is_array($result)
                && ($result['success'] ?? false)
                && empty($result['skippedLocked'])
                && empty($result['skippedDeselected'])
                && empty($result['skipped']);
        }));
    }

    /**
     * Rows eligible for FinaliseRunJob's `lock` step: `status=imported`, a
     * real `entry_id`, never locked before this run (`locked_at IS NULL`),
     * and — decoded from `result` — `success` true and not `skippedLocked`
     * (SyncJob's own auto-lock loop predicate, SyncJob.php ~562-573; the
     * `skippedLocked` check is a belt-and-braces leftover from when locked
     * and imported rows shared one array — a locked page gets its own
     * `STATUS_SKIPPED_LOCKED` row here, so `status=imported` already
     * excludes it).
     *
     * Unlike {@see pagesToAck()}, `result` IS decoded on the returned
     * rows — the lock step reads `blockNotes`/`sortOrder`/`contentiqPageId`
     * off it to persist into `contentiq_entry_syncs`.
     *
     * @param int $runId
     * @return array
     */
    public function pagesToLock(int $runId): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_sync_pages}}')
            ->where(['run_id' => $runId, 'status' => self::STATUS_IMPORTED])
            ->andWhere(['not', ['entry_id' => null]])
            ->andWhere(['locked_at' => null])
            ->orderBy(['position' => SORT_ASC])
            ->all();

        $eligible = [];

        foreach ($rows as $row) {
            $result = $row['result'] !== null ? Json::decodeIfJson($row['result']) : null;

            if (!is_array($result) || !($result['success'] ?? false) || !empty($result['skippedLocked'])) {
                continue;
            }

            $row['result'] = $result;
            $eligible[]    = $row;
        }

        return $eligible;
    }

    /**
     * Sets a run's total image count. Kept separate from {@see finish()} —
     * FinaliseRunJob's `report` step only knows the final image total
     * (pages + globals) once the `globals` step has already run and
     * returned its own `imageCount`, and `finish()`'s own signature is
     * already fixed by SyncJob's legacy report shape — rather than growing
     * that signature for one column, this is a second, narrow write, same
     * pattern as {@see setQueueJobId()}.
     *
     * @param int $runId
     * @param int $count
     * @return void
     */
    public function setImageCount(int $runId, int $count): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            [
                'imageCount'  => $count,
                'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            ],
            ['id' => $runId],
        )->execute();
    }

    /**
     * The newest non-terminal run, if any — the "one run at a time" guard's
     * data source (§3.6).
     *
     * @return SyncRun|null
     */
    /**
     * The oldest non-terminal run OTHER than the given one, or null. Used by
     * StageRunJob's "older run wins" concurrency guard (spec §3.6): a run
     * yields only to runs created before it, so two runs can never both
     * yield to each other.
     *
     * @param int $excludeRunId
     * @return SyncRun|null
     */
    public function olderActiveRun(int $excludeRunId): ?SyncRun
    {
        $row = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['not in', 'phase', [SyncRun::PHASE_DONE, SyncRun::PHASE_FAILED]])
            ->andWhere(['<', 'id', $excludeRunId])
            ->orderBy(['id' => SORT_ASC])
            ->one();

        return $row === null ? null : SyncRun::fromRow($row);
    }

    public function hasActiveRun(): ?SyncRun
    {
        $row = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['not in', 'phase', [SyncRun::PHASE_DONE, SyncRun::PHASE_FAILED]])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        return $row === null ? null : SyncRun::fromRow($row);
    }

    /**
     * Deletes terminal runs older than `$keepDays` — contentiq_sync_pages
     * rows cascade via the FK. Only ever touches `done`/`failed` runs, never
     * an active one, regardless of how old its `dateCreated` is.
     *
     * @param int $keepDays
     * @return int Number of runs deleted.
     */
    public function prune(int $keepDays): int
    {
        $cutoff = Db::prepareDateForDb((new \DateTime())->modify("-{$keepDays} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%contentiq_import_runs}}', [
                'and',
                ['in', 'phase', [SyncRun::PHASE_DONE, SyncRun::PHASE_FAILED]],
                ['<', 'dateCreated', $cutoff],
            ])
            ->execute();
    }
}

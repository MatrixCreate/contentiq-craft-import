<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use yii\base\Component;

/**
 * Lock/consent state the batched sync pipeline applies once at staging and
 * relocks on every terminal path — per-entry sync locks
 * (`contentiq_entry_syncs.locked`) and the single globals-consent row
 * (`contentiq_globals_sync`).
 *
 * Ported verbatim from SyncJob's private lock helpers
 * (`_applyLockState()`/`_setGlobalsLock()`/`_globalsLocked()`/
 * `_relockGlobals()`, SyncJob.php ~657-787) into a shared service so every
 * job in the chain — and SyncJob's own one-release shim — has one
 * implementation to call, instead of four private copies. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.6 (review gate 2): globals must be relocked
 * on every terminal path, including a worker that dies before any failure
 * handler runs.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncLockService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Applies the lock/unlock selection from the Sync screen — locks every
     * synced entry, then unlocks only the given IDs. `null` is a no-op (the
     * "leave lock state untouched" case — see `SyncRun::$options`).
     *
     * Applied at job execution (StageRunJob), never at HTTP request time, so
     * a queued-but-never-run job leaks no unlock against a sync that didn't
     * happen.
     *
     * Unlock is an upsert, not an UPDATE-only: an entry that has never been
     * through a successful sync — the Craft homepage Single is the standing
     * example, since it pre-exists so `findExistingEntry()` matches it, but
     * its `contentiq_entry_syncs` row is only ever created by the
     * post-import auto-lock step (FinaliseRunJob's `lock` step) — has no row
     * for an UPDATE to match. The unlock would then silently no-op, and the
     * per-row lock check further down the pipeline (missing row => locked)
     * would skip the entry anyway, defeating the user's explicit unlock.
     * Rows that already exist are updated in place; missing ones are
     * inserted with `locked=false` and everything else null — the auto-lock
     * step fills in `synced_at`/`notes`/`contentiq_page_id` once the entry
     * actually imports.
     *
     * @param int[]|null $unlockIds
     * @return void
     */
    public function applyLockState(?array $unlockIds): void
    {
        if ($unlockIds === null) {
            return;
        }

        $db = Craft::$app->getDb();

        // Lock everything first, then unlock only the selected entries. An
        // empty $unlockIds (the user selected none) still runs this block —
        // it locks every synced entry and simply has nothing to unlock, so
        // "select none" actually means "sync nothing" instead of leaving
        // previously-unlocked rows unlocked.
        $db->createCommand()
            ->update('{{%contentiq_entry_syncs}}', ['locked' => true])
            ->execute();

        if (empty($unlockIds)) {
            return;
        }

        // $unlockIds arrives from a POST body, captured into `run.options`
        // at request time — cast to positive ints and drop junk before it
        // ever reaches a query, then filter to ids that are real Craft
        // entries so malformed data can't create an orphan sync row for an
        // element that doesn't exist.
        $candidateIds = array_values(array_unique(array_filter(
            array_map('intval', $unlockIds),
            static fn (int $id): bool => $id > 0,
        )));

        if (empty($candidateIds)) {
            return;
        }

        $validIds = Entry::find()->id($candidateIds)->status(null)->ids();

        if (empty($validIds)) {
            return;
        }

        foreach ($validIds as $elementId) {
            $exists = (new Query())
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $elementId])
                ->exists();

            if ($exists) {
                $db->createCommand()
                    ->update('{{%contentiq_entry_syncs}}', ['locked' => false], ['element_id' => $elementId])
                    ->execute();
            } else {
                $db->createCommand()
                    ->insert('{{%contentiq_entry_syncs}}', ['element_id' => $elementId, 'locked' => false])
                    ->execute();
            }
        }
    }

    /**
     * Sets the single globals-sync lock row, creating it if absent.
     *
     * @param bool $locked
     * @return void
     */
    public function setGlobalsLock(bool $locked): void
    {
        $db     = Craft::$app->getDb();
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => $locked])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert('{{%contentiq_globals_sync}}', ['locked' => $locked])
            ->execute();
    }

    /**
     * Whether the single globals-sync row is locked. A missing row is
     * treated as locked (the safe default the globals model documents).
     *
     * @return bool
     */
    public function globalsLocked(): bool
    {
        $row = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();

        // craft\db\Query::one() returns null when no row matches. A missing
        // row means globals have never been consented to — treat as locked.
        if ($row === null) {
            return true;
        }

        return (bool)$row['locked'];
    }

    /**
     * Relocks the globals-sync row — the sole opt-out from "consent is
     * per-run only" (see docs/globals.md).
     *
     * Two call shapes, mirroring exactly what SyncJob did on success vs
     * failure (SyncJob.php's `_relockGlobals()` vs its `_failRun()`'s inline
     * relock):
     *
     *   - **Success** (`$note === null` — FinaliseRunJob's `globals` step,
     *     right after a consented import): upserts the row (creates it if
     *     this is somehow the very first globals sync) and stamps
     *     `synced_at`. Exactly `_relockGlobals()`.
     *   - **Failure** (`$note` given — every job's catch block, and the
     *     concurrency-guard failure in StageRunJob): only touches an
     *     EXISTING row — if globals were never unlocked for this run there
     *     is nothing to relock and no row should be conjured into existence
     *     — and never stamps `synced_at`, since nothing was successfully
     *     synced. This mirrors `_failRun()`'s inline relock exactly, with
     *     one small, deliberate addition: the failure message is recorded in
     *     the row's `notes` column (present in the schema, never written by
     *     any existing code path) so a failed run leaves a breadcrumb
     *     instead of a bare `locked=true` with no explanation.
     *
     * @param string|null $note Failure explanation, or null for a
     *   success-path relock.
     * @return void
     */
    public function relockGlobals(?string $note = null): void
    {
        $db     = Craft::$app->getDb();
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($note !== null) {
            if (!$exists) {
                return;
            }

            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => true, 'notes' => $note])
                ->execute();
            return;
        }

        $now = Db::prepareDateForDb(new \DateTime());

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
}

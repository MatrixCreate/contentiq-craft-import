<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the batched sync pipeline's data model.
 *
 * `contentiq_import_runs` gains the columns a resumable job chain needs to
 * checkpoint across job boundaries instead of holding everything in one PHP
 * process — `phase` (staging|importing|postpass|finalising|done|failed)
 * alongside the existing `status` (success|warnings|errors, still computed
 * at finalise for the legacy report templates — kept, not replaced),
 * `step` (the `finalising` sub-step a resume picks up from),
 * `options`/`globals`/`state` (JSON blobs), `error`/`heartbeat`/
 * `queueJobId` (the staleness fallback), and `dateFinished`. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §3.1/§3.4.
 *
 * `contentiq_sync_pages` is new — one row per page in a run, the checkpoint
 * a resumed run reads instead of re-decoding the whole export. See
 * SyncPlanner and SyncRunService.
 *
 * Backfill: an existing run predates the pipeline and is already terminal,
 * so it gets `phase='done'` for free from the column default — except a
 * `status='pending'` row, which was genuinely mid-flight under the old
 * single-job model and can never be picked up by the new chain (it has no
 * `contentiq_sync_pages` rows to resume from). That one is marked
 * `phase='failed'` with an explanatory error instead of being left for the
 * status poller, which now reads `phase`, not `status`.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class m260921_100000_add_sync_pipeline extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_addRunColumns();
        $this->_createSyncPagesTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_sync_pages}}');

        $columns = ['source', 'phase', 'step', 'options', 'globals', 'state', 'error', 'heartbeat', 'queueJobId', 'dateFinished'];

        foreach ($columns as $column) {
            if ($this->db->columnExists('{{%contentiq_import_runs}}', $column)) {
                $this->dropColumn('{{%contentiq_import_runs}}', $column);
            }
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Adds the run-tracking columns to contentiq_import_runs and backfills
     * existing rows. Guarded on `phase` alone (added last of the pair that
     * matters — see below) so a re-run is a no-op.
     *
     * @return void
     */
    private function _addRunColumns(): void
    {
        if ($this->db->columnExists('{{%contentiq_import_runs}}', 'phase')) {
            return;
        }

        $this->addColumn('{{%contentiq_import_runs}}', 'source', $this->string(16)->notNull()->defaultValue('api')->after('type'));
        $this->addColumn('{{%contentiq_import_runs}}', 'phase', $this->string(16)->notNull()->defaultValue('done')->after('status'));
        $this->addColumn('{{%contentiq_import_runs}}', 'step', $this->string(16)->null()->after('phase'));
        $this->addColumn('{{%contentiq_import_runs}}', 'options', $this->text()->null()->after('step'));
        $this->addColumn('{{%contentiq_import_runs}}', 'globals', $this->mediumText()->null()->after('options'));
        $this->addColumn('{{%contentiq_import_runs}}', 'state', $this->text()->null()->after('globals'));
        $this->addColumn('{{%contentiq_import_runs}}', 'error', $this->text()->null()->after('result'));
        $this->addColumn('{{%contentiq_import_runs}}', 'heartbeat', $this->dateTime()->null()->after('error'));
        $this->addColumn('{{%contentiq_import_runs}}', 'queueJobId', $this->integer()->null()->after('heartbeat'));
        $this->addColumn('{{%contentiq_import_runs}}', 'dateFinished', $this->dateTime()->null()->after('queueJobId'));

        $this->createIndex(null, '{{%contentiq_import_runs}}', ['phase']);

        // A row still `status='pending'` at migration time was mid-flight
        // under the old single-job model — the column default above already
        // gave it `phase='done'`, which is wrong (it never finished); flip it
        // to `failed` with an explanation instead of leaving it looking
        // complete.
        $this->update(
            '{{%contentiq_import_runs}}',
            [
                'phase'  => 'failed',
                'status' => 'errors',
                'error'  => 'Superseded by the batched sync pipeline',
            ],
            ['status' => 'pending'],
        );
    }

    /**
     * Creates contentiq_sync_pages — one row per page in a run.
     *
     * @return void
     */
    private function _createSyncPagesTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_sync_pages}}')) {
            return;
        }

        $this->createTable('{{%contentiq_sync_pages}}', [
            'id'                => $this->primaryKey(),
            'run_id'            => $this->integer()->notNull(),
            'position'          => $this->integer()->notNull(),
            'contentiq_page_id' => $this->integer()->null(),
            'slug'              => $this->string(255)->notNull()->defaultValue(''),
            'parent_slug'       => $this->string(255)->null(),
            'depth'             => $this->integer()->notNull()->defaultValue(0),
            'is_homepage'       => $this->boolean()->notNull()->defaultValue(false),
            'content_type'      => $this->string(64)->null(),
            'duplicate_slug'    => $this->boolean()->notNull()->defaultValue(false),
            'payload'           => $this->mediumText()->null(),
            'status'            => $this->string(24)->notNull()->defaultValue('pending'),
            'entry_id'          => $this->integer()->null(),
            'result'            => $this->mediumText()->null(),
            'postpass_done'     => $this->boolean()->notNull()->defaultValue(false),
            'acked_at'          => $this->dateTime()->null(),
            'locked_at'         => $this->dateTime()->null(),
            'dateCreated'       => $this->dateTime()->notNull(),
            'dateUpdated'       => $this->dateTime()->notNull(),
            'uid'               => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%contentiq_sync_pages}}',
            'run_id',
            '{{%contentiq_import_runs}}',
            'id',
            'CASCADE',
        );

        $this->createIndex(null, '{{%contentiq_sync_pages}}', ['run_id', 'position']);
        $this->createIndex(null, '{{%contentiq_sync_pages}}', ['run_id', 'status']);
        $this->createIndex(null, '{{%contentiq_sync_pages}}', ['run_id', 'slug']);
    }
}

<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the globals sync tables:
 *  - contentiq_office_syncs: maps ContentiQ office ids to Craft office entries.
 *  - contentiq_globals_sync: single-row consent/lock state for globals syncing.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.3.0
 */
class m260712_000000_add_globals_sync_tables extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createOfficeSyncsTable();
        $this->_createGlobalsSyncTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_office_syncs}}');
        $this->dropTableIfExists('{{%contentiq_globals_sync}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_globals_sync table (single-row lock state).
     *
     * @return void
     */
    private function _createGlobalsSyncTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_globals_sync}}')) {
            return;
        }

        $this->createTable('{{%contentiq_globals_sync}}', [
            'id'        => $this->primaryKey(),
            'locked'    => $this->boolean()->notNull()->defaultValue(true),
            'synced_at' => $this->dateTime()->null(),
            'notes'     => $this->text()->null(),
        ]);
    }

    /**
     * Creates the contentiq_office_syncs table (office id → element id map).
     *
     * @return void
     */
    private function _createOfficeSyncsTable(): void
    {
        if (!$this->db->tableExists('{{%contentiq_office_syncs}}')) {
            $this->createTable('{{%contentiq_office_syncs}}', [
                'id'          => $this->primaryKey(),
                'office_id'   => $this->integer()->notNull(),
                'element_id'  => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
            ]);

            $this->createIndex(null, '{{%contentiq_office_syncs}}', ['office_id'], true);

            $this->addForeignKey(
                null,
                '{{%contentiq_office_syncs}}',
                'element_id',
                '{{%elements}}',
                'id',
                'CASCADE',
            );
        }

        // Enforce single-claim: an office entry may be mapped by at most one wire
        // office id, so a double-claim bug explodes loudly instead of persisting.
        // Idempotent — a re-run over an already-created table still adds it.
        try {
            $this->createIndex(null, '{{%contentiq_office_syncs}}', ['element_id'], true);
        } catch (\Throwable) {
            // Index already present — nothing to do.
        }
    }
}

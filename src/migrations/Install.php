<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Installation migration for the ContentIQ plugin.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.1.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createImportRunsTable();
        $this->_createEntrySyncsTable();
        $this->_createOfficeSyncsTable();
        $this->_createGlobalsSyncTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_globals_sync}}');
        $this->dropTableIfExists('{{%contentiq_office_syncs}}');
        $this->dropTableIfExists('{{%contentiq_entry_syncs}}');
        $this->dropTableIfExists('{{%contentiq_import_runs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_import_runs table.
     *
     * @return void
     */
    private function _createImportRunsTable(): void
    {
        $this->createTable('{{%contentiq_import_runs}}', [
            'id'          => $this->primaryKey(),
            'importedBy'  => $this->integer()->null(),
            'filename'    => $this->string(255)->notNull(),
            'type'        => $this->string(10)->notNull()->defaultValue('single'),
            'pageCount'   => $this->integer()->notNull()->defaultValue(0),
            'imageCount'  => $this->integer()->notNull()->defaultValue(0),
            'status'      => $this->string(20)->notNull()->defaultValue('success'),
            'result'      => $this->longText()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            '{{%contentiq_import_runs}}',
            'importedBy',
            '{{%users}}',
            'id',
            'SET NULL',
        );

        $this->createIndex(null, '{{%contentiq_import_runs}}', ['dateCreated']);
    }

    /**
     * Creates the contentiq_entry_syncs table for per-entry sync tracking.
     *
     * @return void
     */
    private function _createEntrySyncsTable(): void
    {
        $this->createTable('{{%contentiq_entry_syncs}}', [
            'element_id' => $this->integer()->notNull(),
            'locked'     => $this->boolean()->notNull()->defaultValue(false),
            'synced_at'  => $this->dateTime()->null(),
            'notes'      => $this->text(),
            'PRIMARY KEY([[element_id]])',
        ]);

        $this->addForeignKey(
            null,
            '{{%contentiq_entry_syncs}}',
            'element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }

    /**
     * Creates the contentiq_globals_sync table (single-row lock state).
     *
     * @return void
     */
    private function _createGlobalsSyncTable(): void
    {
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
        $this->createTable('{{%contentiq_office_syncs}}', [
            'id'          => $this->primaryKey(),
            'office_id'   => $this->integer()->notNull(),
            'element_id'  => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%contentiq_office_syncs}}', ['office_id'], true);

        // Single-claim guard: one office entry may be mapped by at most one wire
        // office id — a double-claim bug then explodes loudly rather than persisting.
        $this->createIndex(null, '{{%contentiq_office_syncs}}', ['element_id'], true);

        $this->addForeignKey(
            null,
            '{{%contentiq_office_syncs}}',
            'element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }
}

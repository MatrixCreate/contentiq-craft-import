<?php

namespace matrixcreate\copydeckimporter\migrations;

use craft\db\Migration;

/**
 * Installation migration for the Copydeck Importer plugin.
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

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%copydeck_import_runs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the copydeck_import_runs table.
     *
     * @return void
     */
    private function _createImportRunsTable(): void
    {
        $this->createTable('{{%copydeck_import_runs}}', [
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
            '{{%copydeck_import_runs}}',
            'importedBy',
            '{{%users}}',
            'id',
            'SET NULL',
        );

        $this->createIndex(null, '{{%copydeck_import_runs}}', ['dateCreated']);
    }
}

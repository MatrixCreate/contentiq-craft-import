<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the contentiq_entry_syncs table for per-entry sync timestamps.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class m250418_000000_add_entry_syncs_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%contentiq_entry_syncs}}')) {
            return true;
        }

        $this->createTable('{{%contentiq_entry_syncs}}', [
            'element_id' => $this->integer()->notNull(),
            'synced_at'  => $this->dateTime()->notNull(),
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

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_entry_syncs}}');

        return true;
    }
}

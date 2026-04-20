<?php

namespace matrixcreate\copydeckimporter\migrations;

use craft\db\Migration;

/**
 * Adds a locked column to copydeck_entry_syncs.
 *
 * When locked, the entry is skipped during batch/full syncs
 * and the sidebar Sync button is disabled.
 */
class m250419_000001_add_locked_to_entry_syncs extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%copydeck_entry_syncs}}', 'locked')) {
            return true;
        }

        $this->addColumn('{{%copydeck_entry_syncs}}', 'locked', $this->boolean()->notNull()->defaultValue(false)->after('element_id'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%copydeck_entry_syncs}}', 'locked');

        return true;
    }
}

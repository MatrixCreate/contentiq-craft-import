<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

class m260427_000000_rename_copydeck_tables extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%copydeck_entry_syncs}}') && !$this->db->tableExists('{{%contentiq_entry_syncs}}')) {
            $this->renameTable('{{%copydeck_entry_syncs}}', '{{%contentiq_entry_syncs}}');
        }

        if ($this->db->tableExists('{{%copydeck_import_runs}}') && !$this->db->tableExists('{{%contentiq_import_runs}}')) {
            $this->renameTable('{{%copydeck_import_runs}}', '{{%contentiq_import_runs}}');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->tableExists('{{%contentiq_entry_syncs}}') && !$this->db->tableExists('{{%copydeck_entry_syncs}}')) {
            $this->renameTable('{{%contentiq_entry_syncs}}', '{{%copydeck_entry_syncs}}');
        }

        if ($this->db->tableExists('{{%contentiq_import_runs}}') && !$this->db->tableExists('{{%copydeck_import_runs}}')) {
            $this->renameTable('{{%contentiq_import_runs}}', '{{%copydeck_import_runs}}');
        }

        return true;
    }
}

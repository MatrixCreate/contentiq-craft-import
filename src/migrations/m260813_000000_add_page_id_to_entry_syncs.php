<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds a contentiq_page_id column to contentiq_entry_syncs.
 *
 * Maps a ContentIQ page (document.id, a stable id) to its Craft
 * element_id, so findExistingEntry() can resolve the existing entry by
 * page id instead of by slug. Slugs can be renamed in ContentIQ or in
 * Craft; a stable id lookup means a rename no longer creates a duplicate
 * entry and orphans the original.
 *
 * Not unique — legacy rows synced before this column existed have it
 * null, and nothing here prevents multiple null rows.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class m260813_000000_add_page_id_to_entry_syncs extends Migration
{
    public function safeUp(): bool
    {
        // If the table doesn't exist (e.g. Install ran before it was added),
        // create it with all columns known up to this migration.
        if (!$this->db->tableExists('{{%contentiq_entry_syncs}}')) {
            $this->createTable('{{%contentiq_entry_syncs}}', [
                'element_id'        => $this->integer()->notNull(),
                'locked'            => $this->boolean()->notNull()->defaultValue(false),
                'synced_at'         => $this->dateTime(),
                'notes'             => $this->text(),
                'contentiq_page_id' => $this->integer()->null(),
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

            $this->createIndex(null, '{{%contentiq_entry_syncs}}', ['contentiq_page_id']);

            return true;
        }

        if ($this->db->columnExists('{{%contentiq_entry_syncs}}', 'contentiq_page_id')) {
            return true;
        }

        $this->addColumn('{{%contentiq_entry_syncs}}', 'contentiq_page_id', $this->integer()->null()->after('element_id'));
        $this->createIndex(null, '{{%contentiq_entry_syncs}}', ['contentiq_page_id']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%contentiq_entry_syncs}}', 'contentiq_page_id');

        return true;
    }
}

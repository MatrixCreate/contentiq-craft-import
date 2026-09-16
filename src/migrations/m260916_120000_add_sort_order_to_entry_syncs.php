<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds a sort_order column to contentiq_entry_syncs.
 *
 * Persists ContentIQ's own per-parent sibling order (document.sort_order,
 * ties broken by document.id) alongside each entry's sync row, so
 * ImportService::positionInStructure() can read every sibling's stored order
 * in one query and position a page correctly relative to siblings that
 * haven't been re-synced in the current run — see docs/import-pipeline.md's
 * hierarchy section.
 *
 * Nullable — legacy rows synced before this column existed, and any
 * Craft-only entry under a ContentIQ-managed parent (never has a
 * document.sort_order), stay null. positionInStructure() treats a null
 * sort_order as "unknown, keep its place" rather than sorting it first.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.34.0
 */
class m260916_120000_add_sort_order_to_entry_syncs extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
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
                'sort_order'        => $this->integer()->null(),
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

        if ($this->db->columnExists('{{%contentiq_entry_syncs}}', 'sort_order')) {
            return true;
        }

        $this->addColumn('{{%contentiq_entry_syncs}}', 'sort_order', $this->integer()->null()->after('contentiq_page_id'));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropColumn('{{%contentiq_entry_syncs}}', 'sort_order');

        return true;
    }
}

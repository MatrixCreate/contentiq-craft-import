<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the contentiq_card_syncs table: maps a (ContentIQ page id, payload
 * block id, card position) triple to the standalone Cards-section entry it
 * produced.
 *
 * Inline cards in a detected-mode Cards block used to be nested `card`
 * Matrix rows; they are now standalone entries related from the block's
 * `entries` field. The payload carries no per-card id, so position within the
 * block is the third key part. CardsService uses this table to update cards
 * in place on re-sync and to prune cards that fell off the end of a block (or
 * whose block left the payload).
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.38.0
 */
class m261009_000001_add_card_syncs_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createCardSyncsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_card_syncs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_card_syncs table ((page_id, block_id, position) → element id map).
     *
     * @return void
     */
    private function _createCardSyncsTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_card_syncs}}')) {
            return;
        }

        $this->createTable('{{%contentiq_card_syncs}}', [
            'id'          => $this->primaryKey(),
            'page_id'     => $this->integer()->notNull(),
            'block_id'    => $this->string(255)->notNull(),
            'position'    => $this->integer()->notNull(),
            'element_id'  => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        $this->createIndex(null, '{{%contentiq_card_syncs}}', ['page_id', 'block_id', 'position'], true);

        $this->addForeignKey(
            null,
            '{{%contentiq_card_syncs}}',
            'element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }
}

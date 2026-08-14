<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the contentiq_block_syncs table: maps a (owner element id, payload
 * block id) pair to the top-level nested Matrix element id it produced.
 *
 * Every sync used to rebuild the whole contentBlocks Matrix with 'new*' keys,
 * so Craft deleted and recreated every nested block on every save — churning
 * the DB and soft-deleting nested elements, which cascades to and destroys
 * any editor provisional drafts attached to those blocks. This table lets
 * ImportService diff-aware-save the TOP-LEVEL blocks only: when a payload
 * block's stable `id` has a live mapping row, its Matrix key is emitted as
 * the existing nested element id (an int) instead of 'new*', so Craft UPDATES
 * it in place (see CLAUDE.md "Saving nested Matrix data": integer keys are
 * treated as existing entry IDs) — preserving the element's identity,
 * revisions, and drafts.
 *
 * Only populated/consulted when config/contentiq.php sets
 * 'preserveBlockIdentity' => true. Defaults to false — off until validated
 * on a live Craft instance (this rewrites the core save path and cannot be
 * integration-tested in a standalone PHP environment). See CLAUDE.md.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class m260814_000002_add_block_syncs_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createBlockSyncsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_block_syncs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_block_syncs table ((owner_element_id, block_id) → nested element id map).
     *
     * @return void
     */
    private function _createBlockSyncsTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_block_syncs}}')) {
            return;
        }

        $this->createTable('{{%contentiq_block_syncs}}', [
            'id'                => $this->primaryKey(),
            'owner_element_id'  => $this->integer()->notNull(),
            'block_id'          => $this->string(255)->notNull(),
            'nested_element_id' => $this->integer()->notNull(),
            'dateCreated'       => $this->dateTime()->notNull(),
            'dateUpdated'       => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%contentiq_block_syncs}}', ['owner_element_id', 'block_id'], true);

        $this->addForeignKey(
            null,
            '{{%contentiq_block_syncs}}',
            'nested_element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );

        $this->addForeignKey(
            null,
            '{{%contentiq_block_syncs}}',
            'owner_element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }
}

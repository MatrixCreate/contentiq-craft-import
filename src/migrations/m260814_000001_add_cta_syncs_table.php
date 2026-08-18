<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the contentiq_cta_syncs table: maps a (ContentIQ page id, block id)
 * pair to the Craft callToActionEntry element it produced.
 *
 * Call to Action blocks were previously matched by title alone
 * (`Entry::find()->title($title)`), so two CTA blocks on the same page with
 * the same (or blank) heading collided onto a single Craft entry instead of
 * staying distinct. ContentIQ's export now carries each block's stable id
 * (unique within a page, survives edits/reorders); this table lets
 * ImportService::_resolveCtaEntry() key identity on (page_id, block_id)
 * instead, falling back to the old title lookup (and adopting the match
 * into this table) when no mapping row exists yet — so existing
 * title-matched CTAs are adopted once, not duplicated.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class m260814_000001_add_cta_syncs_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createCtaSyncsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_cta_syncs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_cta_syncs table ((page_id, block_id) → element id map).
     *
     * @return void
     */
    private function _createCtaSyncsTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_cta_syncs}}')) {
            return;
        }

        $this->createTable('{{%contentiq_cta_syncs}}', [
            'id'          => $this->primaryKey(),
            'page_id'     => $this->integer()->notNull(),
            'block_id'    => $this->string(255)->notNull(),
            'element_id'  => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%contentiq_cta_syncs}}', ['page_id', 'block_id'], true);

        $this->addForeignKey(
            null,
            '{{%contentiq_cta_syncs}}',
            'element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }
}

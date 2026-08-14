<?php

namespace matrixcreate\contentiqimporter\migrations;

use craft\db\Migration;

/**
 * Adds the contentiq_asset_syncs table: maps a ContentIQ image key (the
 * upstream asset's stable storage path, "{project_id}/{page_id}/{filename}")
 * to its Craft asset element id.
 *
 * Without this table, ImageImportService::importFromField() could only
 * de-duplicate assets by filename within the shared import folder — two
 * images from different pages/projects that happen to share a bare filename
 * (e.g. hero.jpg, logo.png) would collide and reuse the wrong asset. The
 * image key is globally unique and stable across in-place content
 * replacement (unlike the filename), so it is the correct identity for
 * reuse once a mapping has been recorded.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class m260814_000000_add_asset_syncs_table extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createAssetSyncsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%contentiq_asset_syncs}}');

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the contentiq_asset_syncs table (image key → element id map).
     *
     * @return void
     */
    private function _createAssetSyncsTable(): void
    {
        if ($this->db->tableExists('{{%contentiq_asset_syncs}}')) {
            return;
        }

        $this->createTable('{{%contentiq_asset_syncs}}', [
            'id'          => $this->primaryKey(),
            'image_key'   => $this->string(255)->notNull(),
            'element_id'  => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%contentiq_asset_syncs}}', ['image_key'], true);

        $this->addForeignKey(
            null,
            '{{%contentiq_asset_syncs}}',
            'element_id',
            '{{%elements}}',
            'id',
            'CASCADE',
        );
    }
}

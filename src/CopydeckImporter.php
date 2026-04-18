<?php

namespace matrixcreate\copydeckimporter;

use Craft;
use craft\base\Plugin;
use matrixcreate\copydeckimporter\services\ImageImportService;
use matrixcreate\copydeckimporter\services\ImportService;
use matrixcreate\copydeckimporter\services\MatrixBuilder;
use matrixcreate\copydeckimporter\services\NodesRenderer;

/**
 * Copydeck Importer plugin.
 *
 * @property-read ImageImportService $images
 * @property-read ImportService $imports
 * @property-read MatrixBuilder $matrixBuilder
 * @property-read NodesRenderer $nodes
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class CopydeckImporter extends Plugin
{
    // Constants
    // =========================================================================

    /** @var string */
    public const VERSION = '1.0.0';

    // Static Properties
    // =========================================================================

    /** @var CopydeckImporter|null */
    public static ?CopydeckImporter $plugin = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        $this->setComponents([
            'images' => ImageImportService::class,
            'imports' => ImportService::class,
            'matrixBuilder' => MatrixBuilder::class,
            'nodes' => NodesRenderer::class,
        ]);

        Craft::info(
            Craft::t('copydeck-importer', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__,
        );
    }
}

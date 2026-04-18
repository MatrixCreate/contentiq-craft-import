<?php

namespace matrixcreate\copydeckimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use matrixcreate\copydeckimporter\CopydeckImporter;
use yii\console\ExitCode;

/**
 * Copydeck import console controller.
 *
 * Usage:
 *   php craft copydeck/import --file=export.json
 *   php craft copydeck/import --file=export.json --dry-run
 *   php craft copydeck/import --file=export.json --verbose
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImportController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'import';

    // Public Properties
    // =========================================================================

    /**
     * @var string|null Path to the Copydeck JSON export file.
     */
    public ?string $file = null;

    /**
     * @var bool Whether to run in dry-run mode (validates and reports, writes nothing).
     */
    public bool $dryRun = false;

    /**
     * @var bool Whether to output verbose block-by-block and image logging.
     */
    public bool $verbose = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'file';
        $options[] = 'dryRun';
        $options[] = 'verbose';

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases(): array
    {
        $aliases = parent::optionAliases();
        $aliases['f'] = 'file';
        $aliases['n'] = 'dryRun';
        $aliases['v'] = 'verbose';

        return $aliases;
    }

    /**
     * Import a Copydeck JSON export into Craft CMS as draft entries.
     *
     * Detects single-page vs batch format from the JSON shape:
     * - Top-level `blocks` array = single page
     * - Top-level `pages` array = batch (loops single-page pipeline)
     *
     * @return int
     */
    public function actionImport(): int
    {
        App::maxPowerCaptain();

        if ($this->file === null) {
            $this->failure('`--file` is required. Usage: craft copydeck/import --file=export.json');

            return ExitCode::USAGE;
        }

        // Resolve the file path to absolute before chdir() changes the CWD.
        $file = realpath($this->file) ?: $this->file;

        if (!is_file($file)) {
            $this->failure("File not found: `{$this->file}`");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        // Local filesystem paths in project.yaml are relative to the web root
        // (e.g. "assets/cms/images"). Web requests execute with CWD = web/, so
        // paths resolve correctly. CLI runs from the project root, so we must
        // manually change to the webroot to match.
        chdir(Craft::getAlias('@webroot'));

        $json = file_get_contents($file);

        if ($json === false) {
            $this->failure("Could not read file: `$file`");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->failure('Invalid JSON: ' . json_last_error_msg());

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout('[DRY RUN] ' . PHP_EOL, Console::FG_YELLOW, Console::BOLD);
        }

        $importService = CopydeckImporter::$plugin->imports;

        // Batch format has a top-level 'pages' array.
        if (isset($data['pages']) && is_array($data['pages'])) {
            return $this->_runBatch($data['pages'], $importService);
        }

        // Single-page format has 'document' and 'blocks' at the top level.
        return $this->_runSinglePage($data, $importService);
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a batch import by looping over each page through the single-page pipeline.
     *
     * @param array[] $pages
     * @param \matrixcreate\copydeckimporter\services\ImportService $importService
     * @return int
     */
    private function _runBatch(array $pages, $importService): int
    {
        $this->stdout(sprintf("Batch import: %d pages\n", count($pages)));

        $exitCode = ExitCode::OK;

        foreach ($pages as $i => $pageData) {
            $this->stdout(sprintf("\n[%d/%d] ", $i + 1, count($pages)), Console::FG_GREY);
            $result = $this->_runSinglePage($pageData, $importService);
            if ($result !== ExitCode::OK) {
                $exitCode = ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return $exitCode;
    }

    /**
     * Runs the import pipeline for a single page and renders output.
     *
     * @param array $data
     * @param \matrixcreate\copydeckimporter\services\ImportService $importService
     * @return int
     */
    private function _runSinglePage(array $data, $importService): int
    {
        $result = $importService->importPage($data, $this->dryRun, $this->verbose);

        $prefix = $this->dryRun ? '[DRY RUN] ' : '';

        $this->stdout("{$prefix}Page: {$result['slug']}\n", Console::BOLD);
        $this->stdout("  Entry: ");

        if ($result['entryFound']) {
            $this->stdout("updated (ID {$result['entryId']})\n", Console::FG_GREEN);
        } elseif ($this->dryRun) {
            $this->stdout("not found — will create\n", Console::FG_YELLOW);
        } else {
            $this->stdout("created (ID {$result['entryId']})\n", Console::FG_GREEN);
        }

        $seoLabel = $this->dryRun
            ? "{$result['seoFieldCount']} fields would populate"
            : "{$result['seoFieldCount']} fields populated";
        $this->stdout("  SEO: {$seoLabel}\n");

        $blockCount = count($result['blocks']);
        $blockLabel = $this->dryRun
            ? "{$blockCount} blocks would import"
            : "{$blockCount} blocks imported";
        $this->stdout("  Blocks: {$blockLabel}\n");

        foreach ($result['blocks'] as $block) {
            if ($block['skipped']) {
                $this->stdout("    ✗ {$block['type']} — skipped (unknown type)\n", Console::FG_YELLOW);
            } else {
                $fields     = implode(', ', $block['fields']);
                $innerCount = $block['innerCount'] ?? 1;
                $countLabel = $innerCount > 1 ? " ({$innerCount} inner)" : '';
                $this->stdout("    ✓ {$block['type']}{$countLabel} — {$fields}\n", Console::FG_GREEN);
            }
        }

        $imageCount = count($result['images']);

        if ($imageCount > 0) {
            $imageLabel = $this->dryRun
                ? "{$imageCount} would download"
                : "{$imageCount} imported";
            $this->stdout("  Images: {$imageLabel}\n");

            if ($this->verbose || $this->dryRun) {
                foreach ($result['images'] as $image) {
                    $status = $image['reused'] ? '(reused)' : ($this->dryRun ? '' : '(downloaded)');
                    $this->stdout("    - {$image['filename']} {$status}\n", Console::FG_GREY);
                }
            }
        }

        foreach ($result['warnings'] as $warning) {
            $this->warning($warning);
        }

        if (!$result['success']) {
            $this->failure($result['error'] ?? 'Import failed.');

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("[DRY RUN] Nothing written.\n\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }
}

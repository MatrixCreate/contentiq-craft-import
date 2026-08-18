<?php

namespace matrixcreate\contentiqimporter\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings model.
 *
 * Stores ContentIQ API connection details used by the sync flow.
 * Saved via Craft's built-in plugin settings mechanism (project config).
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class Settings extends Model
{
    /**
     * Base URL of the ContentIQ instance (e.g. https://contentiq.agency.com).
     *
     * @var string
     */
    public string $contentiqUrl = '';

    /**
     * API key from ContentIQ project settings.
     *
     * @var string
     */
    public string $apiKey = '';

    /**
     * Collection slug → Craft routing map, edited in the CP Mappings screen.
     *
     * Keyed by ContentIQ collection slug. Each row has the shape:
     *   ['section' => handle, 'entryType' => handle, 'contentField' => handle,
     *    'headingField' => handle|null, 'blocksField' => handle|null]
     *
     * blocksField is optional — when null/absent, a collection child's blocks[]
     * (when present) writes to config['matrixField'] instead, same as pages.
     *
     * Merged between defaults.php and the config/contentiq.php `content_types`
     * override by ImportService::_getContentTypesMap() (defaults ← settings ← file).
     *
     * @var array<string, array{section: string, entryType: string, contentField: string, headingField: ?string, blocksField: ?string}>
     */
    public array $collectionMappings = [];

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['contentiqUrl', 'apiKey'], 'string'],
            ['contentiqUrl', 'validateParsedUrl'],
            ['collectionMappings', 'validateCollectionMappings'],
        ];
    }

    /**
     * Normalises collectionMappings: drops rows with an empty section and
     * coerces each surviving row to the canonical shape.
     *
     * Runs as an inline validator, which also marks the attribute safe so
     * Craft's plugin-settings save assigns it via setAttributes().
     *
     * @return void
     */
    public function validateCollectionMappings(): void
    {
        $normalised = [];

        foreach ($this->collectionMappings as $slug => $row) {
            if (!is_array($row)) {
                continue;
            }

            $section = trim((string)($row['section'] ?? ''));

            if ($section === '') {
                continue;
            }

            $headingField = trim((string)($row['headingField'] ?? ''));
            $blocksField  = trim((string)($row['blocksField'] ?? ''));

            $normalised[(string)$slug] = [
                'section'      => $section,
                'entryType'    => trim((string)($row['entryType'] ?? '')),
                'contentField' => trim((string)($row['contentField'] ?? '')),
                'headingField' => $headingField !== '' ? $headingField : null,
                'blocksField'  => $blocksField !== '' ? $blocksField : null,
            ];
        }

        $this->collectionMappings = $normalised;
    }

    /**
     * Validates contentiqUrl after resolving any environment variable alias.
     */
    public function validateParsedUrl(): void
    {
        $resolved = App::parseEnv($this->contentiqUrl);

        if ($resolved === '') {
            return;
        }

        if (filter_var($resolved, FILTER_VALIDATE_URL) === false) {
            $this->addError('contentiqUrl', 'ContentiQ URL is not a valid URL.');
        }
    }
}

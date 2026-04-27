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
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['contentiqUrl', 'apiKey'], 'string'],
            ['contentiqUrl', 'validateParsedUrl'],
        ];
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
            $this->addError('contentiqUrl', 'ContentIQ URL is not a valid URL.');
        }
    }
}

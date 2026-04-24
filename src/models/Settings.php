<?php

namespace matrixcreate\copydeckimporter\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings model.
 *
 * Stores Copydeck API connection details used by the sync flow.
 * Saved via Craft's built-in plugin settings mechanism (project config).
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.2.0
 */
class Settings extends Model
{
    /**
     * Base URL of the Copydeck instance (e.g. https://copydeck.agency.com).
     *
     * @var string
     */
    public string $copydeckUrl = '';

    /**
     * API key from Copydeck project settings.
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
            [['copydeckUrl', 'apiKey'], 'string'],
            ['copydeckUrl', 'validateParsedUrl'],
        ];
    }

    /**
     * Validates copydeckUrl after resolving any environment variable alias.
     */
    public function validateParsedUrl(): void
    {
        $resolved = App::parseEnv($this->copydeckUrl);

        if ($resolved === '') {
            return;
        }

        if (filter_var($resolved, FILTER_VALIDATE_URL) === false) {
            $this->addError('copydeckUrl', 'Copydeck URL is not a valid URL.');
        }
    }
}

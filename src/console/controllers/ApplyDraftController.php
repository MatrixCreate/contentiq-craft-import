<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Applies a ContentIQ Import draft to its canonical entry.
 *
 * Usage:
 *   php craft contentiq-importer/apply-draft/apply --draft-id=176
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ApplyDraftController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null The draft element ID to apply.
     */
    public ?int $draftId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'draftId';

        return $options;
    }

    /**
     * Apply a ContentIQ Import draft to its canonical entry.
     *
     * @return int
     */
    public function actionApply(): int
    {
        if ($this->draftId === null) {
            $this->failure('--draft-id is required.');

            return ExitCode::USAGE;
        }

        $draft = Entry::find()
            ->id($this->draftId)
            ->drafts(true)
            ->status(null)
            ->one();

        if ($draft === null) {
            $this->failure("Draft ID {$this->draftId} not found.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Applying draft ID {$this->draftId} to canonical entry ID {$draft->canonicalId}... ");

        $applied = Craft::$app->getDrafts()->applyDraft($draft);

        if ($applied === false) {
            $this->failure("Failed to apply draft.");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("OK (canonical entry ID {$applied->id})\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}

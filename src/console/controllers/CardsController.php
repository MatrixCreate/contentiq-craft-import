<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\fields\Entries;
use craft\helpers\Console;
use craft\models\EntryType;
use craft\models\Section;
use matrixcreate\contentiqimporter\helpers\CardMigrationMapper;
use RuntimeException;
use Throwable;
use yii\console\ExitCode;

/**
 * Converts legacy "Manual" Entry Cards blocks to the standalone-card model.
 *
 * For each Entry Cards block in manual mode, every nested `card` entry becomes
 * a standalone entry in the Cards channel section, the new entries are linked
 * from the block's `entries` relation field, the block is flipped to automatic
 * mode, and the nested cards are deleted. "Stale" nested cards on blocks that
 * are not in manual mode are deleted too.
 *
 * Every handle is resolved at start (nothing is hard-coded to ids):
 *   - content-blocks Matrix field: config `matrixField` (default `contentBlocks`)
 *   - block type:                  `cards` mapping `outerType` (default `entryCards`)
 *   - nested field / inner type:   `cards` mapping `innerMatrix.outerField` / `innerType`
 *                                  (defaults `entryCards` / `card`)
 *   - target section / entry type / relation field: `cards` mapping `standalone.section`
 *     / `entryType` / `relationField` (defaults `cards` / `standaloneCard` / `entries`)
 *
 * The `cards` mapping honours `blockOverrides.cards` from config/contentiq.php
 * (overrides replace the whole mapping, as in MatrixBuilder::prepare()).
 *
 * A second action, `cards/prune-orphans`, soft-deletes migrated cards left behind after a re-sync.
 *
 * Usage:
 *   php craft contentiq-importer/cards/prune-orphans [--dry-run]
 *   php craft contentiq-importer/cards/migrate --dry-run
 *   php craft contentiq-importer/cards/migrate --block-id=123
 *   php craft contentiq-importer/cards/migrate --owner-id=456
 *   php craft contentiq-importer/cards/migrate
 *
 * See docs/cards-migrate.md.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.38.0
 */
class CardsController extends Controller
{
    // Constants
    // =========================================================================

    private const NO_LEGACY = 404;
    private const REVISION_NOTE = 'Before Entry Cards manual-card migration';

    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $defaultAction = 'migrate';

    /**
     * @var bool Report everything, write nothing.
     */
    public bool $dryRun = false;

    /**
     * @var int|null Restrict to this Entry Cards block id.
     */
    public ?int $blockId = null;

    /**
     * @var int|null Restrict to blocks owned by this entry id.
     */
    public ?int $ownerId = null;

    // Private Properties
    // =========================================================================

    /** @var int[] Owner ids that already have a restore-point revision this run. */
    private array $_revisioned = [];

    private int $_siteId;
    private string $_modeHandle = 'cardsInThisBlock';
    private string $_relationHandle;
    private int $_nestedFieldId;
    private EntryType $_blockType;
    private EntryType $_innerType;
    private EntryType $_cardType;
    private Section $_cardSection;
    private ?int $_fallbackAuthorId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'dryRun';
        $options[] = 'blockId';
        $options[] = 'ownerId';

        return $options;
    }

    /**
     * Moves manual Entry Cards blocks' nested cards into standalone Card entries linked via the block's relation field.
     */
    public function actionMigrate(): int
    {
        try {
            $matrixFieldId = $this->_resolve();
        } catch (RuntimeException $e) {
            if ($e->getCode() === self::NO_LEGACY) {
                $this->stdout($e->getMessage() . "\n", Console::FG_YELLOW);

                return ExitCode::OK;
            }
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $query = Entry::find()
            ->typeId($this->_blockType->id)
            ->fieldId($matrixFieldId)
            ->siteId($this->_siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->orderBy('elements.id');

        if ($this->ownerId !== null) {
            $query->ownerId($this->ownerId);
        }
        if ($this->blockId !== null) {
            $query->id($this->blockId);
        }

        $stats  = ['blocks' => 0, 'cards' => 0, 'stale' => 0, 'staleCards' => 0, 'skipped' => [], 'failed' => 0];
        $prefix = $this->dryRun ? '[dry run] ' : '';

        /** @var Entry $block */
        foreach ($query->all() as $block) {
            try {
                $this->_processBlock($block, $prefix, $stats);
            } catch (Throwable $e) {
                $this->stderr("  FAILED (block #{$block->id}): " . $e->getMessage() . "\n", Console::FG_RED);
                $stats['failed']++;
            }
        }

        $this->stdout("\n" . ($this->dryRun ? 'DRY RUN - nothing was written.' : 'Done.') . "\n", Console::FG_GREEN);
        $this->stdout(sprintf(
            "%d block(s) %s, %d card(s) %s, %d failed, %d skipped.\n",
            $stats['blocks'],
            $this->dryRun ? 'migratable' : 'migrated',
            $stats['cards'],
            $this->dryRun ? 'to create' : 'created',
            $stats['failed'],
            array_sum($stats['skipped']),
        ), $stats['failed'] ? Console::FG_RED : Console::FG_GREEN);
        $this->stdout(sprintf(
            "%d non-manual block(s) with stale nested cards, %d stale card(s) %s.\n",
            $stats['stale'],
            $stats['staleCards'],
            $this->dryRun ? 'to delete' : 'deleted',
        ));
        foreach ($stats['skipped'] as $reason => $count) {
            $this->stdout("  skipped ($reason): $count\n");
        }

        return $stats['failed'] ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Soft-deletes orphaned standalone cards: live cards in the Cards section with no
     * contentiq_card_syncs row that no live element relates to. Never touches import-owned or in-use cards.
     */
    public function actionPruneOrphans(): int
    {
        try {
            $this->_resolveTarget();
        } catch (RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $db = Craft::$app->getDb();
        if (!$db->tableExists('{{%contentiq_card_syncs}}')) {
            $this->stderr("Table contentiq_card_syncs not found; run the plugin migrations first.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $syncRows = (new Query())->select('element_id')->from('{{%contentiq_card_syncs}}')->column();
        $synced   = array_flip(array_map('intval', $syncRows));

        $candidates = Entry::find()
            ->sectionId($this->_cardSection->id)
            ->typeId($this->_cardType->id)
            ->siteId($this->_siteId)
            // Enabled cards only: an editor who disabled a card rather than
            // deleting it is keeping it on purpose, so it is never an orphan.
            ->status(Entry::STATUS_LIVE)
            ->drafts(false)
            ->revisions(false)
            ->orderBy('elements.id')
            ->all();

        $prefix  = $this->dryRun ? '[dry run] ' : '';
        $elements = Craft::$app->getElements();
        $orphans = $kept = $failed = 0;

        /** @var Entry $card */
        foreach ($candidates as $card) {
            if (isset($synced[(int)$card->id])) {
                $kept++;
                continue;
            }
            if ($this->_hasLiveRelation((int)$card->id)) {
                $kept++;
                continue;
            }

            $orphans++;
            $this->stdout(sprintf(
                "%s#%d \"%s\" (created %s)\n",
                $prefix,
                $card->id,
                $card->title,
                $card->dateCreated?->format('Y-m-d H:i') ?? 'unknown',
            ));

            if ($this->dryRun) {
                continue;
            }

            try {
                if (!$elements->deleteElement($card)) {
                    throw new RuntimeException('deleteElement returned false');
                }
            } catch (Throwable $e) {
                $this->stderr("  FAILED deleting #{$card->id}: " . $e->getMessage() . "\n", Console::FG_RED);
                $failed++;
            }
        }

        $this->stdout(sprintf(
            "\n%s%d orphaned card(s) %s, %d kept (import-owned or in use), %d failed.\n",
            $this->dryRun ? 'DRY RUN - nothing was written. ' : '',
            $orphans - $failed,
            $this->dryRun ? 'would be deleted' : 'deleted',
            $kept,
            $failed,
        ), $failed ? Console::FG_RED : Console::FG_GREEN);

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * True if any live element (not trashed/draft/revision, and whose primary owner, if any, is also live) relates to $targetId.
     */
    private function _hasLiveRelation(int $targetId): bool
    {
        return (new Query())
            ->from(['r' => Table::RELATIONS])
            ->innerJoin(['s' => Table::ELEMENTS], '[[s.id]] = [[r.sourceId]]')
            ->leftJoin(['se' => Table::ENTRIES], '[[se.id]] = [[s.id]]')
            ->leftJoin(['o' => Table::ELEMENTS], '[[o.id]] = [[se.primaryOwnerId]]')
            ->where(['r.targetId' => $targetId])
            ->andWhere(['s.dateDeleted' => null, 's.revisionId' => null, 's.draftId' => null])
            ->andWhere(['or',
                ['se.primaryOwnerId' => null],
                ['o.dateDeleted' => null, 'o.revisionId' => null, 'o.draftId' => null],
            ])
            ->exists();
    }

    /**
     * Processes one block, updating $stats. Exceptions propagate to the caller, which counts them as failures.
     *
     * @param array<string, mixed> $stats
     */
    private function _processBlock(Entry $block, string $prefix, array &$stats): void
    {
        $owner = $block->getPrimaryOwner();

        if ($owner === null) {
            $this->stdout("{$prefix}Block #{$block->id} - no owner\n", Console::BOLD);
            $this->_skip($stats, 'no owner', 'owner not found');
            return;
        }

        // Never touch $owner->section: it throws when the section is soft-deleted.
        $sectionHandle = Craft::$app->getEntries()->getSectionById((int)$owner->sectionId)?->handle
            ?? 'section #' . $owner->sectionId;
        $this->stdout("{$prefix}Block #{$block->id} - owner #{$owner->id} \"{$owner->title}\" ($sectionHandle)\n", Console::BOLD);

        if ($owner->trashed) {
            $this->_skip($stats, 'owner trashed', 'owner is trashed');
            return;
        }
        if ($owner->getIsDraft() || $owner->getIsRevision()) {
            $this->_skip($stats, 'draft/revision owner', 'owner is a draft or revision');
            return;
        }
        if (Craft::$app->getEntries()->getSectionById((int)$owner->sectionId) === null) {
            $this->_skip($stats, 'owner section deleted', "owner's section #{$owner->sectionId} is deleted");
            return;
        }

        $cards = $this->_nestedCards($block);
        // Mode field absent from the layout (dropdown already removed mid-upgrade) with nested
        // cards present: those cards are the block's only card data, so migrate, never delete.
        $isManual = $this->_blockMode($block) === 'manual'
            || (!$this->_hasModeField($block) && $cards);

        if (!$isManual) {
            if (!$cards) {
                $this->_skip($stats, 'automatic', 'automatic mode, no nested cards');
                return;
            }
            $this->_deleteStale($owner, $cards);
            $stats['stale']++;
            $stats['staleCards'] += count($cards);
            return;
        }

        if (!$cards) {
            $this->_skip($stats, 'manual, no cards', 'manual mode but no nested cards');
            return;
        }

        $created = $this->_migrateBlock($block, $owner, $cards);

        if ($created === null) {
            $stats['failed']++;
            return;
        }

        $stats['blocks']++;
        $stats['cards'] += $created;
    }

    /**
     * Resolves only what the target side needs (Cards section, standalone entry type, primary
     * site setting), failing fast with a RuntimeException. Returns the merged `cards` mapping
     * and the plugin config so callers can resolve the rest.
     *
     * @return array{0: array, 1: array}
     */
    private function _resolveTarget(): array
    {
        $config   = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $config   = is_array($config) ? $config : [];
        $defaults = require dirname(__DIR__, 2) . '/config/defaults.php';
        $mapping  = ($config['blockOverrides']['cards'] ?? null) ?: ($defaults['cards'] ?? []);

        $standalone     = is_array($mapping['standalone'] ?? null) ? $mapping['standalone'] : [];
        $sectionHandle  = (string)($standalone['section'] ?? 'cards');
        $cardTypeHandle = (string)($standalone['entryType'] ?? 'standaloneCard');
        $this->_relationHandle = (string)($standalone['relationField'] ?? 'entries');

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section) {
            throw new RuntimeException("Cards section '$sectionHandle' not found (cards mapping 'standalone.section'). Add the Cards channel section first.");
        }
        $this->_cardSection = $section;

        $cardType = null;
        foreach ($section->getEntryTypes() as $type) {
            if ($type->handle === $cardTypeHandle) {
                $cardType = $type;
                break;
            }
        }
        if (!$cardType) {
            throw new RuntimeException("Entry type '$cardTypeHandle' is not available in section '$sectionHandle' (cards mapping 'standalone.entryType').");
        }
        $this->_cardType = $cardType;

        $this->_siteId = Craft::$app->getSites()->getPrimarySite()->id;
        if (!isset($section->getSiteSettings()[$this->_siteId])) {
            throw new RuntimeException("Section '$sectionHandle' has no site settings for the primary site; this command is single-site (primary site only). Enable the section for the primary site and re-run.");
        }

        return [$mapping, $config];
    }

    /**
     * Resolves every handle `migrate` needs, failing fast with a RuntimeException. A missing legacy
     * nested card type/field throws with code self::NO_LEGACY (nothing to migrate, not an error).
     * Returns the content-blocks Matrix field id.
     */
    private function _resolve(): int
    {
        [$mapping, $config] = $this->_resolveTarget();

        $matrixHandle = (string)($config['matrixField'] ?? 'contentBlocks');
        $blockTypeH   = (string)($mapping['outerType'] ?? 'entryCards');
        $nestedHandle = (string)($mapping['innerMatrix']['outerField'] ?? 'entryCards');
        $innerTypeH   = (string)($mapping['innerMatrix']['innerType'] ?? 'card');
        $sectionHandle = $this->_cardSection->handle;
        $noLegacy = "No legacy nested cards to migrate (type '$innerTypeH' / field '$nestedHandle' not found)";

        $matrix = Craft::$app->getFields()->getFieldByHandle($matrixHandle);
        if (!$matrix) {
            throw new RuntimeException("Content-blocks Matrix field '$matrixHandle' not found (config 'matrixField').");
        }

        $blockType = $this->_entryTypeByHandle($blockTypeH);
        if (!$blockType) {
            throw new RuntimeException("Block entry type '$blockTypeH' not found (cards mapping 'outerType').");
        }
        $this->_blockType = $blockType;

        $innerType = $this->_entryTypeByHandle($innerTypeH);
        if (!$innerType) {
            throw new RuntimeException($noLegacy, self::NO_LEGACY);
        }
        $this->_innerType = $innerType;

        $nested = $blockType->getFieldLayout()->getFieldByHandle($nestedHandle);
        if (!$nested) {
            throw new RuntimeException($noLegacy, self::NO_LEGACY);
        }
        $this->_nestedFieldId = (int)$nested->id;

        $relation = $blockType->getFieldLayout()->getFieldByHandle($this->_relationHandle);
        if (!$relation) {
            throw new RuntimeException("Relation field '{$this->_relationHandle}' is not in the '$blockTypeH' layout (cards mapping 'standalone.relationField').");
        }
        if (!$relation instanceof Entries) {
            throw new RuntimeException("Relation field '{$this->_relationHandle}' is not an Entries field.");
        }
        $sources = $relation->sources;
        if ($sources !== '*' && !in_array('section:' . $this->_cardSection->uid, (array)$sources, true)) {
            throw new RuntimeException("Relation field '{$this->_relationHandle}' does not include section '$sectionHandle' in its sources; linked cards would not be selectable. Add the section to the field's sources and re-run.");
        }

        return (int)$matrix->id;
    }

    private function _entryTypeByHandle(string $handle): ?EntryType
    {
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $type) {
            if ($type->handle === $handle) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Migrates one block. Returns the number of cards created (or planned), or null on failure.
     *
     * @param Entry[] $cards
     */
    private function _migrateBlock(Entry $block, Entry $owner, array $cards): ?int
    {
        $authorId = $owner->getAuthorId() ?: $this->_fallbackAuthor();

        $relation = $block->getFieldLayout()?->getFieldByHandle($this->_relationHandle);
        $max      = $relation instanceof Entries ? $relation->maxRelations : null;
        if ($max && count($cards) > $max) {
            throw new RuntimeException(sprintf("%d cards exceed the '%s' field's max relations (%d)", count($cards), $this->_relationHandle, $max));
        }

        $inputs = [];
        foreach (array_values($cards) as $i => $card) {
            $inputs[] = $this->_cardInput($card, $i + 1);
        }

        foreach ($inputs as $i => $in) {
            $fields = CardMigrationMapper::cardFields($in);
            $this->stdout(sprintf(
                '  card %d: "%s"%s%s%s%s' . "\n",
                $i + 1,
                $fields['title'],
                $fields['cardImage'] ? ' image=' . $fields['cardImage'][0] : '',
                $fields['cardLink'] ? ' link' : '',
                $in['enabled'] ? '' : ' (disabled)',
                $in['note'] !== '' ? ' [' . $in['note'] . ']' : '',
            ));
        }

        if ($this->dryRun) {
            $this->stdout(sprintf(
                "  would create %d card(s), link them to block #%d and delete %d nested card(s) (author #%s)\n",
                count($cards),
                $block->id,
                count($cards),
                $authorId ?? 'none',
            ));

            return count($cards);
        }

        $this->_ensureRevision($owner);

        $elements    = Craft::$app->getElements();
        $cardLayout  = $this->_cardType->getFieldLayout();
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $cardIds = [];
            foreach ($inputs as $in) {
                $fields = CardMigrationMapper::cardFields($in);
                $new = new Entry();
                $new->sectionId = $this->_cardSection->id;
                $new->typeId    = $this->_cardType->id;
                $new->siteId    = $this->_siteId;
                if ($authorId) {
                    $new->setAuthorId($authorId);
                }
                $new->enabled = $in['enabled'];
                $new->title   = $fields['title'];
                foreach (['cardText', 'cardImage', 'cardLink'] as $handle) {
                    if ($cardLayout->getFieldByHandle($handle)) {
                        $new->setFieldValue($handle, $fields[$handle]);
                    } else {
                        $this->_warnOnce("standalone type '{$this->_cardType->handle}' has no '$handle' field; that value is not migrated");
                    }
                }

                if (!$elements->saveElement($new)) {
                    return $this->_fail($transaction, 'card', $new);
                }
                $cardIds[] = (int)$new->id;
            }

            $block->setFieldValue($this->_relationHandle, $cardIds);
            $layout = $block->getFieldLayout();
            if ($layout?->getFieldByHandle('useChildPages')) {
                $block->setFieldValue('useChildPages', false);
            }
            if ($layout?->getFieldByHandle($this->_modeHandle)) {
                $block->setFieldValue($this->_modeHandle, 'automatic');
            }
            if (!$elements->saveElement($block)) {
                return $this->_fail($transaction, 'block', $block);
            }

            foreach ($cards as $card) {
                if (!$elements->deleteElement($card)) {
                    $transaction->rollBack();
                    $this->stderr("  FAILED: could not delete nested card #{$card->id}; rolled back\n", Console::FG_RED);

                    return null;
                }
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->stdout(sprintf(
            "  created cards [%s]; linked %d cards to block #%d, deleted %d nested cards\n",
            implode(', ', $cardIds),
            count($cardIds),
            $block->id,
            count($cards),
        ), Console::FG_CYAN);

        return count($cardIds);
    }

    /**
     * 'manual' or 'automatic'. Reads the dropdown through the element API, guarded by a
     * layout probe. A missing field reads 'automatic' here; the caller treats a field-less block
     * that still has nested cards as manual (see actionMigrate()).
     */
    private function _blockMode(Entry $block): string
    {
        if (!$this->_hasModeField($block)) {
            return 'automatic';
        }

        $value = $block->getFieldValue($this->_modeHandle);
        $value = is_object($value) && property_exists($value, 'value') ? $value->value : $value;

        return (string)$value === 'manual' ? 'manual' : 'automatic';
    }

    /**
     * Creates the restore-point revision of an owner once per run.
     */
    private function _ensureRevision(Entry $owner): void
    {
        if (isset($this->_revisioned[$owner->id])) {
            return;
        }
        Craft::$app->getRevisions()->createRevision($owner, null, self::REVISION_NOTE);
        $this->_revisioned[$owner->id] = true;
        $this->stdout("  revision created for owner #{$owner->id}\n");
    }

    private function _hasModeField(Entry $block): bool
    {
        return (bool)$block->getFieldLayout()?->getFieldByHandle($this->_modeHandle);
    }

    /**
     * Nested cards of a block in stored order (elements_owners.sortOrder).
     *
     * @return Entry[]
     */
    private function _nestedCards(Entry $block): array
    {
        return Entry::find()
            ->typeId($this->_innerType->id)
            ->fieldId($this->_nestedFieldId)
            ->ownerId($block->id)
            ->siteId($this->_siteId)
            ->status(null)
            ->drafts(false)
            ->revisions(false)
            ->orderBy(['elements_owners.sortOrder' => SORT_ASC])
            ->all();
    }

    /**
     * Soft-deletes stale nested cards of a non-manual block (reports only in dry-run).
     *
     * @param Entry[] $cards
     */
    private function _deleteStale(Entry $owner, array $cards): void
    {
        $this->stdout(sprintf(
            "  not manual, with stale nested cards: %s %d nested card(s) (#%s)\n",
            $this->dryRun ? 'would delete' : 'deleting',
            count($cards),
            implode(', #', array_map(fn(Entry $c) => $c->id, $cards)),
        ));
        if ($this->dryRun) {
            return;
        }

        $this->_ensureRevision($owner);

        $elements    = Craft::$app->getElements();
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            foreach ($cards as $card) {
                if (!$elements->deleteElement($card)) {
                    throw new RuntimeException("could not delete nested card #{$card->id}");
                }
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * Plain input array for CardMigrationMapper::cardFields() from a nested card.
     *
     * @return array<string, mixed>
     */
    private function _cardInput(Entry $card, int $position): array
    {
        $layout = $card->getFieldLayout();
        $has    = fn(string $handle): bool => (bool)$layout?->getFieldByHandle($handle);

        $link       = $has('cardLink') ? $card->getFieldValue('cardLink') : null;
        $serialized = $has('cardLink') ? ($card->getSerializedFieldValues(['cardLink'])['cardLink'] ?? []) : [];
        $linked     = is_object($link) && method_exists($link, 'first') ? $link->first()?->getElement() : null;

        if (!$has('cardTitle')) {
            $this->_warnOnce("nested type '{$this->_innerType->handle}' has no 'cardTitle' field; titles fall back to linked entry title or 'Card N'");
        }

        $input = [
            'titleHtml'   => $has('cardTitle') ? (string)$card->getFieldValue('cardTitle') : '',
            'textHtml'    => $has('cardText') ? (string)$card->getFieldValue('cardText') : '',
            'linkedTitle' => $linked?->title,
            'position'    => $position,
            'imageIds'    => $has('cardImage') ? $card->getFieldValue('cardImage')->ids() : [],
            'link'        => is_array($serialized) ? $serialized : [],
            'enabled'     => (bool)$card->enabled,
            'note'        => '',
        ];

        if ($has('useEntryCardDetails') && $card->getFieldValue('useEntryCardDetails') && $linked instanceof Entry) {
            $linkedLayout = $linked->getFieldLayout();
            $input['titleHtml'] = $linkedLayout?->getFieldByHandle('cardTitle')
                ? (string)$linked->getFieldValue('cardTitle')
                : (string)$linked->title;
            $input['textHtml'] = $linkedLayout?->getFieldByHandle('cardText') ? (string)$linked->getFieldValue('cardText') : '';
            $input['imageIds'] = $linkedLayout?->getFieldByHandle('cardImage') ? $linked->getFieldValue('cardImage')->ids() : [];
            $input['note']     = "useEntryCardDetails: snapshot of entry #{$linked->id}";
        }

        if ($has('showCustomButton') && $card->getFieldValue('showCustomButton')) {
            $input['note'] = trim($input['note'] . ' showCustomButton on (dropped)');
        }

        return $input;
    }

    private array $_warned = [];

    private function _warnOnce(string $message): void
    {
        if (isset($this->_warned[$message])) {
            return;
        }
        $this->_warned[$message] = true;
        $this->stderr("  WARNING: $message\n", Console::FG_YELLOW);
    }

    /**
     * First admin user id, used when the owner entry has no author.
     */
    private function _fallbackAuthor(): ?int
    {
        return $this->_fallbackAuthorId ??= \craft\elements\User::find()->admin()->status(null)->orderBy('elements.id')->ids()[0] ?? null;
    }

    /**
     * @param \yii\db\Transaction|null $transaction
     */
    private function _fail($transaction, string $what, Entry $entry): null
    {
        $transaction?->rollBack();
        $this->stderr("  FAILED saving $what; rolled back\n", Console::FG_RED);
        foreach ($entry->getErrors() as $attribute => $errors) {
            $this->stderr("    $attribute: " . implode('; ', $errors) . "\n", Console::FG_RED);
        }

        return null;
    }

    /**
     * @param array{skipped: array<string, int>} $stats
     */
    private function _skip(array &$stats, string $reason, string $message): void
    {
        $stats['skipped'][$reason] = ($stats['skipped'][$reason] ?? 0) + 1;
        $this->stdout("  skipped: $message\n", Console::FG_YELLOW);
    }
}

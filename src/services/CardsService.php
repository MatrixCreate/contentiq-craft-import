<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use Throwable;
use yii\base\Component;

/**
 * Writes inline Cards-block cards as STANDALONE entries in a channel section
 * (default `cards` / `standaloneCard`) and keeps them idempotent across
 * re-syncs.
 *
 * Identity is (ContentIQ page id, payload block id, position) → element id in
 * `contentiq_card_syncs`. The payload carries no per-card id, so position is
 * the third key part. Modelled on the CTA entry map
 * (`ImportService::_resolveCtaEntry()` / `contentiq_cta_syncs`), with one
 * addition the CTA path doesn't need: pruning. Cards beyond the new count,
 * and cards of blocks that left the payload, are deleted (Craft soft-delete)
 * — deleted, not disabled.
 *
 * Cards are saved WITH validation (unlike page entries): a failed save is
 * surfaced as a warning, never forced through. See docs/block-mapping.md
 * "Cards block".
 *
 * Every write method honours `$dryRun`: reads only, planned counts returned.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.38.0
 */
class CardsService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Memoized {@see isAvailable()} answers, keyed `section:entryType`.
     *
     * @var array<string, bool>
     */
    private array $_available = [];

    /**
     * Memoized owner-page author ids, keyed by ContentIQ page id (null = none).
     *
     * @var array<int, int|null>
     */
    private array $_ownerAuthors = [];

    /** First admin user id, resolved once per run (false = not looked up yet). */
    private int|null|false $_adminId = false;

    // Public Methods
    // =========================================================================

    /**
     * Whether the Cards section and entry type both exist, and the entry type
     * belongs to the section. Resolved once per run per handle pair.
     *
     * @param string $sectionHandle
     * @param string $entryTypeHandle
     * @return bool
     */
    public function isAvailable(string $sectionHandle, string $entryTypeHandle): bool
    {
        $key = "{$sectionHandle}:{$entryTypeHandle}";

        if (isset($this->_available[$key])) {
            return $this->_available[$key];
        }

        try {
            $entries   = Craft::$app->getEntries();
            $section   = $entries->getSectionByHandle($sectionHandle);
            $entryType = $entries->getEntryTypeByHandle($entryTypeHandle);
            $available = false;

            if ($section !== null && $entryType !== null) {
                foreach ($section->getEntryTypes() as $sectionType) {
                    if ($sectionType->id === $entryType->id) {
                        $available = true;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            Craft::warning("ContentIQ could not resolve the Cards section: {$e->getMessage()}", __METHOD__);
            $available = false;
        }

        return $this->_available[$key] = $available;
    }

    /**
     * Picks the author for new cards: the owner page's author, else the first
     * admin. Pure, so the precedence is unit-testable.
     *
     * @param int|null $ownerAuthorId
     * @param int|null $adminId
     * @return int|null Null when neither exists (new cards cannot be saved).
     */
    public static function chooseAuthorId(?int $ownerAuthorId, ?int $adminId): ?int
    {
        return $ownerAuthorId ?: ($adminId ?: null);
    }

    /**
     * Resolves the author for new cards of a page. Queue jobs and the console
     * have no logged-in identity, so Entry's default-author logic sets none and
     * a section with minAuthors >= 1 fails validation. Mirrors
     * CardsController::_fallbackAuthor().
     *
     * @return int|null
     */
    public function resolveAuthorId(int $pageId): ?int
    {
        if (!array_key_exists($pageId, $this->_ownerAuthors)) {
            $ownerId = (new Query())
                ->select(['element_id'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['contentiq_page_id' => $pageId])
                ->scalar();

            $this->_ownerAuthors[$pageId] = $ownerId
                ? (Entry::find()->id((int)$ownerId)->status(null)->one()?->getAuthorId() ?: null)
                : null;
        }

        if ($this->_adminId === false) {
            $this->_adminId = \craft\elements\User::find()->admin()->status(null)->orderBy('elements.id')->ids()[0] ?? null;
        }

        return self::chooseAuthorId($this->_ownerAuthors[$pageId], $this->_adminId);
    }

    /**
     * Creates/updates one standalone entry per spec for a (page, block), then
     * prunes mapped cards at positions beyond the new count.
     *
     * @param int    $pageId  ContentIQ document.id.
     * @param string $blockId Payload block id (or the synthetic `cards-N`).
     * @param array<int, array{title: string, fields: array<string, mixed>}> $specs Ordered card specs.
     * @param array  $cfg     The `cards.standalone` mapping (section / entryType).
     * @param bool   $dryRun
     * @return array{ids: int[], created: int, updated: int, deleted: int, warnings: string[]}
     */
    public function sync(int $pageId, string $blockId, array $specs, array $cfg, bool $dryRun): array
    {
        $out = ['ids' => [], 'created' => 0, 'updated' => 0, 'deleted' => 0, 'warnings' => []];

        $sectionHandle   = (string)($cfg['section'] ?? 'cards');
        $entryTypeHandle = (string)($cfg['entryType'] ?? 'standaloneCard');

        $mapped = $this->_mappedRows($pageId, $blockId);
        $specs  = array_values($specs);

        // Needed only when something will actually be created.
        $authorId = null;
        if (!$dryRun) {
            $authorId = $this->resolveAuthorId($pageId);
        }
        $authorWarned = false;

        foreach ($specs as $position => $spec) {
            $title  = (string)$spec['title'];
            $fields = $spec['fields'] ?? [];

            $existing = isset($mapped[$position])
                ? $this->_findCard((int)$mapped[$position]['element_id'], $sectionHandle, $entryTypeHandle)
                : null;

            if ($dryRun) {
                if ($existing !== null) {
                    $out['updated']++;
                    $out['ids'][] = $existing->id;
                } else {
                    $out['created']++;
                }
                continue;
            }

            if ($existing !== null) {
                $existing->title = $title;
                $existing->setFieldValues($fields);

                if (!Craft::$app->getElements()->saveElement($existing)) {
                    $out['warnings'][] = $this->_saveWarning('update', $position, $title, $existing);
                } else {
                    $out['updated']++;
                }

                // Keep the relation even when the update failed: the entry
                // still exists and the previous content is better than none.
                $out['ids'][] = $existing->id;
                $this->_upsertMap($pageId, $blockId, $position, $existing->id);
                continue;
            }

            $section   = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
            $entryType = Craft::$app->getEntries()->getEntryTypeByHandle($entryTypeHandle);

            $entry            = new Entry();
            $entry->sectionId = $section->id;
            $entry->typeId    = $entryType->id;
            $entry->siteId    = Craft::$app->getSites()->getPrimarySite()->id;
            $entry->title     = $title;

            if ($authorId === null) {
                if (!$authorWarned) {
                    $out['warnings'][] = "Cannot create cards for block '{$blockId}': no author available (the page entry has no author and there is no admin user).";
                    $authorWarned = true;
                }
                continue;
            }

            $entry->setAuthorId($authorId);
            $entry->setFieldValues($fields);

            if (!Craft::$app->getElements()->saveElement($entry)) {
                $out['warnings'][] = $this->_saveWarning('create', $position, $title, $entry);
                continue;
            }

            $out['created']++;
            $out['ids'][] = $entry->id;
            $this->_upsertMap($pageId, $blockId, $position, $entry->id);
        }

        $pruned = $this->pruneBlock($pageId, $blockId, count($specs), $cfg, $dryRun);
        $out['deleted'] += $pruned['deleted'];
        $out['warnings'] = array_merge($out['warnings'], $pruned['warnings']);

        return $out;
    }

    /**
     * Deletes mapped cards of one (page, block) at positions >= $keep.
     * `$keep = 0` removes every card the block owns.
     *
     * @return array{deleted: int, warnings: string[]}
     */
    public function pruneBlock(int $pageId, string $blockId, int $keep, array $cfg, bool $dryRun): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_card_syncs}}')
            ->where(['page_id' => $pageId, 'block_id' => $blockId])
            ->andWhere(['>=', 'position', $keep])
            ->all();

        return $this->_deleteRows($rows, $cfg, $dryRun);
    }

    /**
     * Deletes mapped cards of every block of $pageId whose block id is NOT in
     * $presentBlockIds (blocks that left the payload or are no longer cards).
     *
     * @param int      $pageId
     * @param string[] $presentBlockIds Block ids of the page's cards blocks in the current payload.
     * @return array{deleted: int, warnings: string[]}
     */
    public function pruneStale(int $pageId, array $presentBlockIds, array $cfg, bool $dryRun): array
    {
        $query = (new Query())
            ->from('{{%contentiq_card_syncs}}')
            ->where(['page_id' => $pageId]);

        if ($presentBlockIds !== []) {
            $query->andWhere(['not in', 'block_id', array_values($presentBlockIds)]);
        }

        return $this->_deleteRows($query->all(), $cfg, $dryRun);
    }

    // Private Methods
    // =========================================================================

    /**
     * Mapping rows for one (page, block), keyed by position.
     *
     * @return array<int, array>
     */
    private function _mappedRows(int $pageId, string $blockId): array
    {
        $rows = (new Query())
            ->from('{{%contentiq_card_syncs}}')
            ->where(['page_id' => $pageId, 'block_id' => $blockId])
            ->all();

        $byPosition = [];
        foreach ($rows as $row) {
            $byPosition[(int)$row['position']] = $row;
        }

        return $byPosition;
    }

    /**
     * Loads a live standalone card by id, or null when it's gone, trashed, or
     * not a card of the configured section/type (a stale or poisoned mapping).
     */
    private function _findCard(int $elementId, string $sectionHandle, string $entryTypeHandle): ?Entry
    {
        return Entry::find()
            ->id($elementId)
            ->section($sectionHandle)
            ->type($entryTypeHandle)
            ->status(null)
            ->one();
    }

    /**
     * Soft-deletes the elements behind mapping rows and removes the rows.
     * Soft-deleted elements don't cascade the FK, so rows are removed
     * explicitly. Dry run counts only elements that are live cards.
     *
     * @param array[] $rows
     * @return array{deleted: int, warnings: string[]}
     */
    private function _deleteRows(array $rows, array $cfg, bool $dryRun): array
    {
        $deleted  = 0;
        $warnings = [];

        $sectionHandle   = (string)($cfg['section'] ?? 'cards');
        $entryTypeHandle = (string)($cfg['entryType'] ?? 'standaloneCard');

        foreach ($rows as $row) {
            $card = $this->_findCard((int)$row['element_id'], $sectionHandle, $entryTypeHandle);

            if ($dryRun) {
                if ($card !== null) {
                    $deleted++;
                }
                continue;
            }

            if ($card !== null) {
                try {
                    if (Craft::$app->getElements()->deleteElement($card)) {
                        $deleted++;
                    } else {
                        $warnings[] = "Could not delete stale card #{$card->id}.";
                        continue; // keep the row so the next sync retries
                    }
                } catch (Throwable $e) {
                    $warnings[] = "Could not delete stale card #{$card->id}: {$e->getMessage()}";
                    continue;
                }
            }

            Craft::$app->getDb()->createCommand()
                ->delete('{{%contentiq_card_syncs}}', ['id' => $row['id']])
                ->execute();
        }

        return ['deleted' => $deleted, 'warnings' => $warnings];
    }

    private function _saveWarning(string $verb, int $position, string $title, Entry $entry): string
    {
        $errors = implode('; ', $entry->getFirstErrors());
        Craft::warning("ContentIQImporter: card {$verb} failed for '{$title}': {$errors}", __METHOD__);

        return sprintf("Could not %s card %d ('%s'): %s", $verb, $position + 1, $title, $errors);
    }

    /**
     * Upserts a contentiq_card_syncs row ((page_id, block_id, position) → element_id).
     */
    private function _upsertMap(int $pageId, string $blockId, int $position, int $elementId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db  = Craft::$app->getDb();
        $key = ['page_id' => $pageId, 'block_id' => $blockId, 'position' => $position];

        $exists = (new Query())->from('{{%contentiq_card_syncs}}')->where($key)->exists();

        if ($exists) {
            $db->createCommand()->update(
                '{{%contentiq_card_syncs}}',
                ['element_id' => $elementId, 'dateUpdated' => $now],
                $key,
            )->execute();

            return;
        }

        $db->createCommand()->insert('{{%contentiq_card_syncs}}', $key + [
            'element_id'  => $elementId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid'         => StringHelper::UUID(),
        ])->execute();
    }
}

<?php

namespace matrixcreate\contentiqimporter\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Db;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\StructureOrder;
use yii\console\ExitCode;

/**
 * One-time Structure-order repair for sites that were syncing before
 * ContentIQ's `sort_order` was persisted.
 *
 * Before `sort_order` was tracked, `SyncJob`/`CpController` always
 * `append()`ed a page to the end of its siblings on every run — so any page
 * written in a later, partial sync landed last, even though ContentiQ's own
 * export carries a stable per-parent sibling order. This command re-fetches
 * the whole-project export and repositions every already-synced page to
 * match it, using the exact same {@see \matrixcreate\contentiqimporter\services\ImportService::positionInStructure()}
 * logic every sync now uses — a one-time catch-up, not an ongoing behaviour.
 *
 * Deliberately narrow — this command does NOT:
 *   - write any page content (no `importPage()` call at all);
 *   - acknowledge pages with ContentiQ (no `ackPages()` call);
 *   - touch `contentiq_entry_syncs.locked` in any way, including for a page
 *     that has no sync row yet — writing one (even with `locked` at its
 *     column default) would flip an implicitly-locked entry (missing row ⇒
 *     locked, the safe default everywhere else in this plugin) to
 *     explicitly unlocked. `sort_order` is therefore only ever UPDATEd on an
 *     existing row, never INSERTed as a new one;
 *   - reposition (or write `sort_order` for) a page whose `parent_slug` is
 *     set but doesn't resolve to a Craft entry. `SyncJob`/`CpController`'s
 *     import path saves that page at root with a warning — correct there,
 *     because the page is being written for the first time and "somewhere"
 *     beats "nowhere." This is a repair tool acting on pages that already
 *     have a real position (and possibly a whole subtree beneath them) — it
 *     SKIPs them instead of silently relocating that subtree to root.
 *
 * Collection children (`document.content_type` set — no Structure) and the
 * homepage (`document.is_homepage` — a Single, never positioned) are skipped
 * entirely, same as every other sync entry point.
 *
 * Usage:
 *   php craft contentiq-importer/structure/reorder
 *   php craft contentiq-importer/structure/reorder --dry-run
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.34.0
 */
class StructureController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    public $defaultAction = 'reorder';

    /**
     * @var bool Whether to report what would move without writing anything
     *           (no Structure moves, no `sort_order` writes).
     */
    public bool $dryRun = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'dryRun';

        return $options;
    }

    /**
     * Repositions every existing ContentIQ-synced Structure page to match
     * ContentiQ's current `sort_order`, and backfills `sort_order` onto its
     * `contentiq_entry_syncs` row (existing rows only — see class docblock).
     * A page whose `parent_slug` doesn't resolve to a Craft entry is SKIPped
     * — never repositioned to root — see class docblock.
     *
     * @return int
     */
    public function actionReorder(): int
    {
        $plugin = ContentIQImporter::$plugin;

        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;

        if ($structureId === null) {
            $this->stderr("Could not resolve the Pages section's Structure — check the 'section' config setting.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $apiResult = $plugin->api->fetchExport();

        if (!$apiResult['success']) {
            $this->stderr('API request failed: ' . ($apiResult['error'] ?? 'unknown error') . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = $apiResult['data'];

        if (!is_array($data)) {
            $this->stderr("Malformed export: expected an object.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        if ($this->dryRun) {
            $this->stdout("[DRY RUN] Nothing will be written.\n\n", Console::FG_YELLOW, Console::BOLD);
        }

        $moved     = 0;
        $unchanged = 0;
        $skipped   = 0;
        // Parent slug → Craft entry ID, built incrementally as pages process
        // — mirrors SyncJob/CpController's own in-run map, relying on the
        // export's parents-before-children ordering the same way they do.
        $slugToEntryId = [];

        foreach ($pages as $pageData) {
            if (!is_array($pageData)) {
                $skipped++;
                continue;
            }

            $document    = $pageData['document'] ?? [];
            $slug        = (string)($document['slug'] ?? '');
            $isHomepage  = (bool)($document['is_homepage'] ?? false);
            $contentType = $document['content_type'] ?? null;

            // Collection children have no Structure; the homepage Single is
            // never positioned in one either — out of scope for both.
            if ($contentType !== null || $isHomepage) {
                continue;
            }

            $entry = $plugin->imports->findExistingEntry($pageData);

            if ($entry === null) {
                $skipped++;
                $this->stdout("  SKIP       {$slug} — no matching Craft entry found.\n", Console::FG_GREY);
                continue;
            }

            $parentSlug = $document['parent_slug'] ?? null;
            $parentId   = $this->_resolveParentId($parentSlug, $sectionHandle, $slugToEntryId);

            if ($slug !== '') {
                $slugToEntryId[$slug] = $entry->id;
            }

            // A repair tool must never guess. SyncJob/CpController's import
            // path saves an unresolved parent_slug at root with a warning —
            // correct there, because "somewhere" beats "nowhere" for a page
            // being written for the first time. Here the page (and its whole
            // subtree, if any) already has a real position; silently moving
            // it to root would be a destructive relocation with no signal
            // other than a log line. Skip it entirely instead — no move, no
            // sort_order write — and let the next real sync (which DOES
            // resolve parents depth-first within the same run) fix it.
            if ($parentSlug !== null && $parentSlug !== '' && $parentId === null) {
                $skipped++;
                $this->stdout("  SKIP       {$slug} — parent '{$parentSlug}' not found in Craft.\n", Console::FG_GREY);
                continue;
            }

            $sortOrder = (int)($document['sort_order'] ?? 0);
            $pageId    = isset($document['id']) ? (int)$document['id'] : null;

            $prediction = $this->_predictMove($entry, $parentId, $structureId, $sortOrder, $pageId);

            if (!$prediction['wouldMove']) {
                $unchanged++;
                $this->stdout("  UNCHANGED  {$slug}\n");
                continue;
            }

            $moved++;
            $this->stdout("  MOVED      {$slug}\n", Console::FG_GREEN);

            if ($this->dryRun) {
                continue;
            }

            $warnings = [];
            $plugin->imports->positionInStructure(
                entry: $entry,
                parentId: $parentId,
                structureId: $structureId,
                sortOrder: $sortOrder,
                contentiqPageId: $pageId,
                warnings: $warnings,
            );

            foreach ($warnings as $warning) {
                $this->stdout("             {$warning}\n", Console::FG_YELLOW);
            }

            // Update-only — see class docblock for why this never inserts.
            Craft::$app->getDb()->createCommand()
                ->update('{{%contentiq_entry_syncs}}', ['sort_order' => $sortOrder], ['element_id' => $entry->id])
                ->execute();
        }

        $this->stdout("\n" . ($this->dryRun ? 'Would move' : 'Moved') . ": {$moved}, unchanged: {$unchanged}, skipped: {$skipped}\n", Console::BOLD);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a `parent_slug` to a Craft entry id, trying the in-run map
     * first and falling back to a live Craft query — the same two-step
     * resolution `SyncJob`/`CpController` use for hierarchy.
     *
     * @param string|null         $parentSlug
     * @param string              $sectionHandle
     * @param array<string, int>  $slugToEntryId
     * @return int|null
     */
    private function _resolveParentId(?string $parentSlug, string $sectionHandle, array $slugToEntryId): ?int
    {
        if ($parentSlug === null || $parentSlug === '') {
            return null;
        }

        if (isset($slugToEntryId[$parentSlug])) {
            return $slugToEntryId[$parentSlug];
        }

        return Entry::find()
            ->section($sectionHandle)
            ->slug(Db::escapeParam($parentSlug))
            ->status(null)
            ->one()
            ?->id;
    }

    /**
     * Predicts whether {@see \matrixcreate\contentiqimporter\services\ImportService::positionInStructure()}
     * would actually move `$entry`, without writing anything — read-only
     * twin of that method's own sibling lookup, plus a comparison against
     * the entry's current next sibling.
     *
     * @param Entry    $entry
     * @param int|null $parentId
     * @param int      $structureId
     * @param int      $sortOrder
     * @param int|null $pageId
     * @return array{wouldMove: bool}
     */
    private function _predictMove(Entry $entry, ?int $parentId, int $structureId, int $sortOrder, ?int $pageId): array
    {
        $siblingsQuery = Entry::find()
            ->structureId($structureId)
            ->status(null)
            ->orderBy(['structureelements.lft' => SORT_ASC]);

        if ($parentId !== null) {
            $siblingsQuery->descendantOf($parentId)->descendantDist(1);
        } else {
            $siblingsQuery->level(1);
        }

        // Ordered ids INCLUDING the target — used to read its current next
        // sibling (position it would need to move FROM).
        $orderedIds  = $siblingsQuery->ids();
        $currentIdx  = array_search($entry->id, $orderedIds, true);
        $currentNext = $currentIdx !== false ? ($orderedIds[$currentIdx + 1] ?? null) : null;

        // Same ids, excluding the target — the shape StructureOrder::insertBeforeId() expects.
        $siblingIds = array_values(array_filter($orderedIds, fn(int $id): bool => $id !== $entry->id));

        $syncRows = empty($siblingIds)
            ? []
            : (new Query())
                ->select(['element_id', 'sort_order', 'contentiq_page_id'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $siblingIds])
                ->indexBy('element_id')
                ->all();

        $siblings = array_map(
            fn(int $siblingId): array => [
                'id'        => $siblingId,
                'sortOrder' => isset($syncRows[$siblingId]['sort_order']) ? (int)$syncRows[$siblingId]['sort_order'] : null,
                'pageId'    => isset($syncRows[$siblingId]['contentiq_page_id']) ? (int)$syncRows[$siblingId]['contentiq_page_id'] : null,
            ],
            $siblingIds,
        );

        $targetBeforeId = StructureOrder::insertBeforeId($siblings, $sortOrder, $pageId);

        return ['wouldMove' => $targetBeforeId !== $currentNext];
    }
}

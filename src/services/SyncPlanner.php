<?php

namespace matrixcreate\contentiqimporter\services;

use yii\base\Component;

/**
 * Plans a batched sync run from a decoded ContentIQ export envelope.
 *
 * Pure — makes no Craft:: calls and touches no database. `plan()` ports the
 * envelope validation and batch-vs-single detection SyncJob performs inline
 * (SyncJob.php ~lines 118-145) into a standalone step so StageRunJob can
 * write one contentiq_sync_pages row per page without holding the whole
 * decoded export in scope for longer than one pass. See
 * CRAFT-IMPORT-QUEUE-SPEC.md §5.1 (W1) and SyncRunService::stageRun(),
 * which persists this method's output.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.35.0
 */
class SyncPlanner extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Plans a run from a decoded export envelope.
     *
     * Batch-vs-single detection matches SyncJob exactly: `pages` present and
     * itself an array means batch (each element validated individually);
     * otherwise the whole envelope is treated as a single page. A page
     * entry is malformed — planned with `status => 'skipped_malformed'`,
     * `payload => null`, and a warning — in the same two cases SyncJob's
     * pipeline treats as unimportable: the entry isn't an array at all, or
     * (mirroring ImportService::importPage()'s own validation) it's missing
     * `document.slug` and isn't the homepage (`document.is_homepage` is the
     * one legitimate reason a page carries no slug).
     *
     * Duplicate-slug detection: the first page to use a given non-blank slug
     * in this run keeps `duplicate_slug => false`; every later page reusing
     * that slug gets `true`. A blank slug (homepage) is never flagged. This
     * is NOT the same rule as SyncJob's own `$isDuplicateSlug` (~line 220),
     * which only flags a collision against a slug already recorded in
     * `$slugToEntryId` — populated only as pages are actually imported, so a
     * locked/deselected/failed page never lands in it and SyncJob can
     * under-detect a duplicate a later page's slug collides with. The
     * planner's rule — flag against every non-blank slug SEEN in the export,
     * regardless of what happens to the page carrying it — is the intended,
     * stricter rule (CRAFT-IMPORT-QUEUE-SPEC.md §3.2), not a
     * re-implementation of SyncJob's.
     *
     * @param array $decoded The decoded export envelope (single-page shape,
     *   or `{pages: [...], globals: {...}, pulled_at|exported_at: ...}`).
     * @return array{
     *   pages: array<int, array{position: int, contentiq_page_id: int|null, slug: string|null, parent_slug: string|null, depth: int, is_homepage: bool, content_type: string|null, duplicate_slug: bool, payload: array|null, status: string}>,
     *   globals: array|null,
     *   warnings: string[],
     *   skippedMalformed: int,
     *   shape: 'batch'|'single',
     *   state: array{homepageSlug: string|null, pulledAt: mixed}
     * }
     */
    public function plan(array $decoded): array
    {
        $isBatch  = isset($decoded['pages']) && is_array($decoded['pages']);
        $rawPages = $isBatch ? $decoded['pages'] : [$decoded];
        $shape    = $isBatch ? 'batch' : 'single';

        $pages            = [];
        $warnings         = [];
        $skippedMalformed = 0;
        $seenSlugs        = [];
        $homepageSlug     = null;

        foreach (array_values($rawPages) as $position => $pageData) {
            if (!is_array($pageData)) {
                $skippedMalformed++;
                $warnings[] = "Page at position {$position} in the export was malformed (not an object) and was skipped.";

                $pages[] = $this->_skippedMalformedPage($position);

                continue;
            }

            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);
            $rawSlug    = $pageData['document']['slug'] ?? '';
            $slug       = (string)$rawSlug;

            // Mirrors ImportService::importPage()'s own validation — a blank
            // slug is only ever legitimate on the homepage, which resolves
            // by section rather than slug.
            if (!$isHomepage && trim($slug) === '') {
                $skippedMalformed++;
                $warnings[] = "Page at position {$position} in the export was missing document.slug and was skipped.";

                $pages[] = $this->_skippedMalformedPage(
                    position: $position,
                    contentiqPageId: isset($pageData['document']['id']) ? (int)$pageData['document']['id'] : null,
                    parentSlug: $pageData['document']['parent_slug'] ?? null,
                    depth: (int)($pageData['document']['depth'] ?? 0),
                    contentType: $pageData['document']['content_type'] ?? null,
                );

                continue;
            }

            // Second and later occurrence of a non-blank slug in this run —
            // surfaced as a flag, not skipped (SyncJob's own "last one wins"
            // import behaviour is unchanged; this just makes the collision
            // visible on the report).
            $isDuplicate = $slug !== '' && isset($seenSlugs[$slug]);

            if ($slug !== '') {
                $seenSlugs[$slug] = true;
            }

            if ($isHomepage && $homepageSlug === null) {
                $homepageSlug = $slug;
            }

            $pages[] = [
                'position'          => $position,
                'contentiq_page_id' => isset($pageData['document']['id']) ? (int)$pageData['document']['id'] : null,
                'slug'              => $slug,
                'parent_slug'       => $pageData['document']['parent_slug'] ?? null,
                'depth'             => (int)($pageData['document']['depth'] ?? 0),
                'is_homepage'       => $isHomepage,
                'content_type'      => $pageData['document']['content_type'] ?? null,
                'duplicate_slug'    => $isDuplicate,
                'payload'           => $pageData,
                'status'            => 'pending',
            ];
        }

        $globals  = (isset($decoded['globals']) && is_array($decoded['globals'])) ? $decoded['globals'] : null;
        $pulledAt = $decoded['pulled_at'] ?? $decoded['exported_at'] ?? null;

        return [
            'pages'            => $pages,
            'globals'          => $globals,
            'warnings'         => $warnings,
            'skippedMalformed' => $skippedMalformed,
            'shape'            => $shape,
            'state'            => [
                'homepageSlug' => $homepageSlug,
                'pulledAt'     => $pulledAt,
            ],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a planned page row for a malformed entry.
     *
     * @param int         $position
     * @param int|null    $contentiqPageId
     * @param string|null $parentSlug
     * @param int         $depth
     * @param string|null $contentType
     * @return array
     */
    private function _skippedMalformedPage(
        int $position,
        ?int $contentiqPageId = null,
        ?string $parentSlug = null,
        int $depth = 0,
        ?string $contentType = null,
    ): array {
        return [
            'position'          => $position,
            'contentiq_page_id' => $contentiqPageId,
            'slug'              => null,
            'parent_slug'       => $parentSlug,
            'depth'             => $depth,
            'is_homepage'       => false,
            'content_type'      => $contentType,
            'duplicate_slug'    => false,
            'payload'           => null,
            'status'            => 'skipped_malformed',
        ];
    }
}

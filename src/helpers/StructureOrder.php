<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Pure sibling-ordering logic for ContentIQ structure positioning.
 *
 * ContentIQ carries a per-parent sibling order on every export `document`
 * (`sort_order`, an int; ties broken by `id`). This class contains the pure
 * comparison logic {@see ImportService::positionInStructure()} uses to find
 * where a page belongs among its already-positioned siblings — no Craft
 * dependency, so it's testable without booting a Craft instance.
 *
 * Pure and Craft-free — safe to unit test without booting Craft.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.34.0
 */
class StructureOrder
{
    // Public Methods
    // =========================================================================

    /**
     * Finds the id of the first sibling that should come after the target
     * page, given the target's own `(sortOrder, pageId)` and an ordered list
     * of its current siblings.
     *
     * `$siblings` must already be in structure order (current position),
     * each entry shaped `['id' => int, 'sortOrder' => ?int, 'pageId' => ?int]`.
     * A sibling with a null `sortOrder` is a Craft-only entry with no
     * ContentIQ ordering data (or one that predates this column) — it's
     * skipped entirely rather than treated as "lowest", so it keeps its
     * existing place instead of being shoved to the front on every sync.
     *
     * Comparison is `(sortOrder, pageId)` lexicographic — `sortOrder` first,
     * `pageId` breaking ties, matching ContentIQ's own export ordering rule.
     *
     * @param array<int, array{id: int, sortOrder: ?int, pageId: ?int}> $siblings Ordered siblings, excluding the target itself.
     * @param int      $sortOrder The target page's `document.sort_order`.
     * @param int|null $pageId    The target page's ContentIQ `document.id`.
     * @return int|null The id of the sibling to insert before, or null to append at the end.
     */
    public static function insertBeforeId(array $siblings, int $sortOrder, ?int $pageId): ?int
    {
        foreach ($siblings as $sibling) {
            if ($sibling['sortOrder'] === null) {
                continue;
            }

            if (self::_isAfter($sibling['sortOrder'], $sibling['pageId'], $sortOrder, $pageId)) {
                return $sibling['id'];
            }
        }

        return null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether `(sortOrderA, pageIdA)` sorts strictly after `(sortOrderB, pageIdB)`.
     *
     * @param int      $sortOrderA
     * @param int|null $pageIdA
     * @param int      $sortOrderB
     * @param int|null $pageIdB
     * @return bool
     */
    private static function _isAfter(int $sortOrderA, ?int $pageIdA, int $sortOrderB, ?int $pageIdB): bool
    {
        if ($sortOrderA !== $sortOrderB) {
            return $sortOrderA > $sortOrderB;
        }

        return ($pageIdA ?? 0) > ($pageIdB ?? 0);
    }
}

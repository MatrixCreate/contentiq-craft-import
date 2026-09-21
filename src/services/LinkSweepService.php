<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\ContentBlock;
use craft\fields\Matrix;
use craft\htmlfield\HtmlField;
use craft\htmlfield\HtmlFieldData;
use matrixcreate\contentiqimporter\helpers\LinkRewriter;
use Throwable;
use yii\base\Component;

/**
 * PASS 3: Upgrades root-relative links written this run to live entry
 * references, once every page in the batch has an entry id to link to.
 *
 * Craft's own `HtmlField::serializeValue()` rewrite of `href` into a
 * reference tag only fires for a full site-base-URL href; ContentiQ exports
 * root-relative hrefs (`/parent/child`), which never match. `sweep()` is the
 * upgrade pass that catches those, plus the equivalent verbb Hyper `Url` →
 * `Entry` link upgrade — see {@see \matrixcreate\contentiqimporter\helpers\LinkRewriter}
 * for the pure rewrite logic this class drives.
 *
 * Runs at the same point in the pipeline as PASS 2
 * ({@see ImportService::resolveCardReferences()}) — see
 * {@see ImportService::runPostPasses()}, the entry point every caller uses —
 * after every page in the run has been imported (so every in-run slug has an
 * entry id to resolve to) and before the ack call and before auto-lock.
 *
 * Scope rule: only elements genuinely written THIS run are ever walked or
 * saved — the two id lists passed in are already filtered by
 * `ImportService::_writtenPages()` to successful, non-skipped, non-locked
 * pages. §3 below re-checks the lock anyway: it's a defensive backstop
 * against a caller passing a stale/wrong id, not a path this plugin's own
 * callers are expected to hit in normal operation.
 *
 * `LinkRewriter::rewriteHtml()`'s reference tag keeps the original
 * root-relative href as its `||` fallback text
 * (`{entry:ID@SITEID:url||/parent/child}`) rather than discarding it — if
 * the entry is later deleted, Craft renders the fallback text verbatim
 * instead of an empty/broken href, so an upgraded link degrades to exactly
 * today's (pre-upgrade) behaviour rather than breaking worse than doing
 * nothing would have.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.32.0
 */
class LinkSweepService extends Component
{
    // Constants
    // =========================================================================

    /**
     * How many levels deep (Matrix/ContentBlock nesting) the walk will
     * recurse before giving up on a branch. Defensive only — real content
     * never nests this deep — so a warning fires once per top-level target
     * rather than the walk being allowed to run away on a malformed/circular
     * field layout.
     */
    private const MAX_DEPTH = 6;

    // Public Methods
    // =========================================================================

    /**
     * PASS 3: Upgrades root-relative links written this run to live entry
     * references. Returns warnings keyed by owner page entry id.
     *
     * @param int[] $writtenEntryIds Craft entry IDs genuinely written this run.
     * @param int[] $writtenPageIds  ContentiQ page IDs genuinely written this run.
     * @param bool  $dryRun          If true, resolve and warn but write nothing.
     * @return array<int, string[]> Warnings keyed by owner entry ID.
     */
    public function sweep(array $writtenEntryIds, array $writtenPageIds, bool $dryRun = false): array
    {
        if (empty($writtenEntryIds) && empty($writtenPageIds)) {
            return [];
        }

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        // -----------------------------------------------------------------------
        // 1-2. Site id + memoised uri → entry id resolver. getElementByUri('')
        //      already maps to the homepage; enabledOnly=false so a disabled
        //      target still links (matches Craft's own reference-tag behaviour).
        // -----------------------------------------------------------------------
        $uriCache = [];
        $resolve  = function (string $uri) use ($siteId, &$uriCache): ?int {
            if (array_key_exists($uri, $uriCache)) {
                return $uriCache[$uri];
            }

            $el = Craft::$app->getElements()->getElementByUri($uri, $siteId, false);

            return $uriCache[$uri] = ($el instanceof Entry ? (int)$el->id : null);
        };

        // -----------------------------------------------------------------------
        // 3. Defensive lock filter. A written-this-run entry is unlocked at
        //    this point by construction (ImportService::_writtenPages() already
        //    excludes skippedLocked pages) — a row that comes back locked here
        //    means a caller passed the wrong id, not a real sync-time race.
        // -----------------------------------------------------------------------
        $writtenEntryIds = array_values(array_unique($writtenEntryIds));

        if (!empty($writtenEntryIds)) {
            $lockedIds = (new Query())
                ->select(['element_id'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['element_id' => $writtenEntryIds, 'locked' => true])
                ->column();

            foreach ($lockedIds as $lockedId) {
                Craft::warning("ContentIQImporter: link sweep skipped entry {$lockedId} — it came back "
                    . 'locked despite being passed in as written this run.', __METHOD__);
            }

            if (!empty($lockedIds)) {
                $lockedIds       = array_map('intval', $lockedIds);
                $writtenEntryIds = array_values(array_diff($writtenEntryIds, $lockedIds));
            }
        }

        // -----------------------------------------------------------------------
        // 4. Targets: entryId => owner page entry id. Every remaining written
        //    entry (a page) maps to itself, plus each CTA entry written this run
        //    maps to the page entry that owns it (falling back to the CTA's own
        //    id when that page isn't itself a resolvable written target).
        // -----------------------------------------------------------------------
        $targets = [];

        foreach ($writtenEntryIds as $entryId) {
            $targets[$entryId] = $entryId;
        }

        if (!empty($writtenPageIds)) {
            $pageToEntry = [];

            $entrySyncRows = (new Query())
                ->select(['contentiq_page_id', 'element_id'])
                ->from('{{%contentiq_entry_syncs}}')
                ->where(['contentiq_page_id' => $writtenPageIds])
                ->all();

            foreach ($entrySyncRows as $row) {
                $pageToEntry[(int)$row['contentiq_page_id']] = (int)$row['element_id'];
            }

            $ctaRows = (new Query())
                ->select(['page_id', 'element_id'])
                ->from('{{%contentiq_cta_syncs}}')
                ->where(['page_id' => $writtenPageIds])
                ->all();

            foreach ($ctaRows as $row) {
                $ctaEntryId   = (int)$row['element_id'];
                $ownerEntryId = $pageToEntry[(int)$row['page_id']] ?? null;

                // Only adopt the page as owner when it's itself a live target
                // (written this run, and survived the lock filter above) —
                // otherwise the CTA's own warnings would attach to an id this
                // sweep never otherwise reports against.
                $targets[$ctaEntryId] = ($ownerEntryId !== null && isset($targets[$ownerEntryId]))
                    ? $ownerEntryId
                    : $ctaEntryId;
            }
        }

        if (empty($targets)) {
            return [];
        }

        // -----------------------------------------------------------------------
        // 5-6. Walk + save each target, isolated per element like the rest of
        //      the import pipeline (see importPage()'s per-page try/catch) — one
        //      bad element never aborts the rest of the sweep.
        // -----------------------------------------------------------------------
        $warningsByOwner  = [];
        $resolvedByOwner  = [];
        $hyperHandleCache = [];

        // -----------------------------------------------------------------------
        // 4b. Preload every target entry in ONE query rather than one per
        //     target inside the loop below — an 88-page run was otherwise
        //     issuing 88 individual Entry::find() calls here. status(null) +
        //     indexBy('id') keep this query's own semantics identical to the
        //     per-target lookup it replaces (any status, element id => Entry);
        //     no siteId filter, matching what the per-target lookup did too.
        //     A failure of the query itself is reported against every owner
        //     page (mirroring RedirectService::sweep()) rather than escaping
        //     the per-element isolation the old per-target lookup enjoyed.
        // -----------------------------------------------------------------------
        try {
            $targetEntries = Entry::find()->id(array_keys($targets))->status(null)->indexBy('id')->all();
        } catch (Throwable $e) {
            $targetEntries = [];

            foreach ($targets as $ownerPageId) {
                $warningsByOwner[$ownerPageId][] = 'Could not load entry for the link sweep: ' . $e->getMessage();
            }
        }

        foreach ($targets as $entryId => $ownerPageId) {
            $warn = function (string $message) use (&$warningsByOwner, $ownerPageId): void {
                $warningsByOwner[$ownerPageId][] = $message;
            };

            try {
                $el = $targetEntries[$entryId] ?? null;

                if ($el === null) {
                    continue;
                }

                $ownWarnings   = [];
                $seenWarnings  = [];
                $resolvedCount = 0;

                $dirty = $this->_walkElement(
                    $el,
                    $resolve,
                    $siteId,
                    $dryRun,
                    $ownWarnings,
                    $seenWarnings,
                    $hyperHandleCache,
                    $resolvedCount,
                );

                foreach ($ownWarnings as $message) {
                    $warn($message);
                }

                if ($resolvedCount > 0) {
                    $resolvedByOwner[$ownerPageId] = ($resolvedByOwner[$ownerPageId] ?? 0) + $resolvedCount;
                }

                if ($dirty && !$dryRun) {
                    try {
                        if (!Craft::$app->getElements()->saveElement($el, false)) {
                            $warn('Could not save resolved links: ' . implode('; ', $el->getFirstErrors()));
                        }
                    } catch (Throwable $e) {
                        $warn('Could not save resolved links: ' . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $warn('Link sweep failed: ' . $e->getMessage());
                continue;
            }
        }

        foreach ($resolvedByOwner as $ownerPageId => $count) {
            Craft::info("ContentIQImporter: link sweep resolved {$count} link(s) for owner entry {$ownerPageId}.", __METHOD__);
        }

        return $warningsByOwner;
    }

    // Private Methods
    // =========================================================================

    /**
     * Walks one element's own field layout — HTML fields, Hyper fields,
     * and recursing into Matrix/ContentBlock nested elements — upgrading
     * every root-relative link it finds.
     *
     * Returns true only when $el's OWN direct field values changed (an HTML
     * field's stored content, or a Hyper field's link value) — a nested
     * Matrix/ContentBlock element that changed is saved from inside this
     * walk and never makes the parent dirty, so callers never re-save an
     * owner purely because one of its nested elements changed.
     *
     * @param ElementInterface $el                $el's own field layout is walked.
     * @param callable         $resolve           fn(string $uri): ?int — see {@see LinkRewriter::rewriteHtml()}.
     * @param int              $siteId
     * @param bool             $dryRun            If true, nested elements are walked/warned but never saved.
     * @param string[]         $warnings          By-ref accumulator for this target's warnings (unkeyed — the
     *                                             caller attaches them to the right owner).
     * @param array<string, true> $seenWarnings    By-ref dedupe set (exact warning text → true), local to one
     *                                             target's whole walk, so the same missing path on 5 nested
     *                                             blocks warns once.
     * @param array<int, ?string> $hyperHandleCache By-ref memo of Hyper field id → enabled Entry link-type
     *                                              handle (or null when the field has none), shared across the
     *                                              whole sweep() call since field configuration doesn't change
     *                                              mid-run.
     * @param int              $resolvedCount     By-ref running total of links upgraded on $el and everything
     *                                             beneath it.
     * @param int              $depth             Recursion depth — 0 at the top-level target.
     * @return bool
     */
    private function _walkElement(
        ElementInterface $el,
        callable $resolve,
        int $siteId,
        bool $dryRun,
        array &$warnings,
        array &$seenWarnings,
        array &$hyperHandleCache,
        int &$resolvedCount,
        int $depth = 0,
    ): bool {
        if ($depth > self::MAX_DEPTH) {
            $message = 'Link sweep stopped recursing past ' . self::MAX_DEPTH
                . ' levels of nested content — anything deeper was not checked for links.';

            if (!isset($seenWarnings[$message])) {
                $seenWarnings[$message] = true;
                $warnings[]             = $message;
            }

            return false;
        }

        $dirty = false;

        foreach ($el->getFieldLayout()?->getCustomFieldElements() ?? [] as $layoutElement) {
            $handle = $layoutElement->attribute();
            $field  = $layoutElement->getField();

            // -------------------------------------------------------------------
            // CKEditor/HTML fields — rewrite root-relative <a href>s in place.
            // -------------------------------------------------------------------
            if ($field instanceof HtmlField) {
                $value = $el->getFieldValue($handle);
                $raw   = $value instanceof HtmlFieldData
                    ? $value->getRawContent()
                    : (is_string($value) ? $value : null);

                if ($raw !== null && $raw !== '') {
                    $r = LinkRewriter::rewriteHtml($raw, $resolve, $siteId);

                    if ($r['html'] !== $raw) {
                        $el->setFieldValue($handle, $r['html']);
                        $dirty          = true;
                        $resolvedCount += $r['resolved'];
                    }

                    foreach ($r['unresolved'] as $path) {
                        $message = "Link '{$path}' did not resolve to an entry — left as a URL.";

                        if (!isset($seenWarnings[$message])) {
                            $seenWarnings[$message] = true;
                            $warnings[]             = $message;
                        }
                    }
                }

                // -----------------------------------------------------------------
                // CKEditor fields (craft\ckeditor\Field extends HtmlField) can also
                // carry nested entries — e.g. the `actionButtons` entries
                // textBlockNestedButtons creates (see
                // ImportService::_writeNestedActionButtons() and
                // docs/block-mapping.md "Text blocks — nested action buttons").
                // Recurse into each so a Hyper Url button inside one is upgraded
                // to an Entry link by this same pass, same as everywhere else a
                // button lives. class_exists() guards sites without
                // craftcms/ckeditor installed — never a composer dependency of
                // this plugin (see AGENTS.md) — same convention as the
                // ContentBlock branch below.
                // -----------------------------------------------------------------
                if (class_exists('craft\\ckeditor\\Field') && $field instanceof \craft\ckeditor\Field) {
                    $nested = Entry::find()->ownerId($el->id)->fieldId($field->id)->status(null)->all();

                    foreach ($nested as $child) {
                        $childDirty = $this->_walkElement(
                            $child,
                            $resolve,
                            $siteId,
                            $dryRun,
                            $warnings,
                            $seenWarnings,
                            $hyperHandleCache,
                            $resolvedCount,
                            $depth + 1,
                        );

                        if ($childDirty && !$dryRun) {
                            try {
                                if (!Craft::$app->getElements()->saveElement($child, false)) {
                                    $message = 'Could not save resolved links: ' . implode('; ', $child->getFirstErrors());

                                    if (!isset($seenWarnings[$message])) {
                                        $seenWarnings[$message] = true;
                                        $warnings[]             = $message;
                                    }
                                }
                            } catch (Throwable $e) {
                                $warnings[] = 'Could not save resolved links: ' . $e->getMessage();
                            }
                        }
                    }
                }

                continue;
            }

            // -------------------------------------------------------------------
            // verbb Hyper fields — upgrade a root-relative Url link to Entry.
            // Hyper is never a composer dependency of this plugin (see
            // AGENTS.md) — is_a() with a string class name resolves this
            // safely whether or not Hyper is installed on the target site.
            // -------------------------------------------------------------------
            if (is_a($field, 'verbb\\hyper\\fields\\HyperField')) {
                $collection = $el->getFieldValue($handle);
                $link       = $collection?->first();

                if ($link === null || !is_a($link, LinkRewriter::URL_LINK_TYPE)) {
                    continue;
                }

                $serialized = $link->getSerializedValues();
                $path       = LinkRewriter::hyperLinkPath($serialized);

                if ($path === null) {
                    continue;
                }

                $fieldId = $field->id;

                if (!array_key_exists($fieldId, $hyperHandleCache)) {
                    $entryHandle = null;

                    foreach ($field->getLinkTypes() as $linkType) {
                        if (is_a($linkType, LinkRewriter::ENTRY_LINK_TYPE) && $linkType->enabled) {
                            $entryHandle = $linkType->handle;
                            break;
                        }
                    }

                    $hyperHandleCache[$fieldId] = $entryHandle;
                }

                $entryHandle = $hyperHandleCache[$fieldId];

                // No enabled Entry link type on this field — a site config
                // choice, not a broken link. Nothing to upgrade to.
                if ($entryHandle === null) {
                    continue;
                }

                $new = LinkRewriter::upgradeHyperLink($serialized, $resolve, $entryHandle, $siteId);

                if ($new !== null) {
                    $el->setFieldValue($handle, [$new]);
                    $dirty = true;
                    $resolvedCount++;
                } else {
                    $message = "Button link '{$path}' did not resolve to an entry — left as a URL.";

                    if (!isset($seenWarnings[$message])) {
                        $seenWarnings[$message] = true;
                        $warnings[]             = $message;
                    }
                }

                continue;
            }

            // -------------------------------------------------------------------
            // Matrix — recurse into every nested entry. Each nested entry is
            // saved directly when ITS own fields changed; the owner ($el)
            // never gets marked dirty on account of a nested save.
            // -------------------------------------------------------------------
            if ($field instanceof Matrix) {
                $nested = $el->getFieldValue($handle)?->status(null)->all() ?? [];

                foreach ($nested as $child) {
                    $childDirty = $this->_walkElement(
                        $child,
                        $resolve,
                        $siteId,
                        $dryRun,
                        $warnings,
                        $seenWarnings,
                        $hyperHandleCache,
                        $resolvedCount,
                        $depth + 1,
                    );

                    if ($childDirty && !$dryRun) {
                        try {
                            if (!Craft::$app->getElements()->saveElement($child, false)) {
                                $message = 'Could not save resolved links: ' . implode('; ', $child->getFirstErrors());

                                if (!isset($seenWarnings[$message])) {
                                    $seenWarnings[$message] = true;
                                    $warnings[]             = $message;
                                }
                            }
                        } catch (Throwable $e) {
                            $warnings[] = 'Could not save resolved links: ' . $e->getMessage();
                        }
                    }
                }

                continue;
            }

            // -------------------------------------------------------------------
            // ContentBlock (Craft 5.8+) — same shape as Matrix, but the field
            // holds exactly one nested element instead of a list.
            // class_exists() guards sites on Craft <5.8, which has no such
            // class at all — is_a()/instanceof would just be false there, but
            // this makes the version dependency explicit rather than relying
            // on that behaviour incidentally.
            //
            // NOTE: saving a ContentBlock element individually (rather than
            // implicitly via its owner's own save) has no other precedent in
            // this plugin — add it to the live-validation checklist alongside
            // preserveBlockIdentity (see docs/block-mapping.md) before this
            // path is exercised against real hero/footer-CTA ContentBlock
            // fields in production.
            // -------------------------------------------------------------------
            if (class_exists(ContentBlock::class) && $field instanceof ContentBlock) {
                $block = $el->getFieldValue($handle);

                if ($block instanceof ElementInterface) {
                    $blockDirty = $this->_walkElement(
                        $block,
                        $resolve,
                        $siteId,
                        $dryRun,
                        $warnings,
                        $seenWarnings,
                        $hyperHandleCache,
                        $resolvedCount,
                        $depth + 1,
                    );

                    if ($blockDirty && !$dryRun) {
                        try {
                            if (!Craft::$app->getElements()->saveElement($block, false)) {
                                $message = 'Could not save resolved links: ' . implode('; ', $block->getFirstErrors());

                                if (!isset($seenWarnings[$message])) {
                                    $seenWarnings[$message] = true;
                                    $warnings[]             = $message;
                                }
                            }
                        } catch (Throwable $e) {
                            $warnings[] = 'Could not save resolved links: ' . $e->getMessage();
                        }
                    }
                }

                continue;
            }

            // Everything else (relations, assets, plain text, etc.) is
            // ignored. In particular, Entries/relations fields are never
            // followed — recursing into them would wander into unrelated
            // entries outside this run's scope.
        }

        return $dirty;
    }
}

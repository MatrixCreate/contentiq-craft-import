# ContentIQ Importer — Progress

## Read-only export acknowledgement + full-project Sync tree (2026-08-21)

ContentiQ's export GETs (`/api/v1/export`, `/api/v1/pages/{slug}/export`) are becoming side-effect-free — they no longer flip page statuses just by being fetched. The CMS now explicitly acknowledges pages it actually wrote, and the Sync screen shows ContentiQ's full project tree (not just what's already been imported) on load.

**Acknowledgement (`POST /api/v1/pages/ack`):**
- `ContentIQApiService::ackPages(int[] $pageIds)` — Bearer-authed POST with `{"page_ids": [...]}`, 15s timeout. Empty input is a no-op (no request). Any failure (unconfigured API, network error, non-2xx, malformed JSON) is reported via `success`/`error` but never throws — always non-fatal to the caller.
- `SyncJob` collects the ContentiQ page id of every page result that actually wrote this run (`success`, not `skippedLocked`, not ImportService's own non-fatal `skipped`, has an `entryId`, carries a `contentiqPageId`) and makes ONE batch ack call after PASS 2 (card refs). A failure appends a warning to the run result (`result.ackWarning`, new sibling of `pages`/`globals`) and flips the run status to `warnings` — rendered as a small banner on the sync report screen. Never fails the sync.
- The sidebar widget sync (`CpController::actionWidgetSync()`) acks its single page the same way after a successful sync, non-fatally logged on failure.
- `ContentIQApiService::fetchExport()` gained optional `$timeout`/`$connectTimeout` overrides (defaults unchanged: 120s/10s) — used by the Sync screen (see below) for page-load responsiveness without touching the queue job's call.

**Full-project Sync tree:**
- `CpController::actionSync()` now calls `fetchExport()` (20s/5s timeouts) on every page load and builds the FULL ContentiQ project tree via new `_buildFullSyncTree()`, rather than only listing entries already in `contentiq_entry_syncs`.
- Each export page is matched to Craft via `ImportService::findExistingEntry()` — the same id-first resolver SyncJob's own lock check uses, so the screen and an actual sync always agree on what a page maps to. A match carries its lock state (missing sync row ⇒ locked, same default as SyncJob); no match renders a "New" row (never locked — SyncJob's lock check only runs when `findExistingEntry()` finds something, so an unimported page always imports regardless of any selection — matches first-sync-ever behaviour, unchanged).
- Pages-group hierarchy is built from the export's own `document.parent_slug`/`slug` (the only source that can link a not-yet-imported child to its parent — a new page has no Craft structure position yet). Collection-entry rows stay flat (parentSlug forced null), matching the pre-existing local-only view and avoiding a latent bug in the shared tree macro (a parentSlug pointing outside its own group silently drops the row — pre-existing, not touched).
- An unmapped `content_type` (no `content_types` route) is skipped from the tree entirely, mirroring `ImportService::_importCollectionChild()`'s own non-fatal import-time skip.
- Old behaviour — building the tree from `contentiq_entry_syncs` only — is preserved verbatim as `_buildLocalOnlySyncTree()`, now the fallback whenever the API is unconfigured or unreachable (previously unconfigured hard-redirected away from the screen entirely; it now renders the local-only view with a dismissible warning banner instead — the screen never 500s because ContentiQ is down).
- `sync.twig`: "New" rows get a blue "New" status badge (checkbox default-checked, but — since it has no lock row to toggle — the status cell and the `unlockIds` payload both ignore its checked state; a hint line spells this out). Existing lock-toggle/select-all/select-none behaviour for already-imported rows is unchanged.

**Real opt-out for "New" rows:** the above shipped New rows as checked-but-disabled — SyncJob always imported them regardless of the checkbox. Ben tested it and wanted a genuine skip. New checkboxes are now enabled (still checked by default) and carry `data-contentiq-page-id` (the export's `document.id`); unchecking one flips its status cell to "New — skipped" (gray, still toggleable) and the checkbox now participates normally in shift-click / Select All / Select None / the summary counter (the `disabled`-skip guards for New rows are gone).

- **`sync.twig`** — submit handler collects `excludeNewIds`: ContentIQ page ids of unchecked New rows, appended to the FormData as a JSON array alongside `unlockIds`. Deliberately opt-out semantics: if JS breaks or an id is missing, nothing is excluded and the page imports as before — never silently skip by default. Hint copy updated: unchecking a New page skips it for this sync only; it reappears as New next time.
- **`CpController::actionRunSync()`** — reads `excludeNewIds` the same tolerant way as `unlockIds` (`Json::decodeIfJson`, non-array/malformed → `[]`, never `null`), passes it to `SyncJob`.
- **`SyncJob::$excludeNewIds`** (new `int[]` property, default `[]`) — in the page loop, after the existing lock-check block, a page is skipped without importing when `findExistingEntry()` found nothing (genuinely new) AND its `document.id` is in the list — same resolver the lock check uses, so a page that raced to become an existing entry between page-load and run-time is never skipped, only genuinely-new pages are. Records a result row shaped like the `skippedLocked` case (`success: true`, `entryId: null`, warning `'Skipped — deselected.'`, flag `skippedDeselected: true`) and `continue`s — deliberately NOT added to `$slugToEntryId` (nothing was created). A child of an excluded parent falls through to the existing "parent slug not found" warning and imports at root; no special-casing added, next sync self-heals once the parent is no longer deselected.
- Ack filter (`5b.` in `SyncJob`) and the auto-lock loop (`9.`) already exclude these rows via `entryId === null`; added an explicit `empty($result['skippedDeselected'])` check to the ack filter for defense-in-depth and readability parity with the existing `skippedLocked` check.
- **`sync-result.twig`** — mirrors the `skippedLocked` rendering: `'Skipped — deselected.'` warning string drives a "Skipped (deselected)" inline label, a "Deselected" status-column badge, and counts toward `skippedCount` (not `updatedCount`) in the summary line — same pattern as the locked case.

## Sync screen + Sync Report reorg (2026-08-21)

Both CP templates reorganised into a Payload-plugin-style layout (one bordered pane per group, master + per-pane "Select all" checkboxes, a single calibrated 24px content inset across every pane). No controller/PHP changes — both templates already had (or, for the report, could derive purely in Twig) everything needed. Sync semantics (FormData contract, SyncJob) untouched.

**`sync.twig`:**
- Panes reordered: top control block → Pages pane → one pane per collection section (`syncGroups` was already Pages-first-then-collections from `CpController::actionSync()`, so this is a pure template split of the old single multi-`tbody` table into one `.pane`/`<table>` per group) → Globals consent pane (moved below all entry panes, was above) → new bottom control block.
- Replaced the `contentiq-select-all`/`contentiq-select-none` buttons with checkboxes: one master "Select all" bar above all panes (new id `contentiq-select-all-master`) toggling every `.contentiq-sync-cb` on the page, plus one `.contentiq-pane-select-all` checkbox in each pane's header toggling just that pane's rows (found via `closest('.pane')`). Both reflect state — checked/unchecked/indeterminate — recomputed after every row click, shift-click range, or select-all toggle (`refreshAllSelectAlls()`). Shift-click ranging still walks the full flat `checkboxes` array (can span a pane boundary; each pane's own select-all recomputes correctly regardless — degrades gracefully per spec).
- New bottom control block: a static hint + a second `<button type="submit" form="sync-form">` — no duplicate form, submits the same `#sync-form` via the HTML `form` attribute (fires the same `submit` listener). Loading/error UI panels (`sync-idle`/`sync-loading`/`sync-error`) stay top-only per the brief's fallback allowance; the bottom button is disabled while a sync is in flight or on error via `bottomSyncBtn.disabled` in the existing `submit`/`showError`/`resetSync` handlers.
- Padding calibration: every pane's inner content now uses a 24px left/right inset consistently — pane headers (`12px 24px`), the top/bottom control panes (`24px`), and the row macro's first/last `<td>` (`padding-left:24+depth*24px` / `padding-right:24px`, was `12px`/unset). Table `<th>`s carry matching `padding-left:24px`/`padding-right:24px` on the first/last column.
- FormData payload construction (`unlockIds`, `excludeNewIds`, `unlockGlobals`) is byte-identical to before — only the select-all/master-checkbox bookkeeping around it changed.

**`sync-result.twig`:**
- Same pane-per-group reorg, done entirely in Twig (no controller changes): a `sectionLabel` already present on every result row (`null` for Pages/homepage, section name for collection children — set by `ImportService::_importCollectionChild()`/`SyncJob`) is grouped in-template into `pageOnlyResults` (tree-rendered via the existing `pageRows` macro, now scoped to non-collection rows) and `collectionGroups`/`collectionGroupOrder` (one pane per label, in first-appearance order, rendered flat via a new `flatEntryRows` macro — collection children never have a Craft parent, so no tree is built for them, sidestepping any slug-collision risk in a shared tree-node map).
- Order: status banner → ackWarning → summary stat row → warnings panel (unchanged position/mechanics) → Pages pane → one pane per collection → Globals pane (now wrapped in the same bordered-pane-with-uppercase-header treatment as the other panes, with offices/global-set-fields/warnings as hairline-divided sub-sections inside one pane instead of three stacked panes).
- Summary line replaced with a "Craft-idiomatic" stat row (`.pane` with a flex row of number-over-label stat blocks) — same counts as before (updated/skipped/total/images downloaded/images reused/warnings), no counts added or removed.
- Status badge vocabulary aligned with the Sync screen per the brief: `Updated` is now green (was blue) — Created and Updated both read "green" now; Locked/Deselected/Skipped stay gray, Failed stays red.
- Same 24px padding calibration as sync.twig (pane headers, row macro first/last `<td>`, table `<th>`s).
- Kept: the legacy flat-result fallback (`result.pages is defined ? result.pages : result`), the `ackWarning` banner, the collapsed warnings "See more" panel, per-row Edit/View buttons, "Sync Again" button.

Verified: Twig `{% if %}`/`{% for %}`/`{% macro %}`/`{% block %}`/`{% css %}` tag balance checked programmatically for both files (all matched). Both files re-read top-to-bottom tracing master toggle → row checkbox → `updateStatusCell`/`updateSummary` → submit payload. No PHP files changed (`CpController::actionSync()`/`actionSyncResult()` untouched — the report's grouping is pure Twig, and `actionSync()`'s existing `$syncGroups` shape was already exactly what the new pane-per-group template needed).

## Blockquote node type support (2026-08-20)

`NodesRenderer` no longer silently drops `blockquote` nodes — both rich-text consumption paths now render them (previously both `match` statements fell through to `default => ''`).

- **`_renderNode()` (flat `nodes[]` blocks path)** — new `'blockquote'` arm calling `_renderBlockquote()`. Mirrors `_renderParagraph()`'s idiom exactly: prefers inline `content` (marks, hardBreaks) via `_renderInlineContent()`, falls back to `htmlspecialchars($node['text'])`. Emits `<blockquote><p>…</p></blockquote>` — CKEditor's Block Quote feature expects a paragraph inside the blockquote. Empty blockquote → `''`.
- **`_renderDocNode()` (raw ProseMirror `content` path, collection children)** — new `'blockquote'` arm calling `_renderDocBlockquote()`. Here the shape is the NESTED ProseMirror form (`{type:'blockquote', content:[block nodes]}`, not flat text) — wraps `<blockquote>` around each child block rendered through the existing `_renderDocNode` recursion, so nested paragraphs/lists inside the quote keep their own markup. Empty → `''`.
- The two paths take genuinely different input shapes for the same node-type name and are handled separately, same as `paragraph` already is in each.
- `CLAUDE.md` and `PLUGIN-SPEC.md`'s NodesRenderer node-type tables updated with the new rows.
- Test coverage added to `tests/run-transforms.php`: flat text-only, flat with `content` (hardBreak + mark), flat empty, nested single paragraph, nested two paragraphs, nested empty.

## Custom block — multi-image support (2026-05-22)

The Custom block mapping has been updated to match ContentiQ's new multi-image export shape.

**`defaults.php`:**
- `'image' => ['contentiqImage', 'image']` replaced with `'images' => ['contentiqImages', 'images']`.
- The `contentiqImages` handle is a multi-asset field on the `contentiqCustom` entry type.

**`MatrixBuilder.php`:**
- Added `'images'` handler to `_resolveFieldByHandler()` dispatch table.
- New `_handleImages(string $handle, mixed $value, array &$imageReport, bool $dryRun): array` method: iterates the images array (up to 10), calls `ImageImportService::importFromField()` for each, collects Craft asset IDs. Individual failures are non-fatal — logged as a warning, image skipped, rest of block continues.

## Removed em-dash nesting markers in Sync view (2026-05-22)

Dropped the `&mdash;` indicator span from the pre-sync entry tree (`sync.twig` `pageRows` macro). The `padding-left` indentation (24px per depth) already makes nesting clear, so the leading dashes were redundant. The `depth`/`indent` calculation stays — only the visual marker was removed.

## Collapsible warnings panel (2026-05-22)

The sync report's Warnings panel is now collapsed to a small bar by default (heading + count + a "See more"/"See less" toggle). Expanding is CSS-only: a visually-hidden checkbox driven by the `<label>`, with the list animated open via a `grid-template-rows: 0fr → 1fr` transition (animates to exact content height — no JS, no magic max-height). Styles registered via `{% css %}` in `sync-result.twig`.

## Accurate sync report summary counts (2026-05-22)

The sync report headline read "X pages imported" using `run.result|length` — the number of pages **found in the API response**, not the number actually written. A sync that wrote 1 entry and skipped 33 locked ones reported "34 pages imported".

- **`sync-result.twig`** — replaced the single figure with **"X updated · Y skipped · Z total"**. Counts are derived per page from `run.result` (the same array the row table reads), alongside the existing image/warning tallies:
  - `updated` = `page.success` and not skipped — entries actually written/saved (covers created + updated).
  - `skipped` = `page.skipped` **or** the locked marker (`'Skipped — entry is locked.'` in `page.warnings`) — every skip reason.
  - `total` = `pages|length` — pages found in the API response (= updated + skipped when there are no failures).
- No PHP changed: the per-page result already carries `success`, `skipped`, and the locked warning string (locked entries are pushed to `run.result` by `SyncJob` with `success=true` but never written; unmapped content types carry `skipped=true` from `ImportService`). The image download/reuse counts are unchanged.
- Verified against run #43 (the reported case): now shows "1 updated · 33 skipped · 34 total".

## Collection child H1 → dedicated heading field (2026-05-22)

Some collections (e.g. blog/article) want the content's H1 in a dedicated field (a "headline") rather than buried in the body rich text. A collection child's first content node is a level-1 `heading` whose text duplicates `document.title`.

- **`content_types` route** gained an optional `headingField` key — `slug => {section, entryType, contentField, headingField}`. When set, `_importCollectionChild()` lifts the **first level-1 heading** out of the ProseMirror `content` as plain text into that field, and strips that heading node from the body so it isn't rendered twice. Unset → unchanged (H1 stays in the body). Unknown handles are dropped by the existing field-layout filter, so a wrong/absent field is harmless.
- **`NodesRenderer::extractHeading(?array $doc, int $level = 1): {text, doc}`** (new) — returns the first matching heading's plain text (marks flattened via new private `_plainText()`) plus the doc with that node removed, preserving the input shape (doc node or bare content array). `text` is null + doc untouched when no match.
- **Config** — default `articles` mapping in `defaults.php` now sets `headingField => 'headline'`; the Craft Starter project override (`config/contentiq.php`, `blog` slug) sets `contentField => 'articleBody'`, `headingField => 'headline'`.
- Verified against `fish-13-export`: H1 "Seafood Sustainability" lifted into `headline`, `articleBody` starts at the first `<p>` with no `<h1>`.

## Collection children had no front-end URL (2026-05-22)

Imported collection children (articles, etc.) showed as LIVE but had no globe link in the CP and were not viewable on the front end. Root cause: the importer saves with `saveElement($entry, false)` (validation off — added to dodge MatrixBlockAnchorField uniqueness errors), but in Craft 5 URI generation runs inside `ElementUriValidator` (a validation-time step). With validation skipped, `elements_sites.uri` stays `NULL` → no front-end URL. Standard **pages** dodged this by accident: their post-save structure-positioning step (`Structures::append()` → `Entry::afterMoveInStructure()`) calls `updateElementSlugAndUri()`, which regenerates the URI. Collection children are channel entries with no structure step, so nothing ever generated their URI.

- **`ImportService::_refreshUri()`** (new private) — after a successful collection-child save (both new and existing branches in `_importCollectionChild()`), calls `Craft::$app->getElements()->updateElementSlugAndUri($entry, true, false)`. This generates and persists slug + URI directly **without** re-running element validation (so the MatrixBlockAnchorField issue the `false` guards against is not reintroduced). Wrapped in try/catch → `OperationAbortedException` etc. become a non-fatal warning on the result. No-op for sections without URLs (`setElementUri` leaves `uri = null`).
- Standard pages / homepage are unchanged — they already get URIs via the structure / Single path.
- Backfill for already-imported entries: `php craft resave/entries --section=article`. (Re-syncing also fixes them now.) Sections with `hasUrls: false` — e.g. `articleCategories` — correctly keep `uri = null`.

## Sync screen lists collection sections (2026-05-22)

The pre-sync entry list previously showed only the Pages section (`->section([$sectionHandle, 'homepage'])`), so collection children synced into other sections (articles, blogCategories, etc.) never appeared as selectable rows. Now the list spans every relevant section, grouped by section.

- **`ImportService::getContentTypesMap()`** (new public method) — exposes the resolved `content_types` map (defaults + project override) so callers can enumerate collection sections. Thin wrapper over the existing private `_getContentTypesMap()`.
- **`CpController::actionSync()`** — derives the ordered collection-section list from `content_types` config (distinct section handles, Pages excluded), then queries `Entry::find()->section([pages, homepage, ...collectionSections])` (still restricted to entries that have a sync record). Entries are bucketed into display groups: homepage folds into the Pages group; each collection section is its own group. `getParent()`/`level` are only read for structure sections (collections are channels → depth 0, no parent). Groups are emitted in order — Pages first, then collection sections in `content_types` config order — and **empty groups are omitted** (a collection with no synced entries shows no header). Passes `syncGroups` to the template (replaces the flat `syncEntries`).
- **`sync.twig`** — renders one `<tbody>` per group with an uppercase section-label header row (Craft section **name**, not the ContentiQ slug). Tree maps (`slugToPage`/`childrenOf`) are built per group so slug collisions across sections can't cross-link, and the parentSlug tree still renders for the Pages structure (collections render flat as root rows). Column header renamed "Page" → "Title". Select All / Select None and the sync submission already operate on all `.contentiq-sync-cb` checkboxes document-wide, so selection works across every group unchanged.
- **`SyncJob`** — confirmed correct, no change needed: the lock check already looks up the entry in its routed section via `getContentTypeRoute()`, and auto-lock / sync-record upserts are element-id keyed (section-agnostic), per the 2026-05-21 work.
- Import/sync logic is unchanged; this is a CP listing/selection change only. All changed PHP files pass `php -l` (no ECS/PHPStan in the plugin).

## Content Types — collection children (2026-05-21)

ContentiQ's Content Types system exports two new document shapes. Collection **parents** are excluded server-side (the importer never sees them). Collection **children** carry `document.content_type` (the inherited collection slug), a raw `content` ProseMirror field instead of a `blocks` array, and SEO only for portal-on collections. The importer now routes and imports them:

- **`config/defaults.php`** — new `content_types` map: slug → `{section, entryType, contentField}` (defaults match the Craft Starter: `articles`, `blog_categories`, `case_studies`, `team`, `offices`). Override per-project in `config/contentiq.php` under `content_types` (per-slug replace). `MatrixBuilder::prepare()` `unset()`s this key so it's never treated as a block mapping.
- **`NodesRenderer::renderDocument()`** (new) — serialises a **raw ProseMirror doc** (the `content` field) to HTML: `heading` (h1–h6 via `attrs.level`), `paragraph`, `bulletList`/`orderedList`/`listItem` (paragraph children unwrapped, nested lists recurse), `hardBreak`, `horizontalRule`, reusing the existing inline renderer for `bold`/`italic`/`link`/etc. Distinct from `render()`, which consumes ContentIQ's block-serialised node shape.
- **`ImportService`** — `getContentTypeRoute(?string)` (public; also used by SyncJob) resolves the routing map (cached). `importPage()` branches early when `content_type` is set to `_importCollectionChild()`: routes to the configured section/entryType, serialises `content` → `contentField`, sets SEO only when present (portal-on), finds/creates/updates by slug in the routed section, no Matrix/hero/CTA/parent steps. Unmapped `content_type` → logged warning + non-fatal skip (`skipped` flag), never a fatal error.
- **`SyncJob`** — lock check now looks up the entry in its **routed** section (not hardcoded Pages); structure positioning / parent resolution is skipped entirely when `content_type` is set (collection children have no Craft parent). The locked-skip result carries `contentType`/`sectionLabel`.
- **`sync-result.twig`** — shows the section label (Craft section name, falling back to the content_type slug) next to the title, and a "Skipped" status for unmapped content types. Collection children render as root rows (their excluded parent isn't in the batch) — report still works.
- **Tracking** — `contentiq_entry_syncs` is element-id keyed (no section column), so insert/update/auto-lock already work unchanged for any routed section.
- Standard pages (`content_type` null) are completely unchanged. No tests exist in the plugin; all changed PHP files pass `php -l`.

## Version 1.2.0 (in progress)

### Completed

- **Plugin settings** — Settings model with `contentiqUrl`, `apiKey`, `projectSlug`. Saved via Craft's plugin settings mechanism. Settings screen accessible from CP Settings > Plugins.

- **Intro screen** — Replaced history list as the plugin home. Shows "Sync from ContentIQ" (primary) and "Import JSON File" (secondary) options. If API not configured, sync button links to settings instead.

- **History view** — Previous import history list moved to `contentiq-importer/history`, linked from the intro screen. Supports new `sync` type indicator alongside `batch` and `single`.

- **ContentIQ API service** — `ContentIQApiService` fetches project export via `GET {url}/api/v1/projects/{slug}/export` with Bearer auth. Uses `Craft::createGuzzleClient()`.

- **Sync queue job** — `SyncJob` extends `craft\queue\BaseJob`. Controller creates a `pending` run record, pushes job to queue. Frontend polls `sync/status?runId=N` for completion, then redirects to sync report. Calls `Craft.postActionRequest('queue/run')` to kick the queue immediately.

- **Per-entry sidebar widget** — `EVENT_DEFINE_SIDEBAR_HTML` appends a CONTENTIQ section to every entry edit screen with Sync button and last-synced timestamp. Calls single-page API endpoint. Stored in `contentiq_entry_syncs` table.

- **Sync report** — Dedicated template showing hierarchical page tree with indentation from `depth`, created/updated indicators, edit/view links, inline warnings. Summary line with page/image/warning counts.

- **Hierarchy handling** — All import paths (CLI batch, CP JSON import, sync queue job) support `parent_slug` in the document object. Uses `Structures::append()` / `appendToRoot()` for correct sibling ordering. Maintains `$slugToEntryId` map with DB fallback for parent lookups.

- **Homepage import** — `is_homepage: true` routes to the `homepage` Single section. Same `hero` ContentBlock field as pages. Skips title overwrite and structure positioning.

- **Hero ContentBlock** — Both pages and homepage use `heroContent` ContentBlock field (handle override `hero`). Imports `heading`, `richText` (subheading + body), `desktopImage`, and `actionButtons`. Sets `enableHero = true`.

- **Hero subheading** — Optional `{level, text}` subheading rendered as `<hN>` prepended to body in `richText`.

- **Hero action buttons** — `buttons[]` array from ContentIQ imported into `actionButtons` Matrix field inside the hero ContentBlock.

- **ContentIQ Cards staging block** — Cards import to `contentiqCards` (not `contentCards`). Editors migrate to the appropriate final card block type with proper entry links.

- **Cards intro field** — `intro` ContentNode[] on cards blocks imported to outer `richText` CKEditor field above the card grid.

- **Cards structured body** — Card `body` changed from plain string to `ContentNode[]` array, processed through `NodesRenderer`. Supports paragraphs, lists, and embedded FAQ items.

- **FAQ nodes handler** — `faqNodes` handler splits the `nodes` array at the `faq_items` boundary: content before → `richText`, items → inner accordion entries, content after → `extraRichText`, CTA buttons → `actionButtons` Matrix. Supports both `fields.items` (primary) and `nodes.faq_items` (fallback) as FAQ item sources.

- **USP block** — `usp` type maps to `contentiqUsp` with `uspText` (richText with list support).

- **Global block** — `global` type maps to `contentiqGlobal` with `contentiqNotes` for developer staging.

- **Action button support** — `hyperButton` handler in MatrixBuilder converts `{label, url}` to Hyper field data. Sets `showLinkAsSeparateButton` when button present.

- **NodesRenderer upgrades** — Added `list` node type (with `ordered` boolean), `faq_items` node type (renders as `<details><summary>` accordions), `ctaButton` node type (renders as `<p><a href="">label</a></p>` — URL left empty for editors to set). Supports `heading`, `paragraph`, `list`, `ordered_list`, `unordered_list`, `faq_items`, `ctaButton`.

- **Price List richer intro** — `nodes` (intro content) now contains headings, paragraphs, lists, and CTA buttons — all rendered via `NodesRenderer` into `richText`. No mapping change needed; `NodesRenderer` handles the new node types automatically.

- **Inline mark rendering** — `NodesRenderer` now renders ProseMirror inline marks (bold, italic, code, strike, underline, link) to HTML tags (`<strong>`, `<em>`, `<code>`, `<s>`, `<u>`, `<a href>`). All render methods check for `content`/`itemContents`/`questionContent`/`answerContent`/`cellContents` and use `_renderInlineContent()` when present, falling back to plain text. `MatrixBuilder::_handleHeading()` and `ImportService::_buildHeroInnerFields()` also use inline content when present. Public `renderInlineContent()` wrapper added for cross-service access.

- **Price List post-table buttons** — `postNodes` (CTA buttons after the table) mapped to `actionButtons` Matrix via new `buttonNodes` handler in `MatrixBuilder`. Handler filters `ctaButton` nodes from a `ContentNode[]` array and builds the same Hyper `actionButton` Matrix structure used by other blocks.

- **Asset filename sanitization** — `Assets::prepareAssetName()` applied before idempotency lookup. Prevents mismatch when Craft sanitizes filenames on save (spaces → hyphens).

- **Image downloads via Guzzle** — Replaced `file_get_contents()` with `Craft::createGuzzleClient()` for SSL compatibility with dev domains.

- **Slug mapping** — `config/contentiq.php` `slugMap` translates Craft slugs to ContentIQ slugs for the sidebar widget sync.

- **CLI default action** — `ImportController::$defaultAction = 'import'` so `contentiq-importer/import` works without repeating `import`.

- **CP nav icon** — Uses Craft's built-in `copyright` system icon.

- **Sidebar block notes** — Collects `notes` from each block during import, formats as "Block Type\nnote text", stores in `contentiq_entry_syncs.notes` column. Displayed in the sidebar widget below "Synced at". Updates in place on sync. Migration `m250419_000000_add_notes_to_entry_syncs` adds the column.

- **Sidebar reload link** — After a successful sync, a "Reload" link appears next to the timestamp so the editor can refresh to see updated content.

- **ctaButton node type** — `NodesRenderer` renders `ctaButton` nodes as `<p><a href="url">label</a></p>`.

- **buttonNodes handler** — Maps `postNodes` on price_list blocks to `actionButtons` Matrix via CTA button extraction.

- **Hyper linkClass** — Action buttons in hero and CTA entries now include `linkClass: 'btn btn-primary'`.

- **Hero mobile image** — `mobile_image` field from ContentIQ hero blocks imported to `mobileImage` asset field on the hero ContentBlock.

- **Sidebar lock toggle** — CSS-only lightswitch in the CONTENTIQ sidebar. Locked entries are skipped during batch syncs (SyncJob) with a warning. Stored in `contentiq_entry_syncs.locked`. Migration `m250419_000001_add_locked_to_entry_syncs`.

- **Sidebar clear notes** — "Clear" button removes block notes via `contentiq-importer/cp/clear-notes` endpoint.

- **Entry title in error messages** — Widget sync errors use the entry title instead of slug for readability.

- **Sync report tree fix** — Report now builds a proper hierarchical tree from `parentSlug` using a recursive Twig macro instead of relying on depth + list order. Pages are grouped under their actual parents regardless of API order.

- **Sync button disabled state** — Dimmed at 35% opacity when locked. Re-enable checks lock state to prevent race condition if locked during an in-flight sync.

### Craft Starter template changes

- **Hero template rewrite** — `hero.twig` rewritten as single file (~100 lines) reading from `entry.hero` ContentBlock. Deleted `hero.slide.twig` and `hero.slide.image.twig`. Removed carousel CSS. Parent image inheritance and global fallback preserved.

- **New content block templates** — `contentiqCards.twig`, `contentiqUsp.twig`, `contentiqGlobal.twig`, `priceList.twig`.

- **CKEditor Details/Summary plugin** — Custom CKEditor 5 plugin (`modules/ckeditor-details/`) via `BaseCkeditorPackageAsset`. Single context-aware toolbar button: inserts a fresh `<details>/<summary>` block, or converts selected list items into details blocks. Registered as a Craft module. Built with Vite as ES module. Includes Enter-to-escape keyboard handling (Enter in summary jumps to content, Enter on empty last paragraph escapes the block). Uses Craft's `list-timeline` icon scaled to CKEditor's 20x20 viewBox.

### New files (plugin)

```
src/
├── jobs/
│   └── SyncJob.php              # Queue job for API sync
├── models/
│   └── Settings.php             # Plugin settings model
├── services/
│   └── ContentIQApiService.php   # ContentIQ API client
└── templates/_cp/
    ├── history.twig             # Import history (moved from index)
    ├── settings.twig            # Plugin settings form
    ├── sync.twig                # Sync screen with polling
    └── sync-result.twig         # Hierarchical sync report
```

### Modified files (plugin)

```
src/
├── ContentIQImporter.php         # Settings, routes, sidebar widget, icon
├── controllers/CpController.php # Intro, history, sync, widget-sync, hierarchy
├── console/controllers/ImportController.php  # defaultAction, hierarchy
├── services/ImportService.php   # Homepage, hero ContentBlock, hierarchy
├── services/MatrixBuilder.php   # hyperButton, faqNodes handlers, internal keys
├── services/NodesRenderer.php   # list, faq_items node types
├── services/ImageImportService.php # Guzzle downloads, filename sanitization
├── config/defaults.php          # All block mappings updated
└── templates/_cp/index.twig     # Now intro screen
```

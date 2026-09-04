# Control panel screens & entry sidebar widget

What this covers: a tour of the plugin's Control Panel screens
(`src/controllers/CpController.php`, `src/templates/_cp/*.twig`), the sync
report's anatomy, and the per-entry sidebar widget that lives on the entry
edit screen — how they share lock state and where each piece of behaviour
lives in code.

Verified against code 2026-08-24.

---

## CP screen tour

Each screen is a controller action plus a template of the same name.
Read the template for exact markup/behaviour — this is a map, not a
transcription.

- **Index** (`actionIndex`, `_cp/index.twig`) — the plugin's landing page:
  three panes (Sync from ContentiQ, Import JSON File, Collection Mappings)
  plus a link to History. Sync is the primary CTA; the button links straight
  to `contentiq-importer/sync` when the API is configured, or to the plugin
  settings screen otherwise.
- **Sync** (`actionSync`, `_cp/sync.twig`) — the main working screen. Loads
  the **full ContentiQ Sitemap** on every page load via `fetchExport()`
  (short 20s/5s timeouts — this runs synchronously on render, unlike the
  queue job's own 120s budget) and builds a tree covering every page
  ContentiQ has, including pages **never imported into Craft** — these
  render as "New" rows with a blue status dot and are pre-checked by
  default. If the API is unreachable or unconfigured, the screen falls back
  to a local-only tree built purely from `contentiq_entry_syncs` rows (the
  screen's original, pre-Sitemap-preview behaviour) with a dismissible
  warning banner. See "Sync tree controls" below for the selection UI, and
  `docs/globals.md` for the globals lightswitch this screen also hosts.
  Submitting starts a queue job (`actionRunSync`) and the page polls
  `sync/status` until it leaves `pending`.
- **Mappings** (`actionMappings` / `actionSaveMappings`, `_cp/mappings.twig`)
  — one row per ContentiQ collection, cascading section → entry type →
  content/heading field dropdowns fed by `_buildSectionsData()`. Saved rows
  land in `Settings::$collectionMappings`, persisted through Craft's plugin
  settings mechanism into project config — see
  [import-pipeline.md](import-pipeline.md#content-type-routing-for-collection-children)
  for how this merges with `config/contentiq.php` and the defaults file.
  Rows whose slug is defined
  in the config file render read-only. Admin-only, unlike every other
  mutating action here (those gate on the `contentiq-importer:sync`
  permission instead).
- **History** (`actionHistory`, `_cp/history.twig`) — lists
  `contentiq_import_runs` rows (single/batch/sync/widget imports), each
  linking to its result screen — a `sync`-type row links straight to
  `contentiq-importer/sync/result/<id>` (`actionSyncResult`), every other
  type to `contentiq-importer/result/<id>` (`actionResult`).
- **Preview** (`actionPreview`, `_cp/preview.twig`) — the upload flow's
  dry-run step: shows what an uploaded JSON file would do, including a
  dry-run of any `globals` key it carries (display only — see
  `docs/globals.md` "Why globals never travel via upload").
- **Upload** (`actionUpload` / `actionRunImport`, `_cp/upload.twig`) — manual
  JSON-file import. The temp file round-trips through a hidden
  `tempFilename` field between preview and run — see `docs/assets.md`
  "Temp-file handling" for the path-traversal guard on that value.
- **Result** (`actionResult`, `_cp/result.twig`) — the upload/CLI import's
  result screen: per-page block-by-block breakdown. `run.result` here is
  always the flat per-page array those entry points store — `actionResult()`
  redirects a `sync`-type run (302) to the sync result screen below instead
  of rendering it, because `SyncJob` stores a differently-shaped, wrapped
  result (see "Sync report anatomy"). `result.twig` also unwraps
  `run.result.pages` if present as a belt-and-braces fallback, matching
  `sync-result.twig`'s existing `pages`/`result` fallback, in case anything
  else ever links straight here with a wrapped result.
- **Sync result** (`actionSyncResult`, `_cp/sync-result.twig`) — the sync
  run's result screen; see "Sync report anatomy" below.

---

## Sync tree controls

`_cp/sync.twig` renders the tree via a recursive macro (`_self.pageRows`)
walking `slugToPage`/`childrenOf` maps built server-side in
`CpController::actionSync()`/`_buildFullSyncTree()` — the same tree-building
approach as the sync report (see below), so both screens nest pages under
their real ContentiQ parent regardless of API response order.

Each row has its own checkbox, each pane (Pages, plus one per collection
section) has a per-pane "Select all" that goes indeterminate when partially
checked, and there's one master "Select all" above every pane that drives
all of them. A "New" row stays selectable — unchecking one just means it's
skipped this run and it reappears as "New" next time, since nothing gets
written for a deselected page. Submitting the form collects unchecked "New"
rows' ContentiQ page ids separately from the checked-entry element ids
(new pages have no `element_id` yet), so the queue job knows which
never-imported pages to actually pull in.

---

## Sync report anatomy

`_cp/sync-result.twig` builds its page hierarchy the same way as the Sync
screen: `parentSlug` is present on every result row, and a recursive Twig
macro (`_self.pageRows` for the tree, `_self.warnPageRows` for the
warnings-only walk, `_self.flatEntryRows` for collection children) walks
`rootSlugs`/`childrenOf` maps built once at the top of the template — never
by iterating the flat `pages` array directly, since the API's raw result
order is grouped by depth, not truly depth-first.

- **Pages vs. collections.** Regular pages/homepage (no `sectionLabel` on
  the result row) render as the nested tree; collection children
  (`sectionLabel` set by `ImportService`/`SyncJob`) render flat, one group
  per collection section, sorted alphabetically by label.
- **Counts.** `updatedCount`/`skippedCount`/`totalWarnings`/`totalImages`/
  `reusedImages` are all derived in the template from the raw per-page
  result flags (`success`, `skipped`, the "Skipped — entry is locked."/
  "Skipped — deselected." warning strings, and each page's `images[]`
  array) — never pre-aggregated server-side. Globals' own `imageCount`/
  `imagesReused` are folded into the same image totals.
- **Status badges.** Per-row: green "Updated"/"Created" on success, red
  "Failed" on a hard failure, grey/disabled "Locked"/"Deselected"/"Skipped"
  for the various skip reasons. The overall banner colour (green/yellow/red)
  always follows `run.status`, independent of whether any individual page
  warned.
- **CSS-only collapsible warnings.** The "Warnings (N)" sub-section inside
  the status pane uses a hidden checkbox + `<label for>` pair
  (`#cq-warn-toggle`) to show/hide — no JavaScript. This is the same
  no-JS-needed trick as the sidebar widget's lock switch (see below), used
  here for the same reason: keep the report renderable without depending on
  script execution order.

---

## Entry sidebar widget

Registered in `src/ContentIQImporter.php`'s `_registerEntrySidebar()`, via
`Event::on(Entry::class, Element::EVENT_DEFINE_SIDEBAR_HTML, ...)` — the
only correct hook for adding sidebar content to the entry edit screen. The
field-layout-designer `BaseUiElement` + `EVENT_DEFINE_UI_ELEMENTS` approach
does **not** work here — it only adds elements to the field layout
designer's palette for the main content area, not the sidebar.
`EVENT_DEFINE_SIDEBAR_HTML` (defined on `craft\base\Element`) fires from
`Element::getSidebarHtml()`, which is what `ElementsController` actually
calls when rendering the sidebar — the same pattern `nystudio107/craft-seomatic`
uses for its own sidebar content. It appends to `$event->html`, never
replaces it, and only renders once the entry has a saved id and slug.

**Why the lock lightswitch is hand-rolled CSS, not Craft's own JS
lightswitch.** Craft's lightswitch component initializes against the DOM at
page-load time. The sidebar HTML is injected dynamically as part of the
sidebar event's response, after that initialization has already run, so
Craft's JS never picks it up. The widget instead renders a plain `<input
type="checkbox">` behind a `<label>` styled to look like a lightswitch
(inline `<style>` block scoped to `.contentiq-switch`), and wires its
`change` event by hand in the widget's own inline `<script>`.

- **Toggling the lock POSTs immediately** to
  `contentiq-importer/cp/toggle-lock` (`elementId`, `locked`), persisted to
  `contentiq_entry_syncs.locked` — there's no separate save step, and no
  page reload. `actionToggleLock()` upserts the row if it doesn't exist yet.
- **Locked disables the Sync button** both visually (the button's
  `disabled` attribute is toggled client-side alongside the switch) and
  server-side: `actionWidgetSync()` re-checks `contentiq_entry_syncs.locked`
  for the given `elementId` before doing anything, since the client-side
  disable is advisory only. A missing sync row is treated as locked — same
  default as the Sync screen and `SyncJob`.
- **Batch syncs skip locked entries too** — `SyncJob` logs "Skipped — entry
  is locked." per entry, which is what drives the sync report's "Locked"
  badge (see above).

**Slug mapping.** The widget's Sync button posts the entry's Craft `slug`;
`actionWidgetSync()` translates it to the ContentiQ slug via
`config/contentiq.php`'s `slugMap` (e.g. `homepage => home`) before calling
`GET /api/v1/pages/{slug}/export` — needed whenever the Craft and ContentiQ
slugs genuinely differ for the same page.

**Block notes.** Collected during import from each block's top-level
`notes` key (`ImportService`, both the single-page and batch/collection
paths — grep `blockNotes` if you need the exact assembly), formatted as
`"Block Type\nnote text"` per block, blank-line-separated, and stored in
`contentiq_entry_syncs.notes`. The widget shows them below "Synced at"; the
"Clear" button removes the row's notes via `contentiq-importer/cp/clear-notes`
and updates the DOM in place, no reload.

**Reload link.** After a successful sync, the widget's inline JS injects a
"Reload" link next to the synced-at timestamp — the entry's own fields are
not re-rendered by the AJAX sync, so a manual reload is how the editor sees
the newly-imported content.

**Element ID collision guard.** Every DOM id the widget generates is scoped
with the entry's element id (`contentiq-sync-{elementId}` and its
`-lock`/`-btn`/`-timestamp`/`-notes`/`-clear`/`-reload` suffixes) — needed
because Craft can render more than one entry-edit sidebar in the same page
context (e.g. slideouts), and unscoped ids would collide.

**Error messages** use the entry's title, not its slug, for readability —
the widget looks the title up server-side by element id before returning an
error response.

---

## Lock semantics tying widget and Sync screen together

`contentiq_entry_syncs.locked` is the single source of truth both surfaces
read and write:

- The widget's lightswitch writes it directly, per entry.
- The Sync screen's tree checkbox defaults to checked for an unlocked (or
  brand-new/"New") entry and unchecked for a locked one — but locked rows
  stay independently visible with a disabled-style "Locked" badge rather
  than being hidden.
- `SyncJob` reads it per entry at import time and skips locked entries
  regardless of what the Sync screen's checkbox state was (the checkbox
  only controls *deselection* of unlocked entries — it can't override a
  lock).

A missing row is treated as locked everywhere this is checked (`actionSync`,
`actionWidgetSync`, `SyncJob`) — the safe default for an entry that has
never been touched by this plugin.

---

## Related docs

- [globals.md](globals.md) — the Sync screen's globals consent lightswitch and its own lock table (`contentiq_globals_sync`), separate from per-entry locks.
- [assets.md](assets.md) — the upload flow's temp-file safety net referenced above.
- [README.md](README.md) — plugin overview and where each doc fits.
- [integration.md](integration.md) — the API endpoints these screens and the widget call.
- [import-pipeline.md](import-pipeline.md) — what actually runs once a sync/upload/widget-sync is submitted.
- [block-mapping.md](block-mapping.md) — where `block.notes` and other per-block data originate.

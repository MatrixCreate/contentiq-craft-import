# ContentIQ Importer — Progress

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

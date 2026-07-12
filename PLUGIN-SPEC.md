# ContentIQ Craft Import — Technical Reference

Full architecture and developer reference for the `matrixcreate/contentiq-craft-import` Craft CMS 5 plugin. Covers the import pipeline, ContentiQ JSON format, API endpoints, block mapping system, and database schema.

## Plugin identity

- Package: `matrixcreate/contentiq-craft-import`
- Handle: `contentiq-importer`
- Namespace: `matrixcreate\contentiqimporter`
- Minimum Craft: 5.0
- GitHub: https://github.com/MatrixCreate/contentiq-craft-import

---

## File structure

```
src/
  ContentIQImporter.php          # Plugin bootstrap, service registration, sidebar widget
  config/
    defaults.php                 # Block type mappings (all standard block types)
  console/controllers/
    ImportController.php         # CLI import command
    TestMatrixController.php     # Isolated API test (debug tool)
    ApplyDraftController.php     # One-off draft apply (debug tool)
  controllers/
    CpController.php             # CP routes: import, sync, history, widget, lock, notes
  jobs/
    SyncJob.php                  # Queue job for API sync
  migrations/
    Install.php                  # Creates contentiq_import_runs + contentiq_entry_syncs
    m250418_000000_add_entry_syncs_table.php
    m250419_000000_add_notes_to_entry_syncs.php
    m250419_000001_add_locked_to_entry_syncs.php
  models/
    Settings.php                 # Plugin settings model (URL + API key)
  services/
    ContentIQApiService.php      # API client (fetch export, single page)
    ImportService.php            # Pipeline orchestrator
    ImageImportService.php       # Asset download + idempotent import
    MatrixBuilder.php            # Block mapping → Matrix data array
    NodesRenderer.php            # ContentIQ nodes → HTML string
  templates/_cp/
    index.twig                   # Intro screen (sync + upload options)
    history.twig                 # Import history list
    settings.twig                # Plugin settings form
    sync.twig                    # Sync screen with queue polling
    sync-result.twig             # Hierarchical sync report
composer.json
CLAUDE.md                        # Settled patterns — loaded automatically by Claude
```

---

## ContentiQ JSON format

Every page export has this top-level shape:

```json
{
  "document": {
    "slug": "about-us",
    "title": "About Us",
    "is_homepage": false,
    "parent_slug": null,
    "depth": 0
  },
  "seo": {
    "title": "About Us - Company Name",
    "description": "Learn about our company...",
    "og_title": "...",
    "og_description": "...",
    "canonical": "https://example.com/about-us",
    "og_image": { "key": "project/og.jpg", "url": "https://cdn.../og.jpg", "alt": "..." }
  },
  "blocks": [
    { "type": "hero",  "notes": "Optional developer note", "fields": { ... } },
    { "type": "text",  "fields": { ... } },
    { "type": "cards", "fields": { ... } }
  ]
}
```

Batch exports (full project) wrap pages in a top-level array:

```json
{
  "exported_at": "2025-05-20T10:30:00Z",
  "pages": [ { ...page... }, { ...page... } ]
}
```

The plugin detects single vs batch from the presence of a top-level `pages` key.

### The `nodes` rich text format

Structured text fields use a ProseMirror/TipTap document format:

```json
"nodes": [
  {
    "type": "paragraph",
    "content": [
      { "type": "text", "text": "Hello ", "marks": [] },
      { "type": "text", "text": "world", "marks": [{ "type": "bold" }] },
      { "type": "hardBreak" }
    ]
  },
  {
    "type": "heading",
    "attrs": { "level": 2 },
    "content": [{ "type": "text", "text": "Section Title", "marks": [] }]
  },
  {
    "type": "list",
    "attrs": { "ordered": false },
    "content": [
      { "type": "listItem", "content": [{ "type": "paragraph", "content": [...] }] }
    ]
  }
]
```

Inline marks: `bold`, `italic`, `code`, `strike`, `underline`, `link` (with `attrs: {href, target?, rel?}`).

### Block `fields` shapes by type

| Block type | Key fields |
|---|---|
| `hero` | `heading: {level, text}`, `image: {key, url, alt}`, `mobile_image?`, `buttons: [{text, url}]`, `subheading?: {level, text}` |
| `text` | `nodes: [...]`, `columns: 'singleColumn\|twoColumns'` |
| `text_and_media` | `nodes: [...]` (may contain `ctaButton` nodes), `image: {key, url, alt}`, `mobile_image?`, `layout: 'image_right\|image_left\|background'` |
| `faq` | `nodes: [...]` — nodes array contains `faq_items` typed nodes |
| `cards` | `cards: [{heading, nodes, image, button_label}]`, `nodes: [...]` (intro) |
| `price_list` | `nodes: [...]`, `price_items: [{...}]`, `nodes` with `ctaButton` nodes |
| `call_to_action` | `heading`, `nodes`, `image`, `buttons: [{text, url}]` |
| `usp` | `heading: {level, text}`, `items: [{text}]` |
| `global` | `nodes: [...]` — rendered to the `globalContent` CKEditor field |
| `custom` | `nodes: [...]` (→ `contentiqContent` CKEditor), `images: [{key, url, alt}]` (→ `contentiqImages`, up to 10) |
| `collection_listing` | `nodes: [...]` (intro), `collection` (ContentIQ collection slug → section handle) |

Image fields always use the shape `{ "key": "s3/path/file.jpg", "url": "https://...", "alt": "..." }`. The `key` is the S3 object key used for filename extraction; `url` is the download URL.

---

## ContentiQ API

Two endpoints, both authenticated via `Authorization: Bearer {apiKey}`:

```
GET /api/v1/export               → full project batch export
GET /api/v1/pages/{slug}/export  → single page export
```

The API key embeds the project slug: `ciq_{project-slug}_{32randomchars}`. The server resolves the project from the Bearer token — no project ID is needed in the URL.

Both endpoints return the same JSON shapes documented above (batch vs single).

The plugin makes API requests via `Craft::createGuzzleClient()` with a 120s read timeout and 10s connect timeout.

### Slug mapping

When the Craft slug differs from the ContentiQ slug (e.g. homepage → home), configure a mapping in `config/contentiq.php`:

```php
'slugMap' => [
    'homepage' => 'home',  // Craft slug → ContentiQ slug
],
```

Used by the sidebar widget sync to translate before calling `/api/v1/pages/{slug}/export`.

---

## Import pipeline — per page

```
JSON page object
    │
    ▼
ImportService::importPage()
    ├── Parse document (slug, title, parent_slug, is_homepage, depth)
    ├── Detect section: pages Structure  OR  homepage Single
    ├── ImageImportService::prepare()    — resolve volume + folder (cached)
    ├── MatrixBuilder::prepare()         — merge defaults + project overrides (cached)
    │
    ├── Extract hero block               → separate handling
    ├── Extract call_to_action blocks    → deferred (need entry IDs first)
    ├── Remaining blocks → MatrixBuilder::build()
    │       ├── Group consecutive text_and_media blocks (grouped mode)
    │       ├── For each block: resolve outerType, outerFields, innerMatrix
    │       ├── Apply field handlers (nodes, image, heading, hyperButton, etc.)
    │       └── Returns matrixData + block/image reports
    │
    ├── Build SEO field value (SEOmatic SeoSettings structure)
    ├── Build hero ContentBlock field value
    ├── Find or create Craft entry by slug
    ├── Filter field values against entry's field layout
    ├── [dry-run exit point]
    ├── Create callToActionEntry records, patch IDs into matrixData placeholders
    ├── entry->setFieldValues(filteredValues)
    └── elements->saveElement(entry, false)   ← skip validation
```

### Return shape

```php
[
    'success'       => bool,
    'slug'          => string,
    'title'         => string,
    'entryId'       => int|null,
    'entryFound'    => bool,       // true = updated, false = created
    'seoFieldCount' => int,
    'blocks'        => [['type' => '...', 'fields' => [...], 'skipped' => bool, 'innerCount' => int]],
    'images'        => [['filename' => '...', 'reused' => bool]],
    'blockNotes'    => string,     // aggregated block.notes from all blocks
    'warnings'      => string[],
    'error'         => string|null,
]
```

---

## Block mapping system

`src/config/defaults.php` is a declarative mapping from ContentiQ block types to Craft field data. The structure:

```php
'text_and_media' => [
    'outerType'   => 'textAndMedia',         // Outer contentBlocks entry type
    'outerFields' => [
        // 'contentiqKey' => ['craftHandle', 'handlerType']
        'layout' => ['blockLayout', 'textMediaLayout'],
    ],
    'innerMatrix' => [
        'outerField' => 'textAndMediaBlocks', // Matrix field on the outer entry
        'innerType'  => 'textAndMediaBlock',  // Inner entry type
        'mode'       => 'grouped',            // How inner entries are generated
        'fields'     => [
            // mediaNodes renders text to richText AND lifts ctaButton nodes out
            // into the inner block's actionButtons Matrix (not raw <a> tags).
            'nodes'  => ['richText', 'mediaNodes'],
            // textMediaMedia receives the whole block ('_block') → sets mediaType
            // (image | backgroundImage) from the layout and routes the image to
            // the matching field (image, or desktop/mobileBackgroundImage).
            '_block' => ['mediaType', 'textMediaMedia'],
        ],
    ],
],
```

### Modes

| Mode | Behaviour |
|---|---|
| `single` | One inner entry, fields pulled from block root |
| `repeated` | One inner entry per item in `sourceKey` array (e.g. cards, FAQ items) |
| `grouped` | Consecutive same-type blocks collapse into one outer entry with multiple inner entries |
| `text_columns` | Splits at first heading for two-column text layouts |

### Field handlers

| Handler | Input | Output |
|---|---|---|
| `nodes` | `ContentNode[]` | HTML string via NodesRenderer |
| `mediaNodes` | `ContentNode[]` with `ctaButton` nodes | richText HTML + `actionButtons` Matrix (ctaButtons lifted out) |
| `textMediaMedia` | whole inner block (`_block`) | `mediaType` + image routed to `image` or `desktop`/`mobileBackgroundImage` |
| `image` | `{key, url, alt}` | `[$assetId]` (downloads image) |
| `images` | `[{key, url, alt}]` | `[$assetId, …]` (multiple assets) |
| `heading` | `{level, text}` or string | `<hN>text</hN>` |
| `body` | plain string | `<p>text</p>` |
| `layout` | string | pass-through |
| `textMediaLayout` | `image_right\|image_left\|background` | `text-left\|image-left` |
| `hyperButton` | `{label, url}` | Hyper link field array |
| `buttonLabel` | `{label, url}` | label string only |
| `faqNodes` | `ContentNode[]` | splits at `faq_items` → `{richText, extraRichText, _faqItems, actionButtons}` |
| `buttonNodes` | `ContentNode[]` | filters `ctaButton` nodes → actionButtons Matrix entries |
| `uspContent` | entire block fields | `{heading:{level,text}, items:[]}` → HTML |
| `collectionSection` | ContentIQ collection slug | Craft section handle (via `content_types` map; unmapped slug stored raw + warning) |
| `tableHtml` | `[{isHeader, cells}]` | `<table>` HTML string |

### Standard block mappings

| ContentIQ type | Outer entry type | Inner Matrix field | Inner entry type | Mode |
|---|---|---|---|---|
| `text` | `text` | `textBlocks` | `textBlock` | text_columns |
| `text_and_media` | `textAndMedia` | `textAndMediaBlocks` | `textAndMediaBlock` | grouped |
| `faq` | `faq` | `accordionItems` | `accordionItem` | repeated |
| `cards` | `entryCards` | `entryCards` | `card` | repeated |
| `price_list` | `priceList` | *(none)* | — | outer fields only |
| `usp` | `contentiqUsp` | *(none)* | — | outer fields only |
| `global` | `contentiqGlobal` | *(none)* | — | outer fields only (`nodes` → `globalContent`) |
| `custom` | `contentiqCustom` | *(none)* | — | outer fields only (`nodes` + `images`) |
| `collection_listing` | `collectionListing` | *(none)* | — | outer fields only (`nodes` intro + `listingSection`) |

### Special blocks (handled by ImportService, not defaults.php)

| Block type | Behaviour |
|---|---|
| `hero` | Sets `enableHero = true` and populates a `hero` ContentBlock field with heading, richText, desktopImage, mobileImage, actionButtons |
| `call_to_action` | Creates/updates a `callToActionEntry` in the `callsToAction` section, then relates it via `chooseCallToAction` on a `callToAction` contentBlock |
| `table` | Skipped — no matching block type in Craft Starter |

### Project overrides

Add `blockOverrides` to `config/contentiq.php` to replace any block definition entirely:

```php
'blockOverrides' => [
    'custom_block' => [
        'outerType'   => 'myCustomBlock',
        'outerFields' => [ ... ],
        'innerMatrix' => [ ... ],
    ],
],
```

Overrides replace the entire definition — they are not merged at the field level.

---

## Services

### ContentIQApiService

Communicates with the ContentiQ API. Key method:

- `fetchExport(): array` — `GET /api/v1/export`, returns `['success', 'data', 'error']`
- Single-page fetch is done inline in `CpController::actionWidgetSync()` using the same Guzzle client

### ImageImportService

Downloads remote images and imports them as Craft assets. Fully idempotent.

```
1. Extract filename from S3 key (or URL basename)
2. Sanitize via Assets::prepareAssetName()  (spaces → hyphens)
3. Query: does this filename exist in this folder?
   YES → return existing ID (reused: true)
   NO  → download via Guzzle to temp file
         → create Asset with SCENARIO_CREATE
         → set newLocation: "{folder:X}filename"
         → saveElement()
```

Non-fatal: if download or save fails, returns `null`, logs a warning, import continues.

`prepare(volumeHandle, folderPath)` must be called once per import run. `reset()` clears the cache between runs.

### MatrixBuilder

Builds the Matrix field data array from ContentiQ blocks using the mapping config.

Pre-processing groups consecutive `grouped`-mode blocks before individual block processing. Each block resolves to:

```php
[
    'outerType' => 'textAndMedia',
    'outerFields' => ['blockLayout' => 'text-left'],
    'innerEntries' => [
        ['type' => 'textAndMediaBlock', 'fields' => ['richText' => '<p>...</p>', 'image' => [42]]],
    ],
]
```

Field layout filtering removes any handles not present in the entry's field layout, preventing `Setting unknown property` exceptions.

### NodesRenderer

Converts ContentiQ nodes arrays to HTML strings. No Craft dependencies — fully portable.

**Block node types:**

| Node type | Output |
|---|---|
| `paragraph` | `<p>...</p>` |
| `heading` | `<h1>`–`<h6>` |
| `list` / `ordered_list` / `unordered_list` | `<ol>` or `<ul>` |
| `faq_items` | `<details><summary>Q</summary><p>A</p></details>` |
| `ctaButton` | `<p><a href="url">label</a></p>` |
| `table` | `<table>` with `<thead>`/`<tbody>` |

Inline marks (`bold`, `italic`, `code`, `strike`, `underline`, `link`) are applied recursively. Text is HTML-escaped via `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

---

## CP routes

| Action | Method | Route | Purpose |
|---|---|---|---|
| `actionIndex` | GET | `/contentiq-importer` | Dashboard |
| `actionHistory` | GET | `/contentiq-importer/history` | Last 50 import runs |
| `actionUpload` | GET | `/contentiq-importer/upload` | File picker |
| `actionPreview` | POST | `/contentiq-importer/preview` | Dry-run, returns preview |
| `actionRunImport` | POST | `/contentiq-importer/import` | Execute import, redirect to result |
| `actionResult` | GET | `/contentiq-importer/result/<id>` | Import report |
| `actionSync` | GET | `/contentiq-importer/sync` | API sync screen |
| `actionRunSync` | POST JSON | `/contentiq-importer/sync/run` | Push SyncJob, return runId |
| `actionSyncStatus` | GET JSON | `/contentiq-importer/sync/status?runId=N` | Poll run status |
| `actionSyncResult` | GET | `/contentiq-importer/sync/result/<id>` | Hierarchical sync report |
| `actionWidgetSync` | POST JSON | `/contentiq-importer/widget-sync` | Single-entry sync from sidebar |
| `actionToggleLock` | POST JSON | `/contentiq-importer/toggle-lock` | Lock/unlock entry |
| `actionClearNotes` | POST JSON | `/contentiq-importer/clear-notes` | Clear block notes |

---

## Queue sync flow

1. User clicks Sync in CP → `run-sync` POST creates a pending run record, pushes `SyncJob`
2. Frontend polls `/sync/status?runId=N` every ~1s
3. `SyncJob` executes:
   - `chdir(@webroot)` for asset path resolution
   - `ContentIQApiService::fetchExport()`
   - Loop through pages: check lock, import, apply hierarchy, update progress
   - Auto-lock all successfully imported entries
   - Write final status + JSON result to run record
4. Frontend detects status change from `pending`, redirects to `/sync/result/<id>`

---

## Sidebar widget

`ContentIQImporter.php` hooks into `Entry::EVENT_DEFINE_SIDEBAR_HTML` to append a CONTENTIQ fieldset to every entry edit screen.

**Features:**

- **Lock toggle** — CSS-only lightswitch, persisted to `contentiq_entry_syncs.locked`. Locks skip the entry during batch syncs. Does not require Craft's JS lightswitch (which doesn't initialise reliably in dynamically injected HTML).
- **Sync button** — AJAX POST to `/widget-sync`. Fetches `/api/v1/pages/{slug}/export`, runs the full import pipeline, updates timestamp inline.
- **Reload link** — appears after successful sync so editors can refresh entry fields.
- **Block notes** — developer notes collected from `block.notes` during import. Displayed below timestamp with a Clear button.

All AJAX is handled by inline `<script>` tags rendered via Twig. Element IDs in DOM nodes include the entry ID to avoid collisions.

---

## Database schema

### `contentiq_import_runs`

| Column | Type | Notes |
|---|---|---|
| `id` | int PK | |
| `importedBy` | int FK → users | Nullable |
| `filename` | varchar(255) | Original filename or `'sync'` |
| `type` | varchar(10) | `'single'`, `'batch'`, `'sync'` |
| `pageCount` | int | |
| `imageCount` | int | |
| `status` | varchar(20) | `'pending'`, `'success'`, `'warnings'`, `'errors'` |
| `result` | longtext | JSON-encoded page results array |
| `dateCreated` | datetime | |
| `dateUpdated` | datetime | |
| `uid` | varchar(36) | |

### `contentiq_entry_syncs`

| Column | Type | Notes |
|---|---|---|
| `element_id` | int PK, FK → elements | |
| `locked` | boolean | Skip during batch syncs |
| `synced_at` | datetime | Last successful sync |
| `notes` | text | Aggregated `block.notes` from last sync |

---

## Key design decisions

**No drafts.** Saves directly to canonical entries with `saveElement($entry, false)`. Re-importing overwrites in place. The draft approach was tried early and caused images to appear in the DB but be invisible in the CP (which shows canonical, not drafts).

**Skip validation.** The `false` flag on `saveElement()` bypasses MatrixBlockAnchorField uniqueness failures on nested content.

**Idempotent images.** Same filename in same folder = reuse. Craft converts spaces to hyphens on save, so lookups use `Assets::prepareAssetName()` for consistency.

**Declarative block map.** `defaults.php` is pure data. Adding a new block type requires no code changes — only a new entry in the map.

**Handler functions.** Named transform functions (not closures, not subclasses) keep MatrixBuilder readable and testable.

**Lock flag.** Editors can freeze entries so re-syncs don't overwrite manual edits. Batch syncs log "Skipped — entry is locked" for locked entries.

**Field layout filtering.** All field values are filtered against the entry's actual field layout before `setFieldValues()`. Unknown handles throw exceptions in Craft 5.

**CLI webroot.** `chdir(Craft::getAlias('@webroot'))` is required before asset operations in CLI context. Local filesystem volume paths are relative to `web/`, not the project root.

---

## Config reference

```php
// config/contentiq.php
return [
    'section'            => 'pages',        // Entry section handle
    'entryType'          => 'pages',        // Entry type handle
    'homepageSection'    => 'homepage',     // Section for is_homepage pages
    'homepageEntryType'  => 'homepage',     // Entry type for homepage
    'assetVolume'        => 'images',       // Asset volume handle
    'assetFolder'        => 'contentiq',    // Folder in volume
    'matrixField'        => 'contentBlocks',
    'seoField'           => 'seo',
    'slugMap'            => [],             // Craft slug → ContentiQ slug
    'blockOverrides'     => [],             // Replaces defaults.php entries
];
```

---

## Error handling

| Condition | Behaviour |
|---|---|
| Invalid JSON | Fatal, exit 1 |
| Section / entry type not found | Fatal, exit 1 |
| Asset volume not found | Fatal, exit 1 |
| Unknown block type | Skip, warn, continue |
| Image download fails | Skip field, warn, continue |
| Field handle not in layout | Skip field, warn, continue |
| Entry save fails | Fatal, log nested validation errors |

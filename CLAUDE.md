# ContentIQ Craft Import — Settled Patterns

Read this at the start of every session. Do not re-discover these patterns.

---

## Releasing

When tagging a new version, always create a matching GitHub release with human-readable notes. Never tag without a release.

```bash
git tag 1.x.0
git push origin main --tags
gh release create 1.x.0 --title "1.x.0" --notes "- What changed"
```

Release notes should be a bullet list describing what changed in plain language — not commit messages verbatim. One bullet per logical change, not per commit.

---

## Local development workflow

The plugin is published to Packagist as `matrixcreate/contentiq-craft-import`. Craft Starter installs it from there by default. To develop the plugin and a Craft project simultaneously, run these from the Craft project root:

**Switch to local symlinked copy:**
```bash
composer config repositories.contentiq '{"type":"path","url":"../contentiq-craft-import","options":{"symlink":true}}' && composer require matrixcreate/contentiq-craft-import:@dev
```

**Revert to Packagist:**
```bash
git checkout composer.json composer.lock && composer install
```

Optional shell functions for `~/.zshrc` (aliases break on the nested quoting):
```bash
cdp-local() {
  composer config repositories.contentiq '{"type":"path","url":"../contentiq-craft-import","options":{"symlink":true}}' && composer require matrixcreate/contentiq-craft-import:@dev
}
cdp-packagist() {
  git checkout composer.json composer.lock && composer install
}
```

The path repo with `symlink: true` means edits in this plugin directory are instantly live in the Craft project.

**Never commit the path repo.** The `git checkout` in the revert step ensures `composer.json` and `composer.lock` are always clean before pushing. Staging deployments run `composer install` from the clean committed state and pull from Packagist.

**The `git checkout` only works if the path repo was never committed.** If it was accidentally committed (e.g. via `git add -A`), `git checkout` restores HEAD — which already contains the path repo — and `composer install` will still fail on any machine without that local path. Fix: manually remove the `repositories` entry and `@dev` constraint from `composer.json`, run `composer update matrixcreate/contentiq-craft-import`, then commit and push both files.

---

## Do not use drafts

The importer saves **directly to the canonical entry** — no draft creation. Early versions used `getDrafts()->createDraft()`, which caused images to appear in the DB but be invisible in the CP (the CP shows the canonical, not the draft). This wasted days of debugging.

- Existing entries: `$existing->setFieldValues($values)` then `saveElement($existing, false)`
- New entries: `new Entry()`, set fields, `saveElement($entry, false)` — no `DraftBehavior`

---

## CLI webroot requirement

`ImportController` must call `chdir(Craft::getAlias('@webroot'))` before any asset operations. Without this, local filesystem volume paths (e.g. `assets/cms/images`) don't resolve in CLI context — they're relative to `web/`, not the project root.

---

## Saving nested Matrix data

### Data shape

```php
$entry->setFieldValue('contentBlocks', [
    'new1' => [
        'type'   => 'textAndMedia',
        'fields' => [
            'blockLayout' => 'text-left',
            'textAndMediaBlocks' => [
                'new1' => [
                    'type'   => 'textAndMediaBlock',
                    'fields' => [
                        'richText' => '<p>HTML string</p>',
                        'image'    => [144],
                    ],
                ],
            ],
        ],
    ],
]);
Craft::$app->getElements()->saveElement($entry, false);
```

### Rules

- **Keys** must start with `'new'` (`'new1'`, `'new2'`). Integer keys are treated as existing entry IDs.
- **Assets fields**: `[int]` array. Not bare int, not string, not nested array. `[]` clears the field.
- **CKEditor fields**: HTML string. Wrap plain text in `<p>` tags.
- **Save with `false`**: skip validation to bypass MatrixBlockAnchorField uniqueness failures.
- No nesting depth limit — Craft's `afterElementPropagate` chain is recursive.

---

## DIFF-AWARE Matrix writes (`preserveBlockIdentity`)

**Config key**: `config/contentiq.php` → `'preserveBlockIdentity' => true`. **Defaults to `false`.** Read in `ImportService::_getConfig()`'s defaults array and `importPage()` (`$preserveBlockIdentity = (bool)($config['preserveBlockIdentity'] ?? false);`), same pattern as `matrixField`/`seoField`/etc.

### Why it's off by default

Every sync used to rebuild the whole `contentBlocks` Matrix with `'new*'` keys, so Craft deleted and recreated every nested block on every save (per "Saving nested Matrix data" above: only an **integer** key updates an existing nested entry in place). That churns the DB and — worse — soft-deletes nested block elements, which cascades to and destroys any editor **provisional draft** attached to those blocks.

This flag makes MatrixBuilder/ImportService reuse an unchanged TOP-LEVEL block's existing nested element id (so Craft updates it in place) instead of always emitting `'new*'`. It rewrites the core Matrix save path and **cannot be integration-tested outside a live Craft instance** (this repo only has standalone pure-PHP test scripts — no Craft runtime/test harness). Leave it off until a human has run through the LIVE-VALIDATION CHECKLIST below on a real Craft instance. When off (or when a block doesn't qualify — see Scope), behaviour is byte-identical to before this feature existed: every key is `'new*'`.

### How it works

1. `ImportService::importPage()` resolves the owner entry (`findExistingEntry()`) **before** calling `MatrixBuilder::build()` (moved up from its previous spot specifically for this), then loads `contentiq_block_syncs` for that owner into `block_id => nested_element_id`.
2. That map is passed into `MatrixBuilder::build(..., $existingBlockMap)`. For each **top-level, non-grouped** block whose payload `id` is a key in the map, `build()` emits the mapped **int** element id as the Matrix key instead of `'new{N}'`. Every other block (no stable id, no mapping row, grouped, or a defensive guard trip — see `_resolveTopLevelKey()`) still gets `'new{N}'`.
3. `build()` also returns `blockKeyConsumption`: emitted key => the payload block id(s) it represents, in emission order.
4. After a successful save (existing-entry OR newly-created entry — a new entry gets its map recorded too, so the *second* sync can preserve identity), `ImportService::_recordBlockSyncMap()` zips `blockKeyConsumption` against the owner's saved top-level blocks in the same order (`$owner->getFieldValue($matrixHandle)->status(null)->all()` — Craft preserves the order the keys were provided in) to recover each block's real nested element id, upserts the map, and prunes rows for block ids no longer present. This is bookkeeping only, wrapped in its own try/catch — a failure here **cannot** fail the page; the next sync just rebuilds from scratch.
5. The pre-existing empty-matrix guard (2d in `importPage()`) is left intact and takes priority: when it omits the matrix handle entirely (page had no importable blocks, existing blocks preserved), `contentiq_block_syncs` is **not** touched — nothing changed.

### Scope — identity is preserved ONLY for top-level, non-grouped blocks

- **Inner/nested blocks** (`textAndMediaBlock`, `accordionItem`, `card`, `actionButton`, …) **always** use `'new*'` — only the OUTER block's identity is preserved. Preserving the outer element id already saves its drafts/revisions; inner churn is accepted for this pass.
- **Grouped blocks** (consecutive `text_and_media` merged into one outer entry — see "Text & Media grouping" above) **always** recreate (`'new*'`). Identity would have to be keyed on the group as a whole, and the grouping shape (which blocks merged together) can change between syncs — an ambiguous case. A recreate is safe (today's behaviour); a wrong-id reuse would be corruption.
- A block with **no stable `id`** in the payload, or **no existing mapping row** (first sync, or a genuinely new block), falls back to `'new*'` — and gets recorded this run so the *next* sync can match it.
- **Residual risk, not checked**: reuse does not verify the mapped element is still the same Craft entry TYPE the block would produce (e.g. block type changed at the same payload position between syncs). MatrixBuilder has no cheap way to check a live element's type without an extra Craft query per top-level block. Test this explicitly (see checklist) before relying on the flag for content that gets restructured.

### LIVE-VALIDATION CHECKLIST (run on a real Craft instance before enabling)

- [ ] Unchanged block on re-sync keeps its element id **and** any editor provisional draft attached to it.
- [ ] Editing a block's content updates the same nested entry in place (no new row in `elements`/`entries`).
- [ ] Adding a new block appears correctly (gets a `'new*'` key this sync, a real id recorded for next sync).
- [ ] Removing a block deletes it (and its `contentiq_block_syncs` row is pruned).
- [ ] A grouped `text_and_media` run round-trips correctly (still recreates every sync — confirm this is acceptable, not a regression from the ungated code path).
- [ ] Reordering blocks in the payload re-syncs correctly (position isn't part of identity — only `id` is).
- [ ] Changing a block's type at the same payload position does not corrupt the previously-mapped element (the residual risk above) — confirm Craft either rejects the mismatched save cleanly or that this scenario doesn't occur in practice for this project's content.

---

## SEOmatic SeoSettings field

Single field (handle: `seo`, type: `SeoSettings`). Not individual handles per meta tag.

```php
$entry->setFieldValue('seo', [
    'metaGlobalVars' => [
        'seoTitle'       => 'Page title',
        'seoDescription' => 'Description',
        'ogTitle'        => 'OG title',
        'ogDescription'  => 'OG description',
        'canonicalUrl'   => 'https://...',
    ],
    'metaBundleSettings' => [
        'seoTitleSource'       => 'fromCustom',
        'seoDescriptionSource' => 'fromCustom',
        'seoImageSource'       => 'fromAsset',
        'seoImageIds'          => [144],
        'ogImageSource'        => 'fromAsset',
        'ogImageIds'           => [144],
    ],
]);
```

Empty strings are valid. Omit `seoImageIds`/`ogImageIds` keys entirely if no image.

---

## Hyper link fields (Verbb)

`actionButton` is a Hyper field (`verbb\hyper\fields\HyperField`). Set via serialized array — `normalizeValue()` accepts the raw format directly.

```php
$entry->setFieldValue('actionButton', [
    [
        'type'      => 'verbb\\hyper\\links\\Url',
        'handle'    => 'default-verbb-hyper-links-url',
        'linkValue' => 'https://example.com',
        'linkText'  => 'Button Label',
    ],
]);
```

Link types and handles (from `actionButton` field config):
- `verbb\hyper\links\Url` → handle `default-verbb-hyper-links-url`
- `verbb\hyper\links\Entry` → handle `default-verbb-hyper-links-entry`
- `verbb\hyper\links\Email` → handle `default-verbb-hyper-links-email`
- `verbb\hyper\links\Asset` → handle `default-verbb-hyper-links-asset`
- `verbb\hyper\links\Custom` → handle varies (`7NRQUzTeW9` for Call Now, `aBaytLJksn` for In-Page Link)

Base properties on `Link`: `linkValue`, `linkText`, `ariaLabel`, `urlSuffix`, `linkTitle`, `classes`, `customAttributes`, `newWindow`, `fields`.

---

## Entries relation fields

`chooseCallToAction` is `craft\fields\Entries`. Set as `[$entryId]` — same format as Assets.

```php
// On the outer callToAction contentBlock:
'fields' => ['chooseCallToAction' => [$ctaEntryId]]
```

The CTA workflow: import creates a `callToActionEntry` in the `callsToAction` section (channel), then relates it via the `chooseCallToAction` field on the `callToAction` contentBlock.

---

## Asset import

`ImageImportService::importFromField()` — idempotent by filename in target folder.

Critical requirements:
- `$asset->newLocation = "{folder:{$folderId}}{$filename}"` — SCENARIO_CREATE requires this
- `$asset->setScenario(Asset::SCENARIO_CREATE)` — must be set
- `$asset->tempFilePath` — downloaded file path
- Orphaned files (on disk but not in DB) are cleaned up before save

---

## Block type mappings (defaults.php)

### Standard blocks (MatrixBuilder handles these)

| ContentIQ type | Outer entry type | Inner Matrix | Inner type | Mode | Outer fields |
|---|---|---|---|---|---|
| `text` | `text` | `textBlocks` (second column only) | `textBlock` | text_columns | `columnLayout`, `richText` (first column — see below) |
| `text_and_media` | `textAndMedia` | `textAndMediaBlocks` | `textAndMediaBlock` | grouped | `blockLayout` |
| `faq` | `faq` | `accordionItems` | `accordionItem` | repeated | `richText`, `extraRichText`, `actionButtons` (via `faqNodes`) |
| `cards` | `entryCards` | `entryCards` | `card` | repeated | `richText` (intro) |
| `price_list` | `priceList` | *(none)* | — | outer fields only | `richText`, `priceList`, `actionButtons` (via `buttonNodes`) |
| `usp` | `contentiqUsp` | *(none)* | — | outer fields only | `uspText` |
| `global` | `contentiqGlobal` | *(none)* | — | outer fields only | `contentiqNotes` |
| `collection_listing` | `collectionListing` | *(none)* | — | outer fields only | `richText` (intro nodes), `listingSection` (collection slug → section handle via `content_types` map; unmapped slug stored raw + page warning) |

### Special blocks (ImportService handles these)

| ContentIQ type | What happens |
|---|---|
| `hero` | ContentBlock field `hero` on page entry: `heading`, `richText` (subheading + body), `desktopImage`, `mobileImage`, `actionButtons`, `heroStyle` (`textImage`/`textOnly`, defaults to `textImage`). Sets `enableHero = true`. |
| `call_to_action` | Creates `callToActionEntry` in `callsToAction` section, relates via `chooseCallToAction` |
| `table` | Skipped (no block type in Craft Starter) |

### Modes

- **single**: one inner entry from the block's fields
- **repeated**: one inner entry per item in a `sourceKey` array
- **grouped**: consecutive blocks of the same type merge into one outer entry with multiple inner entries (e.g. text_and_media)

### Handler types

| Handler | Input | Output |
|---|---|---|
| `nodes` | `ContentNode[]` | HTML string via NodesRenderer |
| `mediaNodes` | `ContentNode[]` with `ctaButton` nodes | richText HTML + `actionButtons` Matrix (ctaButtons lifted out of the HTML) |
| `mediaType` (`textMediaMedia`) | whole inner block (`_block`) | `mediaType` + image routed to `image` or `desktop`/`mobileBackgroundImage` |
| `image` | `{key, url, alt}` | asset ID array `[$id]` |
| `heading` | `{level, text}` or string | `<hN>text</hN>` |
| `body` | plain string | `<p>text</p>` (legacy — use `nodes` for structured content) |
| `layout` | string | pass through |
| `textMediaLayout` | `image_right`/`image_left` | `text-left`/`image-left` |
| `tableHtml` | `[{isHeader, cells}]` | `<table>` HTML string |
| `hyperButton` | `{label, url}` | Hyper link field array + `showLinkAsSeparateButton: true` |
| `faqNodes` | `ContentNode[]` with `faq_items` | splits into `richText`, `extraRichText`, `actionButtons`, `_faqItems` |
| `buttonNodes` | `ContentNode[]` with `ctaButton` nodes | extracts buttons into `actionButtons` Matrix entries |
| `collectionSection` | ContentIQ collection slug string | Craft section handle (via `content_types` map; unmapped slug stored raw + page warning) |
| `collectionListingNodes` | `ContentNode[]` | HTML string via NodesRenderer, minus paragraphs that are whole bracketed listing placeholders (`[Blog Listing]`, `[Listing Grid]`, …) — the rendered listing takes that space |

### NodesRenderer supported node types

| Node type | Output |
|---|---|
| `heading` | `<h1>`–`<h6>` (clamped) |
| `paragraph` | `<p>` |
| `blockquote` | `<blockquote><p>…</p></blockquote>` (flat node, `_renderNode` path — prefers `content` like `paragraph`, falls back to `text`; empty → `''`) |
| `list` | `<ul>` or `<ol>` (based on `ordered` flag) |
| `ordered_list` | `<ol>` (legacy alias) |
| `unordered_list` | `<ul>` (legacy alias) |
| `faq_items` | `<details><summary>question</summary><p>answer</p></details>` |
| `ctaButton` | `<p><a href="url">label</a></p>` (URL always empty from ContentIQ — editors set it in CMS) |

`_renderDocNode` (the raw ProseMirror `content` path, used for collection children) additionally supports `blockquote` as the NESTED ProseMirror shape (`{type:'blockquote', content:[...]}`, block children not inline text) — it wraps `<blockquote>` around each child block rendered through the same recursion, so nested `<p>`/`<ul>` etc. keep their own markup. Empty → `''`. This is a separate arm from the `paragraph`/`heading`/`list` handling above — the two rendering paths take genuinely different input shapes for the same node type name and are not shared.

---

## Text block columns

The Craft Starter's `text` entry type carries the first column's Rich Text **itself** (`richText` on the outer entry); `textBlocks` holds at most one *further* column (`maxEntries: 1`). Before that change every column was an inner `textBlock`, min 1 / max 2.

`text_columns` mode therefore routes as:

| ContentIQ block | Outer `richText` | `textBlocks` |
|---|---|---|
| `singleColumn` | all nodes | *(empty)* |
| `twoColumns`, heading found | nodes up to and including the first heading | one inner block with the remainder |
| `twoColumns`, no heading | all nodes | *(empty)* |

Two things are load-bearing:

- **The empty `textBlocks` array is emitted deliberately.** `['textBlocks' => []]` is what makes Craft delete inner blocks a previous import left behind. Omitting the key would leave a stale block that renders as a phantom second column.
- **The outer field is probed before it's written to** (`MatrixBuilder::_entryTypeHasField()`, memoized). Starter forks that predate the split have no `richText` on the `text` entry type, and setting an unrecognised handle inside Matrix data is swallowed by Craft — `Matrix::_createEntriesFromSerializedData()` catches `InvalidFieldException` — so the column would vanish with no error. When the probe comes back negative the importer falls back to the pre-split shape (both columns as inner blocks) and raises one page warning naming the entry type and field. Same guard shape as the hero `heroStyle` compatibility check.

`firstColumnField` in the mapping is what turns lifting on. Drop that key (per-project `blockOverrides`) and the block reverts to the pre-split behaviour with no warning.

Existing Craft content still has the old shape until the starter's `m260821_113000_lift_text_block_rich_text` content migration is run — it moves the first inner block's Rich Text (and any CKEditor chips it owns) up onto the outer block, then soft-deletes it.

---

## Text & Media grouping

Consecutive `text_and_media` blocks in the JSON are merged into a single `textAndMedia` outer entry with multiple `textAndMediaBlock` inner entries. The outer entry's `blockLayout` field is set from the first block's `layout` value. The CMS template handles alternating image positions automatically.

A non-`text_and_media` block (e.g. faq, price_list) breaks the consecutive run and starts a new group.

Each inner `textAndMediaBlock` carries its own `actionButtons` Matrix. `ctaButton` nodes in the block content are lifted out of `richText` into that Matrix via the `mediaNodes` handler (same `actionButton` entry shape as faq/price_list/hero) — they are not rendered as raw `<a>` tags.

### Media type (Image vs Background Image)

ContentiQ's Text & Media `layout` value drives the inner block's Craft `mediaType` Dropdown (the `textMediaMedia` handler, fed the whole block via `_block`):

- `image_left` / `image_right` → `mediaType = image`; image set on the `image` field. Left/right position comes from the **outer** entry's `blockLayout` (`text-left` / `image-left`), taken from the first block in the group.
- `background` → `mediaType = backgroundImage` (the template renders these as a CSS background and ignores `image`). The block's `image` → `desktopBackgroundImage`; the optional `mobile_image` → `mobileBackgroundImage`, **falling back to the desktop image** when no mobile image was supplied (so mobile never drops to the global placeholder). Position is irrelevant for background, so the outer `blockLayout` falls back to `text-left`.

`mediaType` is per-inner-block; `blockLayout` (position) is per-outer-group. A group with mixed layouts keeps each block's own media type but shares one position derived from the first block.

---

## Call to Action entry creation

The importer creates entries in the `callsToAction` section (channel):
- **Section**: `callsToAction` (handle)
- **Entry type**: `callToActionEntry` (handle)
- **Fields**: `title`, `richText` (CKEditor), `image` (Assets), `actionButtons` (Matrix → `actionButton` entries with Hyper `actionButton` field)
- **Idempotent**: matches by title; existing entries are **updated** on re-import (not just returned) so re-syncs fix stale data

The `actionButtons` field is a Matrix containing `actionButton` entry types. Each has one field: `actionButton` (Hyper). Buttons include `linkClass: 'btn btn-primary'`. Buttons where both label and URL are empty are skipped.

Buttons come from `ctaButton` nodes in `fields.nodes` (same pattern as faq/price_list blocks). The `_resolveCtaEntry` method filters ctaButton nodes out of the nodes array before rendering richText, and builds `actionButtons` from them. Falls back to a flat `fields.buttons` array for legacy data.

---

## Import command

```
php craft contentiq-importer/import --file=export.json [--dry-run] [--verbose]
```

Supports single-page (top-level `blocks`) and batch (top-level `pages`) JSON formats. `$defaultAction = 'import'` so the repeated `/import` suffix is not needed.

### Per-entry widget sync

The sidebar widget calls `GET /api/v1/pages/{slug}/export` for single-page sync (the project is inferred from the API key). Slug is mapped via `config/contentiq.php` `slugMap` when Craft and ContentIQ slugs differ.

### Locked entries

Entries with `contentiq_entry_syncs.locked = true` are skipped during:
- Batch syncs (SyncJob) — logged as "Skipped — entry is locked" in the report
- The sidebar Sync button is disabled when locked

Lock state is toggled via the CSS lightswitch in the sidebar widget and persisted immediately via `contentiq-importer/cp/toggle-lock`.

---

## Plugin settings (Craft 5 pattern)

Settings are stored via Craft's built-in plugin settings mechanism (project config).

```php
// Model: src/models/Settings.php
class Settings extends \craft\base\Model
{
    public string $contentiqUrl = '';
    public string $apiKey = '';
}

// Plugin class:
public bool $hasCpSettings = true;

protected function createSettingsModel(): ?Model
{
    return new Settings();
}

protected function settingsHtml(): ?string
{
    return Craft::$app->view->renderTemplate(
        'contentiq-importer/_cp/settings',
        ['settings' => $this->getSettings()],
    );
}

// Access anywhere:
$settings = ContentIQImporter::$plugin->getSettings();
```

Template uses `autosuggestField` with `suggestEnvVars: true` so editors can reference environment variables (e.g. `$CONTENTIQ_API_KEY`). Project config stores the env var reference, not the secret. At runtime, always resolve with `App::parseEnv()` before using the value:

```php
use craft\helpers\App;
$url = App::parseEnv($settings->contentiqUrl);
$key = App::parseEnv($settings->apiKey);
```

The `settings` namespace is handled automatically by Craft's settings response.

### API key format and project inference

The API key embeds the project slug: `ciq_{project-slug}_{32chars}`. The plugin no longer stores `projectSlug` as a separate setting — it's inferred from the key at runtime:

```php
// Strip "ciq_" prefix, find last underscore, everything before it is the slug
$withoutPrefix = substr($apiKey, 4);
$lastUnderscore = strrpos($withoutPrefix, '_');
$slug = substr($withoutPrefix, 0, $lastUnderscore);
```

API endpoints are now slug-free (`/api/v1/export`, `/api/v1/pages/{page}/export`) — the server resolves the project from the Bearer token. Old `projectSlug` values in existing project config YAML are harmless but ignored.

---

## Collection mappings (content_types precedence)

Collection slugs (ContentiQ `document.content_type` / `collection_listing` slugs) route to Craft sections via a three-layer merge in `ImportService::_getContentTypesMap()`:

```
defaults.php  ←  settings.collectionMappings (CP UI)  ←  config/contentiq.php 'content_types'
```

Per-slug replace at each layer via `array_replace` — a later layer replaces a slug's whole definition. The **config file wins** (dev escape hatch). Settings rows with an empty/missing `section` are filtered before merging, so an empty `collectionMappings` yields a byte-identical result to the old defaults ← file merge.

- **UI storage**: `Settings::$collectionMappings` (`slug => ['section', 'entryType', 'contentField', 'headingField'|null]`), saved via `Craft::$app->getPlugins()->savePluginSettings()` into **project config** — same mechanism as url/apiKey. The Settings model's `validateCollectionMappings()` normalises rows and drops any with no section.
- **CP screen**: `ContentiQ → Mappings` (`CpController::actionMappings` / `actionSaveMappings`, template `_cp/mappings.twig`). Renders one row per project collection from `ContentIQApiService::fetchGlobals()` (`data.globals.collections[]`), unioned with any settings slug no longer on the wire (badged "not in ContentiQ"). Dropdowns cascade section → entry type → CKEditor content field / PlainText heading field via an embedded JSON map. API failure still renders from stored settings with a banner.
- Rows whose slug is in the config file's `content_types` render **read-only** ("defined in config file"); they carry no inputs, so saving never persists them to settings.
- Both `MatrixBuilder::_handleCollectionSection` and the globals drift check consume `getContentTypesMap()`, so they inherit the merged result — no duplication.

---

## Hierarchy / parent entries (Structure sections)

For entries in Structure sections, set the parent after the entry is saved:

```php
// By parent entry ID:
$entry->setParentId($parentId);
Craft::$app->getElements()->saveElement($entry, false);

// Or by parent object (auto-sets level):
$entry->setParent($parentEntry);
Craft::$app->getElements()->saveElement($entry, false);
```

The batch importer maintains a `$slugToEntryId` map during the run so parent lookups don't require DB queries. Pages must be sorted depth-first (parents before children) in the JSON.

If `parent_slug` is present in `document`, the importer sets the parent. If the parent slug isn't found in the map, a warning is logged and the entry is saved at root level.

---

## ContentIQ API sync

The sync flow uses Craft's queue system to avoid HTTP timeouts:

1. Controller creates a `pending` import run record
2. Pushes `SyncJob` to Craft's queue with the run ID
3. Frontend polls `sync/status?runId=N` until status changes from `pending`
4. Queue job: fetches `GET /api/v1/export` via `ContentIQApiService`, imports each page, updates the run record

API endpoints are slug-free — the project is resolved server-side from the Bearer token (see "API key format and project inference" above). API call uses `Craft::createGuzzleClient()` — the recommended HTTP client in Craft 5.

```php
$response = Craft::createGuzzleClient()->request('GET', $endpoint, [
    RequestOptions::HEADERS => [
        'Authorization' => "Bearer {$apiKey}",
        'Accept'        => 'application/json',
    ],
    RequestOptions::TIMEOUT => 120,
]);
```

---

## Globals import

The batch/sync envelope may carry a top-level `globals` key (company, offices, branding, social networks, trust signals, scripts). `GlobalsImportService::import($globals, dryRun)` writes it into the `offices` section and the `companyInfo`, `globalContent`, and `siteConfig` global sets. Not part of the per-page pipeline — invoked separately by `SyncJob` and dry-run by the CP preview.

### Lock / consent model

Globals are gated by the single-row `contentiq_globals_sync` table (missing row ⇒ locked). Unlike per-entry locks, this is per-run consent:

- The Sync screen shows one globals lightswitch. Ticking it POSTs `unlockGlobals` to `run-sync`, which sets `locked = false` for this run only (`CpController::_setGlobalsLock`).
- `SyncJob` imports globals only when unlocked, then immediately relocks and stamps `synced_at` (`_relockGlobals`) — so consent never persists across syncs.
- The upload/import path deliberately SKIPS globals (no consent UI); it flashes "Globals present in file — use Sync to import globals." The CP preview dry-runs globals for display only.

### Sync-owned field boundary

`GlobalsImportService::OFFICE_FIELDS` is the exhaustive list of office handles the sync writes — nothing outside it is ever touched. Every write (offices and global sets) is filtered against the target's field layout via `_filterToLayout()`, so a handle missing on an older project is skipped with a report note instead of throwing. Offices are upserted through `contentiq_office_syncs`; title-matched unmapped entries are adopted, vanished wire ids are deleted, hand-made offices are left alone and surfaced as `unmatched`. Per-entry locks on offices are ignored (globals aren't gated by them) but noted in the report.

### Transforms helper + drift warnings

`helpers/GlobalsTransforms.php` holds the pure, Craft-free transforms (address split, opening hours, country→ISO, url-prefix drift) — unit-tested by `tests/run-transforms.php`. `checkUrlPrefixDrift()` runs read-only on every sync (even when locked): it compares each exported collection `url_prefix` against its mapped section `uriFormat` and emits advisory warnings; it never mutates section settings or project config.

---

## Cards block

Cards from ContentIQ import directly to `entryCards`. Inner entry type is `card`.

Field mapping (inner entries): `cardTitle` (heading), `cardText` (CKEditor, nodes), `cardImage` (asset), `actionButtonLabel` (CKEditor, label text only — editor sets the URL in the CMS). The `contentiqNotes` field on the outer `entryCards` entry is populated automatically from `block.notes`.

Card body fields are `ContentNode[]` arrays (not plain strings), processed through `NodesRenderer`. This supports paragraphs, lists, and embedded FAQ items in card content.

The `intro` field on the outer `entryCards` entry is also a `ContentNode[]` array, rendered to the `richText` CKEditor field above the card grid.

### Cards modes and two-pass resolution

ContentiQ exports `fields.mode`: `detected` (inline card items, imported as manual cards — above), `pages` (a list of page refs), or `children` (a parent page ref whose children become cards). Pages/children modes carry no card items — MatrixBuilder records a deferred ref set per block (`result['cardRefs']`, in memory only) and pass 2 resolves them once the whole run's slug → entry ID map is complete.

Pass 2 is `ImportService::resolveCardReferences(array $allCardRefs, array $slugToEntryId, bool $dryRun = false): array<int, string[]>` (warnings keyed by owner entry ID). It runs from **every** entry point: SyncJob, CLI import (batch and single), CP upload, and the sidebar widget sync (DB-lookups-only there). Blocks are located by `blockIndex` and saved directly as elements — the owner entry is never re-saved.

Resolution per mode:
- `pages`, 2+ refs → `entries` relation in order.
- `pages`, single ref (D6) → one manual `card` row with `entry` + `useEntryCardDetails: true`, so Craft's single-entry auto-expansion never fires.
- `children`, parent == host page → `useChildPages: true` in pass 1, nothing deferred.
- `children`, arbitrary parent → `entries = [parent]` (template expands to its children).

### Children-mode manual-card fallback

When a children-mode parent slug resolves nowhere (page not in the batch, not in Craft — i.e. not ready for export), the block is NOT left empty. The deferred ref retains the raw `intro` nodes, and `MatrixBuilder::buildChildrenFallbackCards()` parses them back into cards using the same detected-mode rule as ContentiQ's serialisers (card heading level = most prominent level ≥ 2 appearing 2+ times):

- Block becomes `cardsInThisBlock: manual`; one `card` row per heading (`cardTitle`, body → `cardText`, ctaButton → `actionButtonLabel`; no images — the template falls back to the global placeholder).
- `richText` is rewritten to only the true intro (nodes before the first card heading) so card content isn't duplicated as prose.
- The warning tells the editor to unlock and re-sync once the parent is exported — the next sync rebuilds the block in automatic mode, so the fallback self-heals.
- No repeating heading pattern in the intro ⇒ no fallback (old behaviour: warning, empty block).

Intro sweep caveat: ContentiQ's pages/children serialisers put *everything* in the marked range into `intro` — including card-ish prose. On the success path that prose currently stays in `richText` above the real cards (upstream ContentiQ concern, not fixed here).

---

## Homepage import

Pages with `"is_homepage": true` in the document object import into the `homepage` Single section instead of the `pages` Structure. The importer:

- Looks up the Single entry by section (not slug — Singles always have exactly one)
- Does not overwrite the title
- Skips structure positioning (Singles don't have parents)
- Uses the same `hero` ContentBlock field as pages

---

## Hero ContentBlock field

Both pages and homepage use a `craft\fields\ContentBlock` field (`heroContent`, handle override `hero`) for hero data. The importer builds the ContentBlock value as:

```php
[
    'enableHero' => true,
    'hero' => [
        'fields' => [
            'heading'       => '<h1>Title</h1>',
            'richText'      => '<h2>Subheading</h2><p>Body text</p>',
            'desktopImage'  => [$assetId],
            'mobileImage'   => [$mobileAssetId],  // optional
            'actionButtons' => [
                'new1' => ['type' => 'actionButton', 'fields' => [
                    'actionButton' => [[
                        'type'      => 'verbb\\hyper\\links\\Url',
                        'handle'    => 'default-verbb-hyper-links-url',
                        'linkValue' => 'https://...',
                        'linkText'  => 'Button Label',
                        'linkClass' => 'btn btn-primary',
                    ]],
                ]],
            ],
            'heroStyle' => 'textImage', // 'textImage' | 'textOnly', see below
        ],
    ],
]
```

- `subheading` (optional `{level, text}`) rendered as `<hN>` prepended to body in `richText`
- `mobile_image` (optional `{key, url, alt}`) imported to `mobileImage` asset field
- `buttons` array imported to `actionButtons` Matrix with Hyper link data including `linkClass`
- `hero_style` (`fields.hero_style` in the payload) whitelist-validated to `textImage`/`textOnly`; missing key or any other value defaults to `textImage`. Always set explicitly when the hero block has other content (whole-page-replace sync model — an explicit default beats relying on Craft's field default). ContentBlock shape only — the flat/legacy hero shape (article/caseStudy/team) has no `heroStyle` handle and never receives this key. Guarded against older starter-based sites whose `heroContent` ContentBlock predates the field: `_buildHeroField()` resolves the `hero` ContentBlock field's own nested field layout and only writes `heroStyle` when that layout actually has it (or is unknown), skipping it otherwise — setting an unrecognised handle inside a ContentBlock's nested `fields` throws `yii\base\UnknownPropertyException`, which Craft's own `ContentBlock::_createContentBlockFromSerializedData()` save path does **not** catch (it only catches `InvalidFieldException`).

---

## Slug mapping

When the Craft slug differs from the ContentIQ slug (e.g. homepage → home), configure a mapping in `config/contentiq.php`:

```php
'slugMap' => [
    'homepage' => 'home',
],
```

Used by the sidebar widget sync to translate Craft slugs to ContentIQ API slugs.

---

## Asset filename sanitization

Filenames from ContentIQ keys are sanitized via `craft\helpers\Assets::prepareAssetName()` before the idempotency lookup. Craft converts spaces to hyphens on save (e.g. `Styles - Luxury - Card Image.jpg` → `Styles-Luxury-Card-Image.jpg`), so the lookup must use the sanitized name to find existing assets.

---

## Image downloads use Guzzle

Image downloads use `Craft::createGuzzleClient()` instead of `file_get_contents()`. This handles self-signed SSL certificates on dev domains (e.g. `contentiq.test`) and respects `config/guzzle.php` settings.

---

## Field layout filtering

Always filter field values against the entry's field layout before `setFieldValues()`. Unknown handles throw `"Setting unknown property: CustomFieldBehavior::handleName"`.

```php
$validHandles = array_map(fn($f) => $f->handle, $fieldLayout->getCustomFields());
$filtered = array_intersect_key($fieldValues, array_flip($validHandles));
```

---

## Exploring Craft project config YAML

All field definitions, entry types, sections, and field layouts live in `config/project/`. Understanding how to navigate these files is essential for mapping ContentIQ blocks to Craft fields.

### File naming convention

```
config/project/
  fields/              # Field definitions: {handle}--{uid}.yaml
  entryTypes/          # Entry type definitions + field layouts: {handle}--{uid}.yaml
  sections/            # Section definitions: {handle}--{uid}.yaml
```

The UID in the filename is the canonical identifier — it's stable across environments. Handles can change.

### Finding a field's type and options

```bash
# Find a field by handle
ls config/project/fields/blockLayout--*.yaml

# Read it — key properties:
#   handle: blockLayout
#   type: craft\fields\Dropdown          ← Craft field class
#   settings.options[].value             ← dropdown option values
#   settings.options[].label             ← what editors see
```

### Finding what fields are on an entry type

Entry type YAMLs contain the full field layout under `fieldLayouts.{uid}.tabs[].elements[]`. Each element has:

```yaml
elements:
  - type: craft\fieldlayoutelements\CustomField
    fieldUid: 05e398c0-9d22-47a9-bccc-cef3688bd6e6  # ← look this up in fields/
    handle: blockLayout       # handle override (null = use field's own handle)
    label: 'Block Layout'     # label override
    required: false
```

**The `fieldUid` is the link.** Cross-reference it with `config/project/fields/` to find the field definition:

```bash
# Find which field a UID belongs to
grep -l "05e398c0" config/project/fields/*.yaml
# → fields/textAndMediaBlockLayout--05e398c0-9d22-47a9-bccc-cef3688bd6e6.yaml
```

**Handle override**: if `handle: null` on the field layout element, the field's own handle from its YAML definition is used. If `handle: blockLayout`, that overrides the field's native handle for this entry type. The same field can appear on multiple entry types with different handles.

### Finding what entry types a Matrix field allows

Matrix fields list their allowed entry types in `settings.entryTypes`:

```yaml
# fields/contentBlocks--f3e37f1f-....yaml
settings:
  entryTypes:
    - uid: b31e80bd-...  # Call to Action
    - uid: 2144c2be-...  # Price List
    - uid: ...
```

Cross-reference with `config/project/entryTypes/` to find the entry type definition.

### Finding what section an entry type belongs to

Sections list their entry types:

```yaml
# sections/callsToAction--0f3b9437-....yaml
type: channel
entryTypes:
  - e93e931e-...  # callToActionEntry
```

### Practical workflow for mapping a new block type

1. **Start with the Twig template** — `templates/_content-blocks/{blockType}.twig` shows what field handles the template expects
2. **Find the entry type** — `ls config/project/entryTypes/{blockType}--*.yaml`
3. **Read the field layout** — look at `fieldLayouts.*.tabs[].elements[]` for `fieldUid` references
4. **Look up each field** — `grep -l "{fieldUid}" config/project/fields/*.yaml`
5. **Check the field type** — `type:` in the field YAML tells you what data shape it expects
6. **Verify with DB** — if an entry of this type already exists, query its `elements_sites.content` to see the actual stored format

### Common field types and their data shapes

| Field type | YAML `type:` | Data shape for `setFieldValue` |
|---|---|---|
| CKEditor | `craft\ckeditor\Field` | HTML string |
| Assets | `craft\fields\Assets` | `[int]` array of asset IDs |
| Entries | `craft\fields\Entries` | `[int]` array of entry IDs |
| Matrix | `craft\fields\Matrix` | `['new1' => ['type' => '...', 'fields' => [...]]]` |
| Dropdown | `craft\fields\Dropdown` | string matching `options[].value` |
| Lightswitch | `craft\fields\Lightswitch` | `true` / `false` |
| Hyper | `verbb\hyper\fields\HyperField` | array of link objects (see Hyper section) |
| ColourSwatches | colour swatches plugin | leave as default — importer doesn't set colours |

---

## Adding sidebar content to the entry edit screen

**The correct mechanism is `Entry::EVENT_DEFINE_SIDEBAR_HTML`.**

This is NOT the field layout designer / `BaseUiElement` approach. `BaseUiElement` + `EVENT_DEFINE_UI_ELEMENTS` only adds elements to the field layout designer palette for the main content area — it does not add sidebar content.

`EVENT_DEFINE_SIDEBAR_HTML` is defined in `craft\base\Element` (line 402):
```php
public const EVENT_DEFINE_SIDEBAR_HTML = 'defineSidebarHtml';
```

It fires from `Element::getSidebarHtml()` which is called by `ElementsController` when rendering the entry edit screen. The event fires on the **element instance** being edited (not on a static class), so listen via `Event::on(Entry::class, ...)`.

### Registration pattern

```php
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\base\Element;
use yii\base\Event;

Event::on(
    Entry::class,
    Element::EVENT_DEFINE_SIDEBAR_HTML,
    static function (DefineHtmlEvent $event) {
        /** @var Entry $entry */
        $entry = $event->sender;
        if ($entry === null || !$entry->id) {
            return; // skip new unsaved entries
        }
        // APPEND to $event->html — never replace it
        $event->html .= Craft::$app->view->renderTemplate(
            'my-plugin/_sidebar/widget',
            ['entry' => $entry],
        );
    },
);
```

Register this in `Plugin::init()`. Confirmed working pattern — used by SEOmatic (`nystudio107/craft-seomatic/src/seoelements/SeoEntry.php`).

### Sidebar HTML structure

Craft's native sidebar sections use `<fieldset>` + `<legend class="h6">` wrapping a `<div class="meta">` with `.field` rows inside. This matches what `Element::statusFieldHtml()` produces:

```html
<fieldset>
    <legend class="h6">CONTENTIQ</legend>
    <div class="meta">
        <div class="field">
            <div class="heading"><label>Status</label></div>
            <div class="input ltr"><button ...>Sync</button></div>
        </div>
        <div class="field">
            <div class="heading"><label>Synced at</label></div>
            <div class="input ltr"><span>Never</span></div>
        </div>
    </div>
</fieldset>
```

SEOmatic uses this same pattern in its sidebar Twig templates (e.g. `_sidebars/_includes/sidebar-preview.twig`).

### Inline JavaScript

Render via a Twig template. The view's `renderTemplate()` call returns HTML that Craft injects into the DOM; any `<script>` tags in the output execute after injection. Embed the element ID in widget DOM IDs to avoid collisions when multiple entries are open.

---

## Sidebar widget features

The CONTENTIQ sidebar widget (`EVENT_DEFINE_SIDEBAR_HTML`) provides:

### Lock toggle

CSS-only lightswitch (no Craft JS dependency — Craft's lightswitch requires DOM-ready initialization which doesn't work for dynamically injected sidebar HTML). Stored in `contentiq_entry_syncs.locked`.

- Disables the Sync button when on
- Batch syncs (SyncJob) skip locked entries with a "Skipped — entry is locked" warning
- Persists immediately on toggle via `contentiq-importer/cp/toggle-lock` endpoint

### Block notes

Collected from `block.notes` (top-level key on each block in the API response) during import. Formatted as "Block Type\nnote text" separated by blank lines. Stored in `contentiq_entry_syncs.notes`.

- Displayed below "Synced at" in the sidebar
- "Clear" button removes notes via `contentiq-importer/cp/clear-notes` endpoint
- Updates in place on sync without page reload

### Reload link

After a successful sync, a "Reload" link appears next to the timestamp so the editor can refresh to see updated content in the entry fields.

### Error messages

User-facing error messages use the entry title (not slug) for readability. The widget looks up the title from the element ID.

---

## Database tables

### `contentiq_import_runs`

Stores import/sync history. Created by `Install` migration.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | Auto-increment |
| `importedBy` | int FK → users | Nullable |
| `filename` | varchar(255) | Source filename or `'sync'` |
| `type` | varchar(10) | `'single'`, `'batch'`, or `'sync'` |
| `pageCount` | int | Pages imported |
| `imageCount` | int | Images imported |
| `status` | varchar(20) | `'pending'`, `'success'`, `'warnings'`, or `'errors'` |
| `result` | longtext | JSON-encoded page results array |

### `contentiq_entry_syncs`

Per-entry sync state for the sidebar widget. Created by `m250418_000000_add_entry_syncs_table`.

| Column | Type | Description |
|---|---|---|
| `element_id` | int PK, FK → elements | Entry ID |
| `locked` | boolean | Skip during batch sync when true |
| `synced_at` | datetime | Last successful sync timestamp |
| `notes` | text | Aggregated block notes from last sync |
| `contentiq_page_id` | int, nullable, indexed | ContentIQ `document.id` for this entry. Primary identity key in `ImportService::findExistingEntry()` — checked before the content_type/homepage/slug lookups, so a slug rename (in ContentIQ or in Craft) still resolves to the same entry instead of creating a duplicate. Not unique (legacy rows and multi-site can leave it null/shared). Added by `m260813_000000_add_page_id_to_entry_syncs`. |

### `contentiq_office_syncs`

Maps ContentiQ office ids to their Craft office entries so globals re-syncs update the same entry. Created by `m260712_000000_add_globals_sync_tables`.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | Auto-increment |
| `office_id` | int | ContentiQ office id (unique index) |
| `element_id` | int FK → elements | Craft office entry (CASCADE on delete) |
| `dateCreated` | datetime | |
| `dateUpdated` | datetime | |

### `contentiq_globals_sync`

Single-row consent/lock state for globals syncing. Created by `m260712_000000_add_globals_sync_tables`.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | Auto-increment |
| `locked` | boolean | Default `true`; a missing row is treated as locked |
| `synced_at` | datetime | Last successful globals sync (nullable) |
| `notes` | text | Reserved (nullable) |

### `contentiq_block_syncs`

Maps a (owner entry, payload block id) pair to the TOP-LEVEL nested Matrix element id it produced. Only populated/consulted when the `preserveBlockIdentity` config flag is on (defaults to `false` — see "DIFF-AWARE Matrix writes" above). Created by `m260814_000002_add_block_syncs_table`.

| Column | Type | Description |
|---|---|---|
| `id` | int PK | Auto-increment |
| `owner_element_id` | int, FK → elements (CASCADE) | The page/homepage entry that owns the Matrix field |
| `block_id` | varchar(255) | The payload block's stable `id` (e.g. `"Call to Action-0"`) |
| `nested_element_id` | int, FK → elements (CASCADE) | The top-level nested Matrix element this block id currently maps to |
| `dateCreated` / `dateUpdated` | datetime | |

Unique on `(owner_element_id, block_id)`. Rows are upserted after a successful save and pruned when a block id is no longer produced by a sync — see `ImportService::_recordBlockSyncMap()`.

---

## Sync report tree

The sync report template builds a proper hierarchical tree from `parentSlug` relationships using a recursive Twig macro (`_self.pageRows`). This ensures pages are grouped under their actual parents regardless of the order they appear in the API response.

Each page result includes `parentSlug` (from `document.parent_slug` in the API) alongside `depth` and `title`. The template builds `slugToPage` and `childrenOf` lookup maps, then renders root pages first, recursing into children at each level.

---

## DB is the source of truth

If the CP appears to show empty fields, query the DB:

```sql
SELECT es.content FROM elements_sites es
JOIN elements e ON es.elementId = e.id WHERE e.id = <entry_id>;
```

Matrix field data is NOT in the owner's content column — it's in owned elements. Query `elements_owners` to find inner entries:

```sql
SELECT e.id, et.handle, es.content FROM elements e
JOIN entries en ON e.id = en.id
JOIN entrytypes et ON en.typeId = et.id
JOIN elements_sites es ON e.id = es.elementId
JOIN elements_owners eo ON e.id = eo.elementId
WHERE eo.ownerId = <parent_id> AND e.dateDeleted IS NULL;
```

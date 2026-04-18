# Copydeck Craft Importer — Plugin Spec

Craft CMS 5 plugin that imports Copydeck export JSON directly into published Craft entries. Standalone Composer package — pure Craft 5 PHP.

## Plugin identity

- Package: `matrixcreate/craft-copydeck-importer`
- Handle: `copydeck-importer`
- Namespace: `matrixcreate\copydeckimporter`
- Minimum Craft: 5.0
- Deliverable: a working console command. No CP UI, no settings screen, no front-end assets.

## Console command

```
php craft copydeck-importer/import/import --file=path/to/export.json
php craft copydeck-importer/import/import --file=path/to/export.json --dry-run
php craft copydeck-importer/import/import --file=path/to/export.json --verbose
```

- `--dry-run` validates and reports without writing anything or downloading assets.
- `--verbose` logs each image as it's processed.
- Detects single vs batch from JSON shape: top-level `blocks` = single page, top-level `pages` = batch.

## Plugin structure

```
src/
  CopydeckImporter.php          # Plugin bootstrap, registers 4 services
  console/controllers/
    ImportController.php         # Main import command
    TestMatrixController.php     # Isolated API test (debug tool)
    ApplyDraftController.php     # One-off draft apply (debug tool)
  services/
    ImportService.php            # Pipeline orchestrator
    ImageImportService.php       # Asset download + idempotent import
    NodesRenderer.php            # Copydeck nodes → HTML
    MatrixBuilder.php            # Block mapping → Matrix data array
  config/
    defaults.php                 # Block type mappings
composer.json
CRAFT.md                        # Settled API patterns — read every session
```

## Config

Installed at `config/copydeck.php` in the Craft project. All keys have defaults:

```php
return [
    'section'        => 'pages',
    'entryType'      => 'pages',
    'assetVolume'    => 'images',
    'assetFolder'    => 'copydeck',
    'matrixField'    => 'contentBlocks',
    'seoField'       => 'seo',
    'blockOverrides' => [],
];
```

## Block mappings (defaults.php)

Two-level nested Matrix: contentBlocks (outer) → inner entry types.

| Copydeck type    | Outer entry type | Inner Matrix       | Inner entry type   | Mode     |
|------------------|------------------|--------------------|--------------------|----------|
| `text`           | `text`           | `textBlocks`       | `textBlock`        | single   |
| `text_and_media` | `textAndMedia`   | `textAndMediaBlocks` | `textAndMediaBlock` | single |
| `faq`            | `faq`            | `accordionItems`   | `accordionItem`    | repeated |
| `cards`          | `contentCards`   | `contentCards`     | `contentCard`      | repeated |

Handled separately:
- `hero` — flat fields on the page entry (`heroTitle`, `heroRichText`, `heroDesktopImage`, `enableHero`), not a Matrix block
- `call_to_action` — skipped (CTA entries don't exist in Craft yet)
- `price_list`, `table` — skipped (no matching block types)

Field handler types: `nodes`, `image`, `heading`, `body`, `layout`, `textMediaLayout`.

## Import pipeline — per page

1. Validate JSON structure (`document.slug` required)
2. Resolve section and entry type from config
3. Prepare ImageImportService (volume + folder)
4. Prepare MatrixBuilder (merge mappings)
5. Extract hero block, pass remaining blocks to MatrixBuilder
6. Build Matrix data + resolve SEO via SEOmatic field
7. Find existing entry by slug — or create new
8. Set field values directly on the entry (no draft)
9. Save with `saveElement($entry, false)` (skip validation)
10. Report result

## Key behaviours

**No drafts.** Saves directly to the canonical entry. Re-importing overwrites in place.

**Idempotent images.** Same filename in same folder = reuse existing asset, no re-download.

**SEOmatic integration.** SEO data goes into a single `SeoSettings` field (handle: `seo`) via `metaGlobalVars` and `metaBundleSettings` arrays. Not individual field handles.

**CLI webroot.** `ImportController` calls `chdir(Craft::getAlias('@webroot'))` before any asset operations — required for local filesystem volume paths to resolve.

**Asset import.** Uses `SCENARIO_CREATE` with `newLocation = "{folder:{$folderId}}{$filename}"`. Orphaned files (on disk but not in DB) are cleaned up before save.

## Error handling

| Condition                     | Behaviour              |
|-------------------------------|------------------------|
| Invalid JSON                  | Fatal, exit 1          |
| Section/entry type not found  | Fatal, exit 1          |
| Asset volume not found        | Fatal, exit 1          |
| Unknown block type            | Skip, warn, continue   |
| Image download fails          | Skip field, warn, continue |
| Entry save fails              | Fatal, log errors, exit 1 |

## Not in scope (current)

- Control panel UI
- Webhook/API pull — file-based only
- Rollback

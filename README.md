# ContentIQ Craft Import

Craft CMS 5 plugin that imports [ContentiQ](https://contentiq.io) content into published Craft entries — via JSON file upload or live API sync.

## Requirements

- Craft CMS 5.0+
- PHP 8.1+

## Installation

```bash
composer require matrixcreate/contentiq-craft-import
php craft plugin/install contentiq-importer
```

## Configuration

Add `config/contentiq.php` to your Craft project:

```php
return [
    'section'     => 'pages',       // Entry section handle
    'entryType'   => 'pages',       // Entry type handle
    'assetVolume' => 'images',      // Asset volume for imported images
    'assetFolder' => 'contentiq',   // Folder within the volume
    'matrixField' => 'contentBlocks',
    'seoField'    => 'seo',
];
```

All keys are optional — the values above are the defaults.

Add API credentials to `.env`:

```
CONTENTIQ_URL=https://your-contentiq-instance.com
CONTENTIQ_API_KEY=ciq_your-project_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Then reference them in the plugin settings (CP → Settings → ContentIQ Importer).

## Usage

### CLI import

```bash
php craft contentiq-importer/import --file=export.json
php craft contentiq-importer/import --file=export.json --dry-run
php craft contentiq-importer/import --file=export.json --verbose
```

- `--dry-run` validates and reports without writing anything or downloading assets
- `--verbose` logs each block and image as it's processed

### CP import

Navigate to **ContentiQ** in the Craft control panel. Upload a JSON export file, review the dry-run preview, then confirm to import.

### API sync

Use the **Sync** tab in the CP to pull all pages from the ContentiQ API in one operation. Runs via Craft's queue — the UI polls for completion and shows a hierarchical report when done.

### Per-entry sync

Each entry edit screen shows a **CONTENTIQ** sidebar widget with:

- **Sync** — pulls and re-imports just this entry from the API
- **Lock** — prevents this entry from being overwritten during batch syncs
- **Notes** — developer notes attached to content blocks in ContentiQ

## Local development

To develop the plugin and a Craft project simultaneously, run from the Craft project root:

```bash
# Switch to local symlinked copy
composer config repositories.contentiq '{"type":"path","url":"../contentiq-craft-import","options":{"symlink":true}}' \
  && composer require matrixcreate/contentiq-craft-import:@dev

# Revert to Packagist
git checkout composer.json composer.lock && composer install
```

Never commit the path repository — the `git checkout` step ensures `composer.json` is clean before pushing.

## Releasing

Always pair a git tag with a GitHub release:

```bash
git tag 1.x.0
git push origin main --tags
gh release create 1.x.0 --title "1.x.0" --notes "- What changed"
```

## Further reading

See [PLUGIN-SPEC.md](PLUGIN-SPEC.md) for a full technical reference covering the import pipeline, JSON format, block mapping system, API endpoints, and database schema.

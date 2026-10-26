# Changelog

Earlier history lives in the [GitHub releases](https://github.com/MatrixCreate/contentiq-craft-import/releases).

## 1.39.0 - 2026-10-26

### Added

- Text & Media `media_type` (`image` | `backgroundImage` | `video`) is read per inner block; absent `media_type` falls back to the legacy `layout: 'background'` rule.
- Text & Media video: `mediaType: video` with a nested `videoBlock` (written as `{fields: {…}}`, the shape Craft's ContentBlock reads; `videoSource` streaming | asset, `videoUrl`, `videoAsset`). Uploaded video files are imported into the images volume page folder. Missing starter handles raise a page-report warning and fall back to an empty image block; a failed video asset import raises a page-report warning.
- Text & Media video `poster` (either source) is written to `videoBlock.alternativePosterImage`; a missing handle raises a page-report warning.
- `maxVideoBytes` config (default 200 MiB) caps downloads of video files (chosen by file extension), with a 300 s download timeout; images and documents keep the 25 MiB cap and 30 s timeout.
- A failed video poster import raises a page-report warning.

## 1.38.0 - 2026-10-09

### Changed

- Inline (`detected`-mode) cards in a Cards block are now written as standalone entries in the Cards channel section (default section `cards`, entry type `standaloneCard`) and related from the `entryCards` block's `entries` field, instead of nested manual `card` Matrix rows. Configured by the new `standalone` key of the `cards` mapping in `defaults.php`.
- Standalone cards are idempotent across re-syncs: new `contentiq_card_syncs` table keyed on `(page_id, block_id, position)`; existing cards are updated in place, and cards beyond the new count (or belonging to a block that left the payload) are deleted.
- `cardsInThisBlock = 'automatic'` and `useChildPages = false` are only written when those fields exist on the `entryCards` entry type; missing `entries`, `richText`, `cardsInThisBlock` or `useChildPages` fields now raise a warning instead of being dropped silently.
- The children-mode prose fallback (parent page not found) writes standalone cards through the same writer.
- A single-page `pages`-mode selection now writes `entries => [page]` on the block instead of a nested `card` row with the non-existent `useEntryCardDetails` field. The template expands a single selected parent to its children.
- Card titles are plain text (tags stripped, entities decoded, whitespace collapsed, max 255 characters), falling back to `Card N`.
- Dry runs report planned card create/update/delete counts and write nothing.
- Plugin `schemaVersion` bumped to 1.5.0 so the new migration runs on update.

### Fallback

- Installs without a Cards section / `standaloneCard` entry type warn once per run and keep the previous nested manual card behaviour unchanged.

### Added

- `contentiq-importer/cards/migrate` console command: one-off, handle-driven migration of legacy manual (nested `card`) Entry Cards blocks into standalone Card entries related from the block's `entries` field (see docs/cards-migrate.md).
- `contentiq-importer/cards/prune-orphans` console command: soft-deletes enabled standalone cards that have no `contentiq_card_syncs` row and are not related from any live element, e.g. migrated cards superseded by a later re-sync (see docs/cards-migrate.md).

### Fixed

- Single-page `pages`-mode card selections no longer write to a non-existent `entry` field on the nested card (the card landed empty).
- Standalone cards created from the queue or console now get an author (the owner page's author, else the first admin), so they pass the Cards section's author requirement.
- `cardsInThisBlock` and `useChildPages` are written in every card mode only when the `entryCards` layout has them. Projects that have deleted the mode dropdown outright no longer fail with "Setting unknown property … cardsInThisBlock" on pages whose cards block is in `pages` or `children` mode, and no warning is raised for the absent fields.

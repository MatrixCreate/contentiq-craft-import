# Changelog

Earlier history lives in the [GitHub releases](https://github.com/MatrixCreate/contentiq-craft-import/releases).

## 1.40.0 - 2026-11-06

### Added

- Hero video: `hero_style: video` (export schema 1.5.0), modelled on Royal Hotel — two videos and two poster frames. The wire `video` object is `{desktop, mobile}`, each the Text & Media video shape with `poster` populated. `desktop.asset` goes to `desktopVideo`, `mobile.asset` to `mobileVideo`, and the posters to `desktopPosterFrame` / `mobilePosterFrame`. Video files are imported into the images volume page folder (same path as the Text & Media `videoAsset`); posters use the normal image path. Readers key on `.asset`, so a mobile-poster-only hero (`mobile: {asset: null, poster}`) writes only `mobilePosterFrame`.
- `heroStyle` is written `video` when the Desktop Video imports. The hero image / mobile image are still written from `image` / `mobile_image` as before.
- `media_mode` is ignored for a video hero: `heroMediaMode` is forced to `singleImage` and no carousel images are imported.
- `maxHeroVideoBytes` config (default 50 MiB; non-positive falls back to the default) caps each hero video download independently, applied per call (no run-wide state), with the same 300 s timeout as other videos. `maxVideoBytes` is unchanged.
- `heroDesktopVideoField`, `heroMobileVideoField`, `heroDesktopPosterField`, `heroMobilePosterField` config keys (defaults `desktopVideo`, `mobileVideo`, `desktopPosterFrame`, `mobilePosterFrame`) for forks that named the fields differently.
- All four fields are cleared (`[]`) on every other hero style, and on the fallback, when the layout has them, so switching away from video leaves no stale pick.
- `tests/run-release-check.php` asserts `ContentIQImporter::VERSION` equals the top CHANGELOG entry.

### Fallback

- No usable Desktop Video — `video` null, `desktop.asset` null, oversize or failed download, or a layout without `desktopVideo` / `heroStyle` (older starter, flat hero shape) — imports the hero as `textImage` with all four fields cleared and one page-report warning naming the page. The sync never fails.
- A Mobile Video that fails or is oversize is dropped with one warning (video only); its Mobile Poster Frame is still written and the Desktop Video plays at every viewport. A failed poster is left empty with a warning.
- 1.4.0 payloads are unchanged.

### Fixed

- `ContentIQImporter::VERSION` now matches the release (it still read 1.38.0 at 1.39.0).

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

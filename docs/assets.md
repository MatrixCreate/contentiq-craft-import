# Image & asset import

What this covers: how `ImageImportService` downloads a ContentiQ image
reference and turns it into (or reuses) a Craft asset — the idempotency
rules, the SSRF/temp-file safety net around the download, the CLI webroot
quirk, and how the multi-image "custom" block field fits in.

Verified against code 2026-08-24.

---

## Mental model

Every image ContentiQ exports arrives as a small JSON object:
`{ "key": "path/to/file.jpg", "url": "https://...", "alt": "..." }`. `key`
is the upstream asset's stable storage path; `url` is a signed, rotating
download link. `ImageImportService::importFromField()`
(`src/services/ImageImportService.php`) is the single entry point every
caller — `MatrixBuilder`, `GlobalsImportService`, `ImportService`'s hero/CTA
handling — goes through to turn that object into a Craft asset ID. A caller
must call `prepare($volumeHandle, $folderPath, $dryRun)` once per run before
any `importFromField()` calls; it resolves and caches the target volume and
folder.

Running the same import twice must **not** duplicate assets. Two
independent idempotency checks make that true, tried in order.

---

## Idempotency, step by step

**Step A — key-first identity (`contentiq_asset_syncs`).** If the image
object's `key` is non-empty, the service looks it up in
`contentiq_asset_syncs` (`image_key → element_id`). A hit against a still-live
asset wins outright — no download happens at all. This is the primary path
once a project has synced at least once: the key is globally unique (it's
the upstream storage path), so it's collision-proof even when two different
pages or projects happen to share a bare filename. If the mapped asset was
hard-deleted or trashed, the stale map row is cleared (real runs only) and
resolution falls through to Step B.

**Step B — filename fallback, in the target folder.** If there's no key
mapping (first sync since the table was added, or the image has no `key`),
the service looks for an existing asset with the same filename in the
resolved volume+folder. A hit is reused, and — on a real run, when the image
has a key — the key mapping is created here so Step A resolves it directly
next time. This is also how a Craft install with pre-`contentiq_asset_syncs`
assets adopts a key mapping without duplicating anything.

Only when neither step finds anything does the service actually download
the file and create a new asset.

**The hazard — filename sanitization must happen before the Step B lookup,
not after.** The candidate filename is built with
`craft\helpers\Assets::prepareAssetName()` — the exact function Craft itself
runs on an asset's filename at save time — **before** the Step B existence
query, not after. Craft's own save path turns spaces into hyphens and
strips other characters (e.g. `Styles - Luxury - Card Image.jpg` becomes
`Styles-Luxury-Card-Image.jpg` on disk). If the idempotency lookup queried
the raw, unsanitized name, it would never find the asset Craft actually
saved under the sanitized name, and every sync would create a fresh
duplicate. Any change to how the target filename is derived must sanitize
before checking existence, not the other way round.

Alt text is only applied to freshly downloaded assets — a Step A/B reuse
never touches `alt`, so an editor's hand-set alt text in Craft survives
every re-sync.

---

## The download itself

Downloads go through `Craft::createGuzzleClient()`, not `file_get_contents()`
— this is what makes self-signed dev-domain certs (e.g. `contentiq.test`)
work, and it respects any project `config/guzzle.php` overrides. Failures
are non-fatal by design: `_download()` returns `null` on any exception, HTTP
status ≥ 300, an empty/missing temp file, or a body over the 25MB cap;
`importFromField()` propagates that `null` up, and every caller treats a
`null` result as "leave this field empty and continue" rather than aborting
the whole page/block/globals import.

Two safety checks run before and during every outbound fetch:

- **SSRF guard** (`UrlSafety::isPublicHttpUrl()`, `src/helpers/UrlSafety.php`)
  — the `url` in a synced/uploaded JSON payload is attacker-controllable, so
  before any request is made the target must be `http`/`https`, resolve
  (directly or via DNS) to a non-private/non-loopback/non-link-local
  address, and redirects are disabled on the request itself (`ALLOW_REDIRECTS
  => false`) so a public URL can't 302 its way to an internal one after the
  check has already passed. This file is pure PHP (native
  `gethostbyname`/`dns_get_record`, no Craft dependency) — see
  `tests/run-security.php` for its standalone coverage. The same file also
  holds `safeHref()`, an unrelated scheme-allowlist helper used by
  `NodesRenderer`/`LinkHelper` when rendering stored HTML — grouped here
  only because both are "is this URL safe to act on" checks with no Craft
  runtime dependency, not because `safeHref()` is part of the asset
  pipeline.
- **Orphaned-file cleanup** (`_deleteOrphanedFile()`) — before saving a new
  asset, the service checks whether a file with the target name already
  exists on the volume filesystem with no corresponding DB row (live *or*
  trashed). If so it deletes the stray file first, since Craft's own "file
  already exists" validation would otherwise block the save. Trashed rows
  are deliberately treated as "not an orphan" — Craft's soft-delete restore
  depends on that physical file still being there.

**Creating the `Asset` element itself has two non-obvious requirements.**
`Asset::setScenario(Asset::SCENARIO_CREATE)` must be set before save, and
`$asset->newLocation` must be the `"{folder:{$folderId}}{$filename}"` string
form — `SCENARIO_CREATE`'s own validation requires `newLocation` in that
exact shape, not a bare filename or path. `$asset->tempFilePath` is set to
the downloaded file's path so Craft knows what to move into the volume.
Skipping either of the first two silently fails Craft's own validation
rather than this service's.

---

## CLI webroot requirement

`php craft contentiq-importer/import --file=export.json` must `chdir()` to
`Craft::getAlias('@webroot')` before any asset operation runs — see
`ImportController::actionImport()`. **What breaks without it:** local
filesystem volume paths in `project.yaml` (e.g. `assets/cms/images`) are
relative to the web root, which is the working directory for every normal
web request. A CLI process starts with the project root as its working
directory instead, so without the explicit `chdir()` those relative paths
resolve to the wrong place (or nowhere) and asset saves fail. The file path
argument is resolved to an absolute path *before* the `chdir()` call, since
changing the working directory would otherwise break a relative `--file`
argument.

---

## Multi-image custom blocks

The Custom block type's `images` field (Craft handle `contentiqImages`,
configured in `src/config/defaults.php`) accepts an array of `{key, url,
alt}` objects rather than a single image. `MatrixBuilder::_handleImages()`
iterates the array, calling `importFromField()` per entry through the same
idempotency path described above; a single bad entry (missing `url`, or a
download failure) is skipped with a warning and does not fail the rest of
the block. A conventional "up to 10" cap is documented in the block's Craft
field config (`maxEntries`) — not something the importer itself enforces or
counts — check the Assets field definition in the target project's
`config/project/fields/` if you need the current cap.

---

## Temp-file handling

Two small, Craft-free helpers guard file paths used elsewhere in the import
flow (neither is part of `ImageImportService`'s own download temp file,
which is a `uniqid()`-based path under Craft's temp directory, cleaned up in
a `finally` block regardless of outcome):

- **`helpers/TempFileSafety.php`** — validates the `tempFilename` hidden
  field the CP upload flow round-trips between the preview and run-import
  requests (`CpController::actionPreview()` / `actionRunImport()`). Without
  this, an attacker-controlled value reaching `getTempPath() . '/' .
  $tempFilename` would allow path traversal to read or delete arbitrary
  files; `sanitize()` strips any directory component and confirms what's
  left matches the server-generated pattern
  (`contentiq-import-{word chars}.json`).
- **`helpers/UrlSafety.php`** — see the SSRF guard above.

Both are pure PHP with no Craft dependency, exercised by
`tests/run-security.php` (a zero-dependency runner in the same style as
`tests/run-transforms.php` — see `docs/globals.md`).

---

## How assets are tracked

`contentiq_asset_syncs` (created by `m260814_000000_add_asset_syncs_table`)
is the sole persistence for image-key idempotency: `image_key` (unique
index) → `element_id`, upserted by `_upsertAssetMap()` after every
resolution path that ends in a resolved id (a Step B reuse or a fresh
download — never a Step A hit, since that mapping is already correct).
Deleting a row here doesn't delete the Craft asset; it just means the next
sync falls back to the Step B filename lookup for that image.

---

## Related docs

- [globals.md](globals.md) — branding/trust-signal logos and office images go through this exact same service.
- [block-mapping.md](block-mapping.md) — which content-block fields carry image data and how they're wired to `_handleImages`/`importFromField`.
- [import-pipeline.md](import-pipeline.md) — where in the page-import run images get downloaded relative to entry saves.
- [README.md](README.md) — plugin overview and where each doc fits.
- [integration.md](integration.md) — the ContentiQ API/export contract that supplies the `{key, url, alt}` shape.

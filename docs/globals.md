# Globals & offices sync

What this covers: how the plugin imports ContentiQ's `globals` payload (company
info, offices, branding, social networks, trust signals, scripts) into the
`offices` section and the `companyInfo`/`globalContent`/`siteConfig` global
sets — the per-run consent model that gates it, the field boundary the sync
respects, offices idempotency, and the read-only URL-prefix drift check.

Verified against code 2026-09-21.

---

## Mental model

Globals import is a separate pipeline from the per-page importer, run by
`GlobalsImportService::import()` (`src/services/GlobalsImportService.php`).
It's invoked from two places: `FinaliseRunJob`'s `globals` step
(`src/jobs/FinaliseRunJob.php` — the `finalising` phase's `globals`
sub-step, see
[import-pipeline.md](import-pipeline.md#the-job-chain--phase-by-phase)) for
every pipeline run (CP Sync, CP upload, `sync/run` alike — the step itself
doesn't gate on `source`, the consent lock does, see "Why globals never
travel via upload" below), and the Sync screen's preview
(`CpController::actionPreview()`) in dry-run mode purely to show what would
happen. The standalone CLI import command (`ImportController`, unrelated to
the batched pipeline) never invokes it at all.

A globals import writes to three destinations:

- The `offices` Structure section — one entry per ContentiQ office.
- Three global sets: `companyInfo` (branding, offices relation, social
  networks), `globalContent` (trust signals), `siteConfig` (scripts,
  analytics id).

Every write is a **field-level merge**, not a wholesale replace of the
global set. Only the specific handles this sync owns are ever touched — see
"Sync-owned field boundary" below.

**One other consumer of the same consent gate, outside this pipeline.**
`ImportService`'s per-page CTA routing (`fields.source === 'global'` —
[block-mapping.md](block-mapping.md#source-routing-fieldssource-page--global))
writes the shared `callToActionEntry` and its
`globalContent.globalChooseCallToAction` relation under the identical
`contentiq_globals_sync.locked` gate this doc describes, via its own
`ImportService::_globalsLocked()` (a read-only mirror of
`SyncLockService::globalsLocked()` — same table, same missing-row-is-locked
default). This routing itself runs per page inside `ImportPagesJob`'s
`importing` phase (see
[import-pipeline.md](import-pipeline.md#the-job-chain--phase-by-phase)) —
`ImportPagesJob` calls `importPage()`/`_buildBlockFieldValues()` per row,
which is where `_resolveCtaBlocks()` and this gate actually run, not inside
`FinaliseRunJob`'s `globals` step. It is **not** part of
`GlobalsImportService::import()` and doesn't touch the three destinations
above — it's mentioned here because it's a second reader of the same lock. Unlike the rest of this doc, though, that
reader's gate is **conditional**, not absolute: the lock protects an
already-chosen global CTA (a client's pick) from being silently overwritten,
but an empty `globalChooseCallToAction` slot has nothing for it to protect —
and a page whose `callToAction.showGlobalCallToAction` lightswitch is ON
renders nothing in the footer until that slot is filled. So
`ImportService::_resolveGlobalCtaEntry()` reads the CURRENT relation first
and only invokes the lock when one already exists; a locked run with no
relation yet still creates and relates the first global CTA. "Why globals
never travel via upload" below therefore applies to it only in the
already-related case — see that section's note.

---

## Consent model — why it's per-run, not persistent

Globals are gated by a single-row table, `contentiq_globals_sync`. A missing
row is treated as **locked** — the safe default when the plugin has just
been installed and the table is empty.

**The hazard.** Globals writes touch company-wide branding, offices, and
scripts — content an editor may have hand-tuned in Craft. Importing it
silently on every sync would clobber those edits with no per-field warning
(unlike per-page content, which is version-controlled in ContentiQ and
meant to be overwritten). Consent has to be re-affirmed every run rather
than being a one-time setting.

Mechanics:

- The Sync screen shows one lightswitch above the tree (`sync.twig`, tied to
  `globalsLocked` in `CpController::actionSync()`). Checking it and
  submitting POSTs `unlockGlobals=1` to `contentiq-importer/cp/run-sync`.
- `StageRunJob` (`src/jobs/StageRunJob.php`, the `staging` phase — the first
  job in the chain, see
  [import-pipeline.md](import-pipeline.md#the-job-chain--phase-by-phase))
  applies the inverse into `contentiq_globals_sync.locked` at the very start
  of the run (`SyncLockService::setGlobalsLock(!$unlockGlobals)`), for every
  pipeline source alike. `FinaliseRunJob`'s `globals` step — the third of its
  four `finalising` sub-steps, running only once `importing`/`postpass` have
  finished — imports globals only if still unlocked, then **immediately
  relocks** and stamps `synced_at` (`SyncLockService::relockGlobals()`,
  no-argument success path) once that step completes. On any failure
  anywhere in the chain, the job that caught the error calls
  `relockGlobals($message)` instead (the failure-path overload — see
  `SyncLockService::relockGlobals()`'s own docblock) — every terminal path
  relocks, never leaving globals unlocked past the run that unlocked them. A
  worker that dies mid-run with no failure handler ever running leaves the
  row unlocked regardless; the sync status poll's staleness fallback
  (`CpController::actionSyncStatus()`) is the relock safety net for that
  case, same as it fails the run itself.
- Net effect: consent lives for exactly one sync. The next sync's tree
  always shows the lightswitch unchecked again — nothing persists it.
- `setGlobalsLock()` runs inside `StageRunJob`, which always completes (and
  advances the run to `importing`) before `ImportPagesJob` starts — so the
  CTA global-routing path's own read (`ImportService::_globalsLocked()`,
  called once per `'global'`-source CTA block as `ImportPagesJob` imports
  each page) always sees THIS run's consent decision, not last run's — the
  same reason `FinaliseRunJob`'s `globals` step itself is safe to gate on the
  same row later in the run.

CP upload now runs through the same `StageRunJob`/`FinaliseRunJob` steps as
CP Sync — but the upload controller (`CpController::actionRunImport()`)
always stamps `unlockGlobals: false` on the run's options (there's no
consent UI on that screen), so `StageRunJob` still locks the row for every
uploaded run, and `FinaliseRunJob`'s `globals` step still finds it locked
and skips the write — same outcome as before this pipeline existed, just
reached by running (and skipping) the same shared step rather than a
separate code path that never touched the table at all. The **standalone
CLI import command** (`ImportController`, unrelated to the batched pipeline)
is the one entry point that genuinely never touches `contentiq_globals_sync`
at all — it simply skips globals unconditionally (see below). A
`'global'`-source CTA block imported through either path finds the row
exactly as the last pipeline run left it (locked, unless another pipeline
run is unlocked and mid-flight elsewhere). As of `_resolveGlobalCtaEntry()`'s
existing-relation gate (above), that locked row only skips-and-warns the
global entry write when `globalContent.globalChooseCallToAction` is already
related to a live entry; if no relation exists yet, the write goes ahead
regardless of the row's locked state — an empty slot has nothing for the
lock to protect. Either way the page's own `callToAction` lightswitch is
still set (page-scoped, not gated by this row — see
[block-mapping.md](block-mapping.md#source-routing-fieldssource-page--global)).

---

## Sync-owned field boundary

`GlobalsImportService::OFFICE_FIELDS` (a constant near the top of the class)
is the exhaustive list of office entry handles the sync will ever write —
read it directly rather than trusting a description here, since it can grow.
Every write path — office entries and all three global sets — is filtered
through `_filterToLayout()` against the *target's own field layout* before
saving. A handle the sync wants to write that isn't present on an older
project's layout is silently skipped and recorded as a `fieldNotes` entry
in the report rather than throwing. This is what lets the plugin ship new
globals fields without breaking a Craft install that hasn't picked up the
matching Craft Starter field/entry-type update yet.

Two field-family patterns worth knowing:

- **Presence-gated sub-keys** (branding logos, scripts): a wire key that's
  entirely absent from the payload leaves the existing Craft value
  untouched. A key that's present — even as `null` — is a real edit and
  clears the field. This lets ContentiQ's export omit `branding` entirely on
  an unrelated partial payload without wiping every logo.
- **Wholesale-rebuilt Matrix fields** (`socialNetworks`, `trustSignals`):
  same absent-vs-present rule at the top level, but once the key is
  present the whole inner Matrix is rebuilt from scratch (`new*` keys) —
  there's no per-row diffing here, unlike the page importer's
  `preserveBlockIdentity` mode (see
  [block-mapping.md](block-mapping.md#diff-aware-matrix-writes-preserveblockidentity)
  — that flag has no analogue in the globals pipeline).

---

## Offices idempotency

Offices are matched across runs via `contentiq_office_syncs`
(`office_id → element_id`), upserted by `_upsertOfficeMap()`. Per wire
office, `_importOffices()` resolves in this order:

1. **Mapped** — the office id has a row in `contentiq_office_syncs` pointing
   at a still-live entry → update in place.
2. **Adopted** — no map row, but an *unmapped, unclaimed* existing office
   entry shares the wire office's title and entry type → adopted (a map row
   is created going forward). This is how offices created by hand in Craft
   before the sync table existed get picked up on the first sync rather than
   duplicated.
3. **Created** — neither of the above → a new office entry.

Wire ids that vanish from the payload (an office deleted in ContentiQ) get
their mapped Craft entry **deleted**, and the stale map row pruned. Craft
entries that were never claimed by any wire office this run — genuinely
hand-made offices with no matching title/id — are left alone entirely and
surfaced in the report's `unmatched` list, never touched or deleted.

A per-run "claim set" (seeded from every existing map row, grown as offices
are processed) guards against two same-titled wire offices both trying to
adopt — and later fight over deleting — the same Craft entry.

Per-entry locks (`contentiq_entry_syncs.locked`, the same lock the sidebar
widget sets — see `docs/cp-and-widget.md`) are **ignored** for office
entries; globals writes are not gated by them. A locked office that gets
written is only *noted* in the report (`_noteLockedOffices()`), not skipped.

---

## URL-prefix drift check — read-only, always runs

`checkUrlPrefixDrift()` compares each ContentiQ collection's exported
`url_prefix` against the mapped Craft section's `uriFormat` (via
`ContentIQApiService::getContentTypesMap()`) and returns advisory warning
strings when the leading path segment diverges (e.g. ContentiQ exports
`/the-blog/…` but the Craft section serves `/blog/…`).

**The hazard.** This check runs on **every** sync — including when globals
are locked — because a URL mismatch is a content-routing problem the editor
needs to see regardless of whether they've consented to a globals import
this run. It never mutates section settings or project config; it's purely
advisory text surfaced in the sync report.

---

## Why globals never travel via upload

The CP's manual JSON-upload path (`actionRunImport()`) deliberately never
writes globals: there's no consent UI on that screen, so importing them there
would bypass the whole per-run gate above. The upload is staged onto the same
job chain as a Sync with `unlockGlobals: false`, so `StageRunJob` locks the
row for that run and `FinaliseRunJob`'s globals step records "skipped —
locked" in the report (any `globals` key in the file is carried on the run
row but unused). The Sync screen's own preview step dry-runs the globals payload
purely for **display** (`GlobalsImportService::import($globals, dryRun:
true)`) — nothing is written on preview regardless of the lock state.

---

## Pure-transform helpers

`src/helpers/GlobalsTransforms.php` holds every piece of this pipeline that
doesn't need a live Craft app: address-line splitting, opening-hours
expansion into Store Hours' per-weekday shape, free-text country → ISO
alpha-2 resolution (alias table + case-insensitive official-name match), and
the URL-prefix drift comparison. Every method is `static` and takes its
Craft-sourced lookups (e.g. the country repository's code→name map) as
injected arguments, so none of it needs Craft bootstrapped to test.

`tests/run-transforms.php` is a zero-dependency runner — plain `php
tests/run-transforms.php`, no Craft, no PHPUnit — that `require`s the helper
file directly and asserts against real lab data shapes (see its opening
hours fixture). Run it whenever `GlobalsTransforms` changes; it's the only
automated coverage this pipeline has (see also `tests/run-security.php` for
the unrelated SSRF/XSS helpers covered in `docs/assets.md`).

---

## Related docs

- [assets.md](assets.md) — how branding/trust-signal logos and office images are downloaded and deduplicated; `ImageImportService` is shared with the page importer.
- [cp-and-widget.md](cp-and-widget.md) — the Sync screen's globals lightswitch, tree, and report rendering.
- [README.md](README.md) — plugin overview and where each doc fits.
- [integration.md](integration.md) — the ContentiQ API contract this payload arrives over.
- [import-pipeline.md](import-pipeline.md) — the per-page import pipeline this runs alongside (not part of).
- [block-mapping.md](block-mapping.md) — content-block field mappings; unrelated to globals but the same JSON envelope.

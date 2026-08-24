# ContentiQ Importer developer documentation

This Craft CMS 5 plugin pulls a ContentiQ project's exported content — pages,
content blocks, images, and site-wide globals — and writes it into that
site's Craft entries. It's installed independently on each client site;
every sync is Craft-initiated, pull-only, and confirmed back to ContentiQ
with an explicit acknowledgement.

This page is the whole plugin at a glance: one line per subsystem doc, then
a walk through what actually happens when someone clicks Sync.

---

## The docs

- **[Integration](integration.md)** — how this plugin talks to ContentiQ: the four API endpoints, Bearer-key auth and project resolution, the read-only-export/explicit-ack contract, and the config surface (connection settings, `blockOverrides`, `content_types`, `slugMap`, `preserveBlockIdentity`).
- **[Import pipeline](import-pipeline.md)** — what happens to a page once its JSON is in Craft: the four entry points that all funnel through `ImportService::importPage()`, find-or-create entry resolution, the no-drafts save, collection children vs. ordinary pages, homepage specifics, hierarchy/parent positioning, and lock semantics.
- **[Block mapping](block-mapping.md)** — the declarative `defaults.php` mapping system, `MatrixBuilder`, `NodesRenderer`'s two rich-text paths, text-column splitting, text-and-media grouping, cards' two-pass reference resolution, hero, CTA entry creation, the diff-aware `preserveBlockIdentity` write path, and how to explore a target project's own Craft field layout.
- **[Globals & offices](globals.md)** — the separate, per-run-consent-gated import of company info, offices, branding, social networks, trust signals, and scripts; the sync-owned field boundary; office idempotency; and the read-only URL-prefix drift check.
- **[Assets](assets.md)** — how `ImageImportService` downloads and idempotently reuses images, the SSRF and path-traversal guards, the CLI webroot requirement, and multi-image custom blocks.
- **[CP screens & sidebar widget](cp-and-widget.md)** — a tour of every Control Panel screen, the sync tree and report anatomy, and the per-entry sidebar widget's lock toggle, block notes, and sync button.

---

## The life of a sync

1. **An editor clicks Sync** — either the CP's Sync screen (a whole-project run) or an entry's sidebar widget (a single page). Both require the plugin's connection settings (`contentiqUrl`/`apiKey`) to be configured.
2. **A queue job is pushed** (whole-project sync only — the widget's single-page sync runs inline). The controller creates a `pending` `contentiq_import_runs` row and pushes `SyncJob`; the CP polls `sync/status` until it leaves `pending`.
3. **The export is fetched** — `GET /api/v1/export` (or `/api/v1/pages/{slug}/export` for a single page) over `Craft::createGuzzleClient()`, Bearer-authed. This is a read-only pull on ContentiQ's side — nothing about ContentiQ's own state changes just because the export was fetched.
4. **Pass 1: pages, blocks, and assets import.** Every page in the response is resolved to a Craft entry (or a new one is created), locked entries are skipped, and `ImportService::importPage()` runs the whole per-page pipeline — Matrix block mapping, hero, image downloads, SEO — saving directly to the canonical entry. A slug → entry ID map is built as this pass goes.
5. **Pass 2: deferred card references.** Once every page in the batch has a Craft entry ID, Cards blocks in `pages`/`children` mode (which reference other pages by slug, not inline content) are resolved and saved directly.
6. **Ack** — `POST /api/v1/pages/ack` tells ContentiQ which pages were genuinely written this run. This is the only call that mutates ContentiQ's own state; a failure here is a non-fatal warning, never a failed sync — the pages are already safely in Craft.
7. **Report** — the run record is finalised (`success`/`warnings`/`errors`) and the CP renders a hierarchical sync report: per-page created/updated/skipped/failed status, image counts, and any warnings.

Globals import (when the payload carries a `globals` key and the per-run consent lightswitch was ticked) and the auto-relock of every successfully-synced entry both happen alongside this same run — see [globals.md](globals.md) and [import-pipeline.md](import-pipeline.md) for where they fit.

---

## Where else to look

- **[`_archive/`](_archive/)** — superseded snapshots: the pre-restructure `AGENTS.md`, the old `PLUGIN-SPEC.md`, and `PROGRESS.md` entries rolled off its capped log. Useful for history, not current behaviour.
- **`../PROGRESS.md`** (repo root) — the capped, session-by-session build log. Durable knowledge lives here in `docs/`, not there.
- **`../AGENTS.md`** (repo root, symlinked from `CLAUDE.md`) — the AI-assistant router and rulebook: hard limits, architectural principles, and the "read this doc first" table, pointing into these docs rather than duplicating them.
- **The `contentiq` repo's `docs/delivery/`** — the server-side half of the API contract this plugin consumes (`docs/delivery/api.md`, `docs/delivery/export.md`). This plugin's own [integration.md](integration.md) covers the wire contract from the Craft side; the ContentiQ repo covers how that contract is served.

---

## Doc conventions

Every doc is stamped with the date it was last verified against code (`Verified against code YYYY-MM-DD`, near the top). Trust the stamp, not your memory — if it's old, re-verify before relying on a claim. Anything that couldn't be confirmed against code is flagged `⚠️ UNVERIFIED` rather than stated as fact.

These six docs absorbed the repo's previous `AGENTS.md` and `PLUGIN-SPEC.md` (2026-08-24) — load-bearing facts were folded into the doc that now owns that subsystem; pure process (releasing, local dev, testing) moved to the new `AGENTS.md`; genuinely stale claims were dropped. When a subsystem doc changes in a way that makes an old snapshot actively misleading, prefer fixing the live doc over letting drift accumulate — `_archive/` is for closed chapters, not a place to defer corrections to.

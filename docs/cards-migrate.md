# Cards migration (`cards/migrate`)

Converts legacy **Manual** Entry Cards blocks to the standalone-card model.

## Purpose

Older Entry Cards blocks stored hand-built cards as nested `card` entries inside the block's `entryCards` Matrix field ("Manual" mode). From plugin 1.38 the importer targets standalone entries in a **Cards** channel section, linked from the block's relation field (default `entries`). `contentiq-importer/cards/migrate` moves existing content across.

## When to run

After upgrading a project to plugin >= 1.38 **and** adding the Cards section (with the `standaloneCard` entry type, and the section added to the block's relation field sources). Run it once per environment, after a database backup.

## Usage

```bash
php craft contentiq-importer/cards/migrate --dry-run
php craft contentiq-importer/cards/migrate --dry-run --block-id=123
php craft contentiq-importer/cards/migrate --owner-id=456
php craft contentiq-importer/cards/migrate
```

| Option | Meaning |
|---|---|
| `--dry-run` | Prints the plan per block and per card; writes nothing. |
| `--block-id=N` | Only the Entry Cards block with this element id. |
| `--owner-id=N` | Only blocks owned by this entry id. |

Always run `--dry-run` first and read the output. Exit code is 0 on success, 1 if any block failed or a handle could not be resolved. If the legacy nested card type or nested field no longer exists (the normal end state after an upgrade), it prints "No legacy nested cards to migrate" and exits 0.

## What it changes

For each Entry Cards block in manual mode with nested cards:

1. Creates a revision of the owner entry (once per owner per run).
2. In one DB transaction per block: creates a standalone card entry per nested card (author = the owner's author, else the first admin), sets the block's relation field (`standalone.relationField`, default `entries`) to the new entries, sets `cardsInThisBlock = automatic` and `useChildPages = false` (only where those fields exist in the layout), then deletes the nested cards. Any failure rolls the block back.

Field mapping per card:

- **Title**: card title text, else the linked entry's title, else `Card {position}` (max 255 chars).
- **Text**: plain text is wrapped in `<p>`; HTML is kept.
- **Image**: first image only. **Link**: `cardLink` is copied.
- **`useEntryCardDetails`**: the linked entry's `cardTitle`/`cardText`/`cardImage` are snapshotted onto the new card (noted in output).
- **`showCustomButton`**: dropped (noted in output).
- Disabled nested cards become disabled standalone cards.

Block classification:

- **Manual**: the `cardsInThisBlock` dropdown reads `manual`, **or** the dropdown is absent from the block type's layout (already removed mid-upgrade) and nested cards exist. The nested cards are then the block's only card data, so they are migrated, never deleted.
- **Stale**: the dropdown is present, reads non-manual, and nested cards exist. Those nested cards are deleted (after an owner revision); `--dry-run` reports them.
- Anything else is skipped.
- **Orphaned blocks are skipped, not touched**: a block whose owner is missing, trashed, a draft/revision, or whose owner's section is soft-deleted is counted in the summary under "skipped" with its reason (`no owner`, `owner trashed`, `draft/revision owner`, `owner section deleted`).
- A failure on one block is printed with its block id and counted; the run continues with the next block and exits 1 at the end.

A summary of counts is printed at the end. A one-line warning is printed once per run if the standalone type lacks `cardText`, `cardImage` or `cardLink`, or the nested type lacks `cardTitle`; that value is then not migrated.

## Limitations

- **Single site only.** Queries, new entries and the enabled state use the primary site (element-level `enabled`, not per-site). The command fails fast if the Cards section has no settings for the primary site.
- Dropped from the project command this was generalised from: `--limit`, and the separate `card-text-to-html` action. Plain-text to `<p>` wrapping now always applies to migrated cards.

## Pruning orphans (`cards/prune-orphans`)

```bash
php craft contentiq-importer/cards/prune-orphans --dry-run
php craft contentiq-importer/cards/prune-orphans
```

When a page is re-synced after `cards/migrate`, the import creates its own standalone cards (tracked in `contentiq_card_syncs`). The cards created by the migration have no sync row and are left related only from the old, now-deleted block. This action soft-deletes them.

A card is an orphan when all of these hold: it is a live (enabled, and not trashed, draft or revision) entry of the configured standalone entry type in the configured Cards section — a card an editor has disabled is treated as deliberately kept and is never pruned; it has no row in `contentiq_card_syncs`; and no live element relates to it. A relation counts as live when its source element is not trashed/draft/revision and, if the source is a nested block, its primary owner is live too.

It never touches import-owned cards (sync row) or cards in use. It resolves only the Cards section, the standalone entry type, the primary-site setting for that section and the `contentiq_card_syncs` table (from the same `standalone` config as `migrate`); it does not need the block type, nested field/type or relation field, so it works after the legacy structures are deleted. Only enabled (live-status) cards are considered; disabled cards are deliberately kept. It lists one line per orphan (id, title, created date), and prints a summary. `--dry-run` lists only; a real run soft-deletes through the elements service. Exit code is 0 on success, 1 on a handle error or any failed delete.

Run it after the first re-sync following a migration, or at any time; it is safe to repeat. Run `--dry-run` first and back up the database.

## Handles it resolves

| Setting | Source | Default |
|---|---|---|
| Content-blocks Matrix field | config `matrixField` | `contentBlocks` |
| Block entry type | `cards` mapping `outerType` | `entryCards` |
| Nested cards field | `cards` mapping `innerMatrix.outerField` | `entryCards` |
| Nested card entry type | `cards` mapping `innerMatrix.innerType` | `card` |
| Target section | `cards` mapping `standalone.section` | `cards` |
| Target entry type | `cards` mapping `standalone.entryType` | `standaloneCard` |
| Relation field on the block | `cards` mapping `standalone.relationField` | `entries` |

`migrate` resolves all of the above; `prune-orphans` resolves only the target rows (section, entry type). `migrate` fails before touching anything if a handle is missing, or if the relation field's sources do not include the Cards section. Card field handles (`cardTitle`, `cardText`, `cardImage`, `cardLink`, `useEntryCardDetails`, `showCustomButton`, `cardsInThisBlock`, `useChildPages`) are Starter conventions and are probed in the field layout before use.

## Backups

Take a database backup first. Owner revisions are a safety net for content, not a substitute: deleted nested cards are soft-deleted and recoverable until garbage collection, but the new standalone entries are real content.

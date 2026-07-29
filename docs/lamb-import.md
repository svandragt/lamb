---
title: Lamb import
---

# Restoring a Lamb export

Lamb ships a CLI script that reads a [Lamb export](export.md) — the `.zip` produced by `/export`, or an already-unpacked copy of one — and restores every post and asset it describes. It is a lamb-to-lamb restore, not a converter: it writes back exactly what was exported, using the same low-level pipeline every other importer uses. It is fully offline — no credentials, no API access — and re-running it is safe.

## What you get

- Every post in the manifest is restored: its body, front matter, slug, `created`/`updated` timestamps, and draft/trash state, exactly as recorded.
- Every asset the manifest lists is written back to `src/assets/YYYY/MM/`, at the same path it was exported from.
- Re-running the importer on the same archive does not create duplicates. Each restored post is tagged with an `import_uuid` (md5 of `'lamb-' + origin + '#' + id`, where the origin is the site the archive came from), and an item whose uuid is already present is left alone.
- Imported posts are **silent**: the importer calls the low-level save pipeline directly, so no outbound webmentions or WebSub hub pings are emitted. The content already existed somewhere else.

## Running the importer

From the project root:

```bash
# Preview what would be restored without writing anything
php import-lamb.php /path/to/lamb-export-2026-07-26.zip --dry-run

# Run it for real
php import-lamb.php /path/to/lamb-export-2026-07-26.zip
```

An already-unpacked export directory works too — pass its path instead of the `.zip`:

```bash
php import-lamb.php /path/to/lamb-export-2026-07-26/
```

The script prints one line per post (`imported:`, `would import:`, `replaced:` or `would replace:`) plus a final summary with the totals (created, existed, skipped), and a one-line asset tally (restored, skipped, rejected).

### `--site-url=<url>`

Restored posts are deduplicated by the site the archive came from, taken from the manifest's `site.url`. Pass `--site-url=<url>` to override it — needed when the manifest carries no `site.url` (older or hand-built archives), or when you are restoring an archive from a site that has since moved to a new domain. Without either, the importer still imports and dedupes re-runs correctly, but it warns: an empty origin cannot tell two different sites' post ids apart if you ever restore a second archive into the same database.

### `--replace`

By default, a post that was already restored (matched by `import_uuid`) is left alone on a second run — the importer counts it as `existed` and moves on. Pass `--replace` to overwrite it instead: body, slug, `created`/`updated`, draft state and trash state are all set back to what the manifest records.

This is deliberately total, not a merge. Local edits made to a restored post since the archive was taken are lost when you `--replace` it — that is what "replace" means. A restore that left a post published when the backup says it was trashed, or dated today instead of when it was actually written, would not be much of a restore.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There is no separate review queue.

A few things worth checking manually:

- **Slugs.** Every post keeps the slug it was exported with. The one case that cannot be restored is a post that had no slug to begin with — a titleless status post. Its permalink was `/status/<its old id>`, and source ids cannot be restored into a populated database (a new row gets a new id). It comes back as a status post again, but at `/status/<new id>` — a different URL than before. Only the URL is affected: posts are matched on the id recorded in the manifest, not on their slug, so a slugless post still imports exactly once no matter how often you re-run the importer.
- **Media.** Run a quick `git status` on `src/assets/` to see what was restored.

## Related

- [Export](export.md) — the format this importer reads, in the opposite direction.
- [WordPress import](wordpress-import.md) — the other importer sharing `run_import()`'s dedupe/summary machinery.
- [Known import](known-import.md) — likewise.
- [Trash](trash.md) — trashed posts round-trip through export and this importer.
- [Drafts](drafts.md) — so do drafts.

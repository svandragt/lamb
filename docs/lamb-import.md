---
title: Lamb import
nav_order: 31
---

# Restore a Lamb export

Lamb ships a CLI script that reads a [Lamb export](export.md) — the `.zip` that `/export` produces, or an already-unpacked copy of one — and restores every post and asset it describes. It's a Lamb-to-Lamb restore rather than a converter: it writes back exactly what you exported, through the same low-level pipeline every other importer uses. The importer works fully offline, with no credentials and no API access, and re-running it is safe.

**Experimental.** This importer is still gathering real-world testing. Enable it by setting `experimental_features = true` in [Settings](site-configuration.md) before running the script — it refuses to run otherwise.

## What you get

- Lamb restores every post in the manifest: its body, front matter, slug, `created` and `updated` timestamps, and draft or trash state, exactly as recorded.
- Lamb writes every asset the manifest lists back to `src/assets/YYYY/MM/`, at the same path it was exported from.
- Re-running the importer on the same archive doesn't create duplicates. Lamb tags each restored post with an `import_uuid`, the md5 of `'lamb-' + origin + '#' + id`, where the origin is the site the archive came from. It leaves an item alone when that uuid is already present.
- Imported posts are **silent**. The importer calls the low-level save pipeline directly, so it emits no outbound webmentions and no WebSub hub pings. The content already existed somewhere else.

## Run the importer

From the project root:

```bash
# Preview what the importer would restore, without writing anything
php import-lamb.php /path/to/lamb-export-2026-07-26.zip --dry-run

# Run it for real
php import-lamb.php /path/to/lamb-export-2026-07-26.zip
```

An already-unpacked export directory works too. Pass its path instead of the `.zip`:

```bash
php import-lamb.php /path/to/lamb-export-2026-07-26/
```

The script prints one line per post (`imported:`, `would import:`, `replaced:`, or `would replace:`), plus a final summary with the totals for created, existed, and skipped, and a one-line asset tally of restored, skipped, and rejected.

### `--site-url=<url>`

Lamb deduplicates restored posts by the site the archive came from, which it takes from the manifest's `site.url`. Pass `--site-url=<url>` to override it. You need this when the manifest carries no `site.url`, as older or hand-built archives may not, or when you're restoring an archive from a site that has since moved to a new domain.

Without either value, the importer still imports and still dedupes re-runs correctly, but it warns you: an empty origin can't tell two different sites' post ids apart if you ever restore a second archive into the same database.

### `--replace`

By default, the importer leaves a post it already restored alone on a second run, matching it by `import_uuid`, counting it as `existed`, and moving on. Pass `--replace` to overwrite it instead. Lamb then sets the body, slug, `created` and `updated` timestamps, draft state, and trash state back to what the manifest records.

This is deliberately total rather than a merge. You lose any local edits made to a restored post since you took the archive — that's what "replace" means. A restore that left a post published when the backup says it was trashed, or dated today instead of when you actually wrote it, wouldn't be much of a restore.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There's no separate review queue.

A few things are worth checking manually:

- **Slugs.** Every post keeps the slug you exported it with. The one case Lamb can't restore is a post that had no slug to begin with, meaning a titleless status post. Its permalink was `/status/<its old id>`, and source ids can't be restored into a populated database, because a new row gets a new id. It comes back as a status post again, but at `/status/<new id>`, a different URL than before. Only the URL is affected: Lamb matches posts on the id recorded in the manifest rather than on their slug, so a slugless post still imports exactly once however often you re-run the importer.
- **Media.** Run `git status` on `src/assets/` to see what the importer restored.

## Related

- [Export](export.md): The format this importer reads, in the opposite direction.
- [WordPress import](wordpress-import.md): The other importer sharing `run_import()`'s dedupe and summary machinery.
- [Known import](known-import.md): Likewise.
- [Trash](trash.md): Trashed posts round-trip through export and this importer.
- [Drafts](drafts.md): So do drafts.

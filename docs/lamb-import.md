---
title: Lamb import
parent: Import & export
---

# Restore a Lamb export

Lamb ships a CLI script that reads a [Lamb export](export.md) — the `.zip` that `/export` produces, or an already-unpacked copy of one — and restores every post and asset it describes. It's a Lamb-to-Lamb restore rather than a converter: it writes back exactly what you exported, through the same low-level pipeline every other importer uses. The importer works fully offline, with no credentials and no API access, and re-running it is safe.

This importer is experimental. [Import & export]({{ site.baseurl }}{% link import-export.md %}) covers the `--experimental` flag and what the three importers share.

## What you get

- Lamb restores every post in the manifest: its body, front matter, slug, `created` and `updated` timestamps, and draft or trash state, exactly as recorded.
- Lamb writes every asset the manifest lists back to `src/assets/YYYY/MM/`, at the same path it was exported from.
- Re-running the importer on the same archive doesn't create duplicates. Lamb tags each restored post with an `import_uuid`, the md5 of `'lamb-' + origin + '#' + id`, where the origin is the site the archive came from. It leaves an item alone when that uuid is already present.
- An import run is silent: it sends no webmentions or WebSub pings. See [Import & export]({{ site.baseurl }}{% link import-export.md %}#import-runs-are-silent).

## Run the importer

Run it as [Import & export]({{ site.baseurl }}{% link import-export.md %}) describes, with the path to the `.zip`:

```bash
# Preview what the importer would restore, without writing anything
sudo -u www-data bin/lamb import lamb /path/to/lamb-export-2026-07-26.zip --dry-run --experimental

# Run it for real
sudo -u www-data bin/lamb import lamb /path/to/lamb-export-2026-07-26.zip --experimental
```

An already-unpacked export directory works too. Pass its path instead of the `.zip`:

```bash
sudo -u www-data bin/lamb import lamb /path/to/lamb-export-2026-07-26/ --experimental
```

Reading a `.zip` uses PHP's `zip` extension, or its `phar` extension when `zip` is missing (the file name then needs an extension, such as `.zip`). If PHP has neither, the importer tells you to unzip the archive yourself and pass the directory.

Besides the lines and summary described in [Import & export]({{ site.baseurl }}{% link import-export.md %}#run-an-importer), this importer prints a one-line asset tally of restored, skipped, and rejected.

### `--site-url=<url>`

Lamb deduplicates restored posts by the site the archive came from, which it takes from the manifest's `site.url`. Pass `--site-url=<url>` to override it. You need this when the manifest carries no `site.url`, as older or hand-built archives may not, or when you're restoring an archive from a site that has since moved to a new domain.

Without either value, the importer still imports and still dedupes re-runs correctly, but it warns you: an empty origin can't tell two different sites' post ids apart if you ever restore a second archive into the same database.

### `--replace`

Pass `--replace` to overwrite a post the importer already restored, as [Replacing imported posts]({{ site.baseurl }}{% link import-export.md %}#replacing-imported-posts) explains. Here it also sets the trash state back to what the manifest records. You lose any local edits made since you took the archive. A restore that left a post published when the backup says it was trashed, or dated today instead of when you actually wrote it, wouldn't be much of a restore.

## After the import

The posts are visible at once, and [Import & export]({{ site.baseurl }}{% link import-export.md %}#after-the-import) covers what to check in `src/assets/`. One thing is specific to a Lamb restore:

- **Slugs.** Every post keeps the slug you exported it with. The one case Lamb can't restore is a post that had no slug to begin with, meaning a titleless status post. Its permalink was `/status/<its old id>`, and source ids can't be restored into a populated database, because a new row gets a new id. It comes back as a status post again, but at `/status/<new id>`, a different URL than before. Only the URL is affected: Lamb matches posts on the id recorded in the manifest rather than on their slug, so a slugless post still imports exactly once however often you re-run the importer.

## Related

- [Export](export.md): The format this importer reads, in the opposite direction.
- [WordPress import](wordpress-import.md): The other importer sharing `run_import()`'s dedupe and summary machinery.
- [Known import](known-import.md): Likewise.
- [Trash](trash.md): Trashed posts round-trip through export and this importer.
- [Drafts](drafts.md): So do drafts.

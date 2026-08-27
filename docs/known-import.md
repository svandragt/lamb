---
title: Known import
parent: Import & export
---

# Importing from Known

Lamb ships a CLI script that reads a [Known CMS](https://withknown.com) RSS export and feeds each published item through Lamb's existing post-creation pipeline. The importer is fully offline — no credentials, no API access — and re-running it is safe.

**Experimental.** This importer is still gathering real-world testing. Enable it by setting `experimental_features = true` in [Settings](site-configuration.md) before running the script — it refuses to run otherwise.

## What you get

- Every published item in the export is imported. Known's RSS export is a partial WXR veneer: post content lives in `<description>` (not `<content:encoded>`), there's no `<wp:post_name>`, and the only date field is `<pubDate>` — so a post's `created` and `updated` timestamps are always identical.
- HTML post bodies are sanitised (`<script>`, `<style>`, `<iframe>` and `on*` event attributes are stripped) and converted to Markdown, same as the WordPress importer.
- **Known-specific cleanups.** Hidden link-preview markup (`unfurl-block` and its children) is removed entirely; every wrapper `<div>` is then unwrapped — Known's own structural divs (`e-content`, `entry-content`, `known-bookmark`, `photo-view`) as well as legacy authored divs carried over from earlier platforms, since a div surviving conversion would render as visibly escaped HTML; inline `<a class="p-category" rel="tag">#tag</a>` anchors (which point at the old, now-dead tag archive) are replaced with plain `#tag` text so the hashtag survives without a dead link; and photo posts' `<a data-gallery>` wrapper around the image is unwrapped to a bare `<img>`.
- **Status detection.** ~45% of a typical Known export is title-less "status update" posts, where Known synthesised a title from the post body. These are detected two ways — the title ends in `...`, or the body carries a microformats2 `p-name` class — and are imported as native, titleless Lamb status posts (permalink `/status/<id>`) rather than pinning the synthetic title. Posts with a real title keep it, and pin the `<link>` path leaf as their slug via front matter.
- **Bookmarks.** Items whose `<link>` points at an offsite page (not the export's own host) are bookmarks. A markdown link line — `[title](url)` — is prepended to the body, mirroring how Known rendered them, and the title is kept in front matter (unlike status posts).
- **Tags.** Known's `<category>#tag</category>` elements become inline `#hashtags` at the end of the body, same as WordPress categories/tags. Leading `#`, case and duplicates are normalised away, and any tag already present as an inline hashtag in the converted body (case-insensitively) is dropped rather than duplicated — Known posts often carry the same tag both ways. Known's structural tags are not imported at all: `#status` just marks a titleless status update (which Lamb models as a post without a title) and `#uncategorized` means no category.
- Every image referenced in the body is downloaded into `src/assets/YYYY/MM/` (using the post's own creation date), re-encoded to WebP and scaled down to the upload pipeline's max edge ([details](media.md)), and the body links are rewritten to point at the local copies — the same image pipeline the WordPress importer uses. `<enclosure>` elements are ignored: they always duplicate an image already inline in the body.
- Imported posts are **silent**: the importer calls the low-level save pipeline directly, so no outbound webmentions or WebSub hub pings are emitted. The content already exists somewhere else.

## Exporting from Known

In Known, go to **Site Configuration → Import/Export**, and export an **RSS** feed of your content.

## Running the importer

From the project root:

```bash
# Preview what would be imported without writing anything
php import-known.php /path/to/export.rss --dry-run

# Run it for real
php import-known.php /path/to/export.rss
```

The preferred entry point is the unified driver, which takes the same flags:

```bash
bin/lamb import known /path/to/export.rss --dry-run
```

`import-known.php` still works but is a deprecated shim that delegates to
`bin/lamb import known` and prints a warning; it is removed a release later.

The script prints one line per item (`imported:`, `would import:`, `replaced:` or `would replace:`) plus a final summary with the totals (created, existed, skipped). An item that was already imported in a previous run is recognised by its `import_uuid` (md5 of `'known-' + guid`) and left alone.

### `--replace`

By default, an item that was already imported is left alone on a second run — the importer counts it as `existed` and moves on. Pass `--replace` to re-apply it instead: body, title, slug, `created`/`updated` and draft state are all set back to what the export describes. The post keeps its id and its `import_uuid`, so its permalink and every redirect pointing at it still work.

This is deliberately total, not a merge. Local edits made to an imported post since the import are lost when you `--replace` it — that is what "replace" means. The case it exists for is Known's HTML→Markdown conversion: when a fix lands for something that came across badly, re-run the same export with `--replace` and take the new conversion.

Images already downloaded by an earlier run are not fetched again: the downloader names each file after `sha1(<source url>)` and returns the existing file when it is already on disk.

Imported posts are treated as **your own content**, not as syndicated feed items: they carry no "Via …" attribution line, and they take part in webmentions and WebSub exactly like a post you wrote in Lamb. The import run itself stays silent — nothing is pinged for content that was published years ago — but editing an imported post later notifies the way any other edit does.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There is no separate review queue.

A few things worth checking manually:

- **Slugs and redirects.** Every post keeps the slug from its original `<link>` path leaf, written into front matter as `slug:`, so imported posts stay on their old URL. Status posts (synthetic-title detection above) keep the slug but get no `title:`. A post whose `<link>` carries no usable slug — an offsite bookmark, for example — falls through to a `/status/<id>` permalink instead. Either way, an automatic 301 redirect is created from **both** the old on-host `<link>` path (e.g. `/2020/old-slug`) and the old `<guid>` path (`/view/<hash>`) to the new local URL, so old links and any bookmarks to the `guid` permalink keep working.
- **Bookmarks.** The bookmarked page's title and URL appear as a markdown link line at the top of the post body — edit it if you'd rather present it differently.
- **Media.** Run a quick `git status` on `src/assets/` to see what was downloaded.

## Related

- [WordPress import](wordpress-import.md) — the sibling importer this one shares its image-download and Markdown-conversion pipeline with.
- [Lamb import](lamb-import.md): Restore a Lamb export, rather than converting from another platform.
- [Cross-posting](cross-posting.md) — outbound syndication, the opposite direction.
- [Feeds](feeds.md) — how Lamb publishes content for other readers to ingest.
- [Media](media.md) — how uploaded images are stored and converted.
- [Export](export.md) — the same Markdown format, in the opposite direction.

---
title: Known import
parent: Import & export
---

# Importing from Known

Lamb ships a CLI script that reads a [Known CMS](https://withknown.com) RSS export and feeds each published item through Lamb's existing post-creation pipeline. The importer is fully offline — no credentials, no API access — and re-running it is safe.

This importer is experimental. [Import & export]({{ site.baseurl }}{% link import-export.md %}) covers the `--experimental` flag and what the three importers share.

## What you get

- Every published item in the export is imported. Known's RSS export is a partial WXR veneer: post content lives in `<description>` (not `<content:encoded>`), there's no `<wp:post_name>`, and the only date field is `<pubDate>` — so a post's `created` and `updated` timestamps are always identical.
- HTML post bodies are sanitised (`<script>`, `<style>`, `<iframe>` and `on*` event attributes are stripped) and converted to Markdown, same as the WordPress importer.
- **Known-specific cleanups.** Hidden link-preview markup (`unfurl-block` and its children) is removed entirely; every wrapper `<div>` is then unwrapped — Known's own structural divs (`e-content`, `entry-content`, `known-bookmark`, `photo-view`) as well as legacy authored divs carried over from earlier platforms, since a div surviving conversion would render as visibly escaped HTML; inline `<a class="p-category" rel="tag">#tag</a>` anchors (which point at the old, now-dead tag archive) are replaced with plain `#tag` text so the hashtag survives without a dead link; and photo posts' `<a data-gallery>` wrapper around the image is unwrapped to a bare `<img>`.
- **Status detection.** ~45% of a typical Known export is title-less "status update" posts, where Known synthesised a title from the post body. These are detected two ways — the title ends in `...`, or the body carries a microformats2 `p-name` class — and are imported as native, titleless Lamb status posts (permalink `/status/<id>`) rather than pinning the synthetic title. Posts with a real title keep it, and pin the `<link>` path leaf as their slug via front matter.
- **Bookmarks.** Items whose `<link>` points at an offsite page (not the export's own host) are bookmarks. A markdown link line — `[title](url)` — is prepended to the body, mirroring how Known rendered them, and the title is kept in front matter (unlike status posts).
- **Tags.** Known's `<category>#tag</category>` elements become inline `#hashtags` at the end of the body, same as WordPress categories/tags. Leading `#`, case and duplicates are normalised away, and any tag already present as an inline hashtag in the converted body (case-insensitively) is dropped rather than duplicated — Known posts often carry the same tag both ways. Known's structural tags are not imported at all: `#status` just marks a titleless status update (which Lamb models as a post without a title) and `#uncategorized` means no category.
- Every image referenced in the body is downloaded and converted as [Import & export]({{ site.baseurl }}{% link import-export.md %}#after-the-import) describes, and the body links are rewritten to point at the local copies. `<enclosure>` elements are ignored: they always duplicate an image already inline in the body. `<video>` elements and links to `.mp4`, `.webm` or `.mov` files in the body are downloaded unchanged and `<video>` elements become embedded players (a `poster` image is not imported), as described in [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}).
- An import run is silent: it sends no webmentions or WebSub pings. See [Import & export]({{ site.baseurl }}{% link import-export.md %}#import-runs-are-silent).

## Exporting from Known

In Known, go to **Site Configuration → Import/Export**, and export an **RSS** feed of your content.

## Running the importer

Run it as [Import & export]({{ site.baseurl }}{% link import-export.md %}) describes, with the path to your `.rss` file:

```bash
# Preview what would be imported without writing anything
sudo -u www-data bin/lamb import known /path/to/export.rss --dry-run --experimental

# Run it for real
sudo -u www-data bin/lamb import known /path/to/export.rss --experimental
```

The importer recognises an item it imported before by its `import_uuid`, the md5 of `'known-' + guid`. Add `--replace` to re-apply those items instead, as [Replacing imported posts]({{ site.baseurl }}{% link import-export.md %}#replacing-imported-posts) explains.

## After the import

The posts are visible at once, and [Import & export]({{ site.baseurl }}{% link import-export.md %}#after-the-import) covers what to check in `src/assets/`. Two things are specific to Known:

- **Slugs and redirects.** Every post keeps the slug from its original `<link>` path leaf, written into front matter as `slug:`, so imported posts stay on their old URL. Status posts (synthetic-title detection above) keep the slug but get no `title:`. A post whose `<link>` carries no usable slug — an offsite bookmark, for example — falls through to a `/status/<id>` permalink instead. Either way, an automatic 301 redirect is created from **both** the old on-host `<link>` path (e.g. `/2020/old-slug`) and the old `<guid>` path (`/view/<hash>`) to the new local URL, so old links and any bookmarks to the `guid` permalink keep working.
- **Bookmarks.** The bookmarked page's title and URL appear as a markdown link line at the top of the post body — edit it if you'd rather present it differently.

## Related

- [WordPress import](wordpress-import.md) — the sibling importer this one shares its image-download and Markdown-conversion pipeline with.
- [Lamb import](lamb-import.md): Restore a Lamb export, rather than converting from another platform.
- [Cross-posting](cross-posting.md) — outbound syndication, the opposite direction.
- [Feeds](feeds.md) — how Lamb publishes content for other readers to ingest.
- [Media](media.md) — how uploaded images are stored and converted.
- [Export](export.md) — the same Markdown format, in the opposite direction.

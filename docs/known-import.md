---
title: Known import
nav_order: 32
---

# Import from Known

Lamb ships a CLI script that reads a [Known CMS](https://withknown.com) RSS export and feeds each published item through Lamb's existing post-creation pipeline. The importer works fully offline, with no credentials and no API access, and re-running it is safe.

## What you get

- Lamb imports every published item in the export. Known's RSS export is a partial WXR veneer: post content lives in `<description>` rather than `<content:encoded>`, there's no `<wp:post_name>`, and the only date field is `<pubDate>`, so a post's `created` and `updated` timestamps are always identical.
- Lamb sanitises HTML post bodies, stripping `<script>`, `<style>`, `<iframe>`, and `on*` event attributes, then converts them to Markdown, the same as the WordPress importer.
- **Known-specific cleanups.** Lamb removes hidden link-preview markup (`unfurl-block` and its children) entirely, then unwraps every wrapper `<div>`. That covers Known's own structural divs (`e-content`, `entry-content`, `known-bookmark`, `photo-view`) as well as legacy authored divs carried over from earlier platforms, because a div surviving conversion would render as visibly escaped HTML. Lamb replaces inline `<a class="p-category" rel="tag">#tag</a>` anchors, which point at the old and now-dead tag archive, with plain `#tag` text, so the hashtag survives without a dead link. It also unwraps photo posts' `<a data-gallery>` wrapper around the image to a bare `<img>`.
- **Status detection.** About 45% of a typical Known export is titleless "status update" posts, where Known synthesised a title from the post body. Lamb detects these two ways: the title ends in `...`, or the body carries a microformats2 `p-name` class. It imports them as native titleless Lamb status posts with the permalink `/status/<id>`, rather than pinning the synthetic title. Posts with a real title keep it, and pin the `<link>` path leaf as their slug through front matter.
- **Bookmarks.** Items whose `<link>` points at an offsite page, rather than the export's own host, are bookmarks. Lamb prepends a Markdown link line, `[title](url)`, to the body, mirroring how Known rendered them, and keeps the title in front matter, unlike for status posts.
- **Tags.** Known's `<category>#tag</category>` elements become inline `#hashtags` at the end of the body, the same as WordPress categories and tags. Lamb normalises away a leading `#`, case differences, and duplicates. It drops any tag already present as an inline hashtag in the converted body, matched case-insensitively, rather than duplicating it, because Known posts often carry the same tag both ways. Lamb doesn't import Known's structural tags at all: `#status` only marks a titleless status update, which Lamb models as a post without a title, and `#uncategorized` means no category.
- Lamb downloads every image the body references into `src/assets/YYYY/MM/`, using the post's own creation date. It re-encodes each image to WebP and scales it down to the upload pipeline's maximum edge ([details](media.md)), then rewrites the body links to point at the local copies. This is the same image pipeline the WordPress importer uses. Lamb ignores `<enclosure>` elements, because they always duplicate an image already inline in the body.
- Imported posts are **silent**. The importer calls the low-level save pipeline directly, so it emits no outbound webmentions and no WebSub hub pings. The content already exists somewhere else.

## Export from Known

In Known, go to **Site Configuration → Import/Export** and export an **RSS** feed of your content.

## Run the importer

From the project root:

```bash
# Preview what the importer would do, without writing anything
php import-known.php /path/to/export.rss --dry-run

# Run it for real
php import-known.php /path/to/export.rss
```

The script prints one line per item, either `imported:` or `would import:`, plus a final summary with the totals for created, existed, and skipped. It recognises an item that a previous run already imported by its `feeditem_uuid`, the md5 of `'known-' + guid`, and leaves it alone.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There's no separate review queue.

A few things are worth checking manually:

- **Slugs and redirects.** Titled posts keep the slug from their original `<link>` path leaf, which the importer writes into front matter as `slug:`. Status posts, detected by the synthetic-title rules above, get no `slug:` or `title:` front matter and fall through to their `/status/<id>` permalink instead. Either way, Lamb creates an automatic 301 redirect from **both** the old on-host `<link>` path, such as `/2020/old-slug`, and the old `<guid>` path, `/view/<hash>`, to the new local URL. Old links and any bookmarks to the `guid` permalink therefore keep working.
- **Bookmarks.** The bookmarked page's title and URL appear as a Markdown link line at the top of the post body. Edit it if you'd rather present it differently.
- **Media.** Run `git status` on `src/assets/` to see what the importer downloaded.

## Related

- [WordPress import](wordpress-import.md): The sibling importer, which shares this one's image-download and Markdown-conversion pipeline.
- [Cross-posting](cross-posting.md): Outbound syndication, the opposite direction.
- [Feeds](feeds.md): How Lamb publishes content for other readers to ingest.
- [Media](media.md): How Lamb stores and converts uploaded images.
- [Export](export.md): The same Markdown format, in the opposite direction.

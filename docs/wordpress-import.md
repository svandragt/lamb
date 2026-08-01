---
title: WordPress import
---

# Import from WordPress

Lamb ships a CLI script that reads a [WordPress WXR export](https://wordpress.com/support/export/) and feeds each published post and page through Lamb's existing post-creation pipeline. The importer works fully offline, with no credentials and no API access, and re-running it is safe.

## What you get

The first-pass scope is deliberately small:

- Lamb imports published **posts** and **pages**. It skips drafts, private posts, custom post types, comments, menus, and theme settings.
- Lamb sanitises HTML post bodies, stripping `<script>`, `<style>`, `<iframe>`, and `on*` event attributes, then converts them to Markdown.
- Categories and tags become inline `#hashtags` at the end of the body, and Lamb's tag index picks them up automatically.
- Lamb downloads every image the body references into `src/assets/YYYY/MM/`, using the post's own creation date. It re-encodes each image to WebP and scales it down to the upload pipeline's maximum edge ([details](media.md)), then rewrites the body links to point at the local copies. Coverage includes `<img src>`, `data-full-url` (gallery blocks ship the original full-resolution URL there and a downscaled copy in `src`, and the importer prefers the full one), `data-src` for lazy-loaded images, and `<a href>` pointing at an image file, so "view full size" links survive the migration. Lamb also pulls in images hosted on a different host than `<wp:base_blog_url>`, because a typical WordPress migration ships images from a CDN or a previous domain. The downloader's content-type and extension checks keep arbitrary URLs safe. Downloads are capped at 20 MB and deduped by URL hash within a month, so Lamb fetches a logo used across many posts once. It leaves failed downloads, `data:` URIs, and unresolvable relative URLs unchanged.
- Imported posts are **silent**. The importer calls the low-level save pipeline directly, so it emits no outbound webmentions and no WebSub hub pings. The content already exists somewhere else.

## Export from WordPress

In your WordPress admin, go to **Tools → Export** and download **All content**. You get a `.xml` file in the WXR format.

## Run the importer

From the project root:

```bash
# Preview what the importer would do, without writing anything
php import-wordpress.php /path/to/wordpress.WordPress.xml --dry-run

# Run it for real
php import-wordpress.php /path/to/wordpress.WordPress.xml
```

The script prints one line per item, either `imported:` or `would import:`, plus a final summary with the totals for created, existed, and skipped. It recognises an item that a previous run already imported by its `feeditem_uuid`, the md5 of `'wordpress-' + guid`, and leaves it alone.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There's no separate review queue.

A few things are worth checking manually:

- **Slugs and redirects.** Page-like posts keep their original WordPress permalink leaf (`<wp:post_name>`), which the importer writes into front matter as `slug:`, so the URL is identical to the WordPress one relative to your new domain. Lamb imports titleless WordPress status posts whose old URL was `/status/<id>/` as Lamb status posts, and creates an automatic 301 redirect from the old WordPress path to the new local `/status/<local-id>` URL. Where a WordPress slug collides with an existing Lamb post or a reserved route, Lamb appends the post id, matching the standard create flow, so that post's URL differs. Set up a 301 if that matters to you.
- **Embedded shortcodes.** The importer doesn't expand WordPress shortcodes such as `[caption …]` or gallery shortcodes, so they appear verbatim in the Markdown. Edit any you care about after the import.
- **Media.** Run `git status` on `src/assets/` to see what the importer downloaded.

## Related

- [Known import](known-import.md): The sibling importer, which shares this one's image-download and Markdown-conversion pipeline.
- [Cross-posting](cross-posting.md): Outbound syndication, the opposite direction.
- [Feeds](feeds.md): How Lamb publishes content for other readers to ingest.
- [Media](media.md): How Lamb stores and converts uploaded images.
- [Export](export.md): The same Markdown format, in the opposite direction.

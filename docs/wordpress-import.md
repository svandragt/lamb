---
title: WordPress import
parent: Import & export
---

# Importing from WordPress

Lamb ships a CLI script that reads a [WordPress WXR export](https://wordpress.com/support/export/) and feeds each published post and page through Lamb's existing post-creation pipeline. The importer is fully offline — no credentials, no API access — and re-running it is safe.

This importer is experimental. [Import & export]({{ site.baseurl }}{% link import-export.md %}) covers the `--experimental` flag and what the three importers share.

## What you get

The first-pass scope is intentionally small:

- Published **Posts** and **Pages** are imported. Drafts, private posts, custom post types, comments, menus and theme settings are skipped.
- HTML post bodies are sanitised (`<script>`, `<style>`, `<iframe>` and `on*` event attributes are stripped) and converted to Markdown.
- Categories and tags become inline `#hashtags` at the end of the body — Lamb's tag index picks them up automatically.
- Every image referenced in the body is downloaded and converted as [Import & export]({{ site.baseurl }}{% link import-export.md %}#after-the-import) describes, and the body links are rewritten to point at the local copies. Coverage includes `<img src>`, `data-full-url` (gallery blocks ship the original full-resolution URL there and a downscaled copy in `src` — the importer prefers the full one), `data-src` (lazy-loaded), and `<a href>` that points at an image file (so "view full size" links survive the migration). Images on a different host than `<wp:base_blog_url>` are pulled in too — a typical WP migration ships images from a CDN or a previous domain, and the downloader's content-type and extension checks keep arbitrary URLs safe. Downloads are capped at 20 MB and deduped by URL hash within a month, so a logo used across many posts is fetched once. Failed downloads, data: URIs and unresolvable relative URLs are left as-is.
- Video files are downloaded too. A `<video src>`, a `<source src>` inside a `<video>`, and an `<a href>` that points at an `.mp4`, `.webm` or `.mov` file are saved into `src/assets/YYYY/MM/` exactly as downloaded and rewritten to the local copy. Classic WordPress `[video]` shortcodes that name an `.mp4`, `.webm` or `.mov` file with `src`, `mp4`, `webm` or `mov` are converted to a `<video>` first, so they are downloaded the same way; a shortcode with no such file, or one shown as an example inside `<code>`, `<pre>`, `<kbd>`, `<samp>` or a link, stays in the post as text. Video downloads are capped at 50 MB, and the server may label the file as a video or as a generic binary download. A video that is larger, fails to download, or has another file extension keeps pointing at the old host.
- A video with a supported file extension is imported as an embedded player, the same as a video you upload in the editor ([details]({{ site.baseurl }}{% link media.md %})). A `<video poster>` image is not imported, because Lamb's player has no poster image. A `<video>` with several sources uses the `.mp4` one, otherwise the first supported one. A `<video>` with no supported file becomes a plain link to its first URL, or is dropped when it has none.
- Imported posts are silent: they send no webmentions or WebSub pings. See [Import & export]({{ site.baseurl }}{% link import-export.md %}#imported-posts-are-silent).

## Exporting from WordPress

In your WordPress admin, go to **Tools → Export** and download **All content**. You'll get a `.xml` file (the WXR format).

## Running the importer

Run it as [Import & export]({{ site.baseurl }}{% link import-export.md %}) describes, with the path to your `.xml` file:

```bash
# Preview what would be imported without writing anything
sudo -u www-data bin/lamb import wordpress /path/to/wordpress.WordPress.xml --dry-run --experimental

# Run it for real
sudo -u www-data bin/lamb import wordpress /path/to/wordpress.WordPress.xml --experimental
```

The importer recognises a post it imported before by its `import_uuid`, the md5 of `'wordpress-' + guid`. Add `--replace` to re-apply those posts instead, as [Replacing imported posts]({{ site.baseurl }}{% link import-export.md %}#replacing-imported-posts) explains.

## After the import

The posts are visible at once, and [Import & export]({{ site.baseurl }}{% link import-export.md %}#after-the-import) covers what to check in `src/assets/`. Two things are specific to WordPress:

- **Slugs and redirects.** Page-like posts keep their original WordPress permalink leaf (`<wp:post_name>`), written into front matter as `slug:` so the URL is identical to the WP one (relative to your new domain). Titleless WordPress status posts whose old URL was `/status/<id>/` are imported as Lamb status posts and get an automatic 301 redirect from the old WordPress path to the new local `/status/<local-id>` URL. Where a WP slug collides with an existing Lamb post or a reserved route, Lamb appends the post id (matching the standard create flow), so that particular post's URL will differ — set up a 301 if it matters.
- **Embedded shortcodes.** WordPress shortcodes (`[caption …]`, gallery shortcodes, etc.) are not expanded — they appear verbatim in the Markdown. Edit any you care about after the import.

## Related

- [Known import](known-import.md) — the sibling importer this one shares its image-download and Markdown-conversion pipeline with.
- [Lamb import](lamb-import.md): Restore a Lamb export, rather than converting from another platform.
- [Cross-posting](cross-posting.md) — outbound syndication, the opposite direction.
- [Feeds](feeds.md) — how Lamb publishes content for other readers to ingest.
- [Media](media.md) — how uploaded images are stored and converted.
- [Export](export.md) — the same Markdown format, in the opposite direction.

---
title: WordPress import
parent: Import & export
---

# Importing from WordPress

Lamb ships a CLI script that reads a [WordPress WXR export](https://wordpress.com/support/export/) and feeds each published post and page through Lamb's existing post-creation pipeline. The importer is fully offline — no credentials, no API access — and re-running it is safe.

**Experimental.** This importer is still gathering real-world testing. Enable it by setting `experimental_features = true` in [Settings](site-configuration.md) before running the script — it refuses to run otherwise.

## What you get

The first-pass scope is intentionally small:

- Published **Posts** and **Pages** are imported. Drafts, private posts, custom post types, comments, menus and theme settings are skipped.
- HTML post bodies are sanitised (`<script>`, `<style>`, `<iframe>` and `on*` event attributes are stripped) and converted to Markdown.
- Categories and tags become inline `#hashtags` at the end of the body — Lamb's tag index picks them up automatically.
- Every image referenced in the body is downloaded into `src/assets/YYYY/MM/` (using the post's own creation date), re-encoded to WebP and scaled down to the upload pipeline's max edge ([details](media.md)), and the body links are rewritten to point at the local copies. Coverage includes `<img src>`, `data-full-url` (gallery blocks ship the original full-resolution URL there and a downscaled copy in `src` — the importer prefers the full one), `data-src` (lazy-loaded), and `<a href>` that points at an image file (so "view full size" links survive the migration). Images on a different host than `<wp:base_blog_url>` are pulled in too — a typical WP migration ships images from a CDN or a previous domain, and the downloader's content-type and extension checks keep arbitrary URLs safe. Downloads are capped at 20 MB and deduped by URL hash within a month, so a logo used across many posts is fetched once. Failed downloads, data: URIs and unresolvable relative URLs are left as-is.
- Video files are downloaded too. A `<video src>`, a `<source src>` inside a `<video>`, and an `<a href>` that points at an `.mp4`, `.webm` or `.mov` file are saved into `src/assets/YYYY/MM/` exactly as downloaded (Lamb doesn't re-encode video) and rewritten to the local copy. Classic WordPress `[video]` shortcodes that name an `.mp4`, `.webm` or `.mov` file with `src`, `mp4`, `webm` or `mov` are converted to a `<video>` first, so they are downloaded the same way; a shortcode with no such file, or one shown as an example inside `<code>`, `<pre>`, `<kbd>`, `<samp>` or a link, stays in the post as text. Video downloads are capped at 50 MB, and the server may label the file as a video or as a generic binary download. A video that is larger, fails to download, or has another file extension keeps pointing at the old host.
- A video with a supported file extension is imported as an embedded player, the same as a video you upload in the editor ([details](media.md)). A `<video poster>` image is not imported, because Lamb's player has no poster image. A `<video>` with several sources uses the `.mp4` one, otherwise the first supported one. A `<video>` with no supported file becomes a plain link to its first URL, or is dropped when it has none.
- Imported posts are **silent**: the importer calls the low-level save pipeline directly, so no outbound webmentions or WebSub hub pings are emitted. The content already exists somewhere else.

## Exporting from WordPress

In your WordPress admin, go to **Tools → Export** and download **All content**. You'll get a `.xml` file (the WXR format).

## Running the importer

From the project root, as the web server user rather than your own shell user (see [NGINX configuration](nginx.md)):

```bash
# Preview what would be imported without writing anything
sudo -u www-data bin/lamb import wordpress /path/to/wordpress.WordPress.xml --dry-run

# Run it for real
sudo -u www-data bin/lamb import wordpress /path/to/wordpress.WordPress.xml
```

`data/lamb.db` runs in WAL mode, and its `-wal`/`-shm` sidecar files inherit whichever user opens the database. Run the importer as your own user and it leaves those sidecars owned by you; a read-only connection can never checkpoint them away, so every subsequent web request then fails with "attempt to write a readonly database" until someone changes the sidecars' owner (see [NGINX configuration](nginx.md)). `bin/lamb` checks the database's owner before opening it and refuses to run as the wrong user, rather than let this happen.

The importer prints one line per item (`imported:`, `would import:`, `replaced:` or `would replace:`) plus a final summary with the totals (created, existed, skipped). An item that was already imported in a previous run is recognised by its `import_uuid` (md5 of `'wordpress-' + guid`) and left alone.

### `--replace`

By default, an item that was already imported is left alone on a second run — the importer counts it as `existed` and moves on. Pass `--replace` to re-apply it instead: body, title, slug, `created`/`updated` and draft state are all set back to what the WXR describes. The post keeps its id and its `import_uuid`, so its permalink and every redirect pointing at it still work.

This is deliberately total, not a merge. Local edits made to an imported post since the import are lost when you `--replace` it — that is what "replace" means. The case it exists for is re-running an export after fixing something in the conversion, where the freshly converted version is the one you want.

Images and videos already downloaded by an earlier run are not fetched again: the downloader names each file after `sha1(<source url>)` and returns the existing file when it is already on disk.

Imported posts are treated as **your own content**, not as syndicated feed items: they carry no "Via …" attribution line, and they take part in webmentions and WebSub exactly like a post you wrote in Lamb. The import run itself stays silent — nothing is pinged for content that was published years ago — but editing an imported post later notifies the way any other edit does.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There is no separate review queue.

A few things worth checking manually:

- **Slugs and redirects.** Page-like posts keep their original WordPress permalink leaf (`<wp:post_name>`), written into front matter as `slug:` so the URL is identical to the WP one (relative to your new domain). Titleless WordPress status posts whose old URL was `/status/<id>/` are imported as Lamb status posts and get an automatic 301 redirect from the old WordPress path to the new local `/status/<local-id>` URL. Where a WP slug collides with an existing Lamb post or a reserved route, Lamb appends the post id (matching the standard create flow), so that particular post's URL will differ — set up a 301 if it matters.
- **Embedded shortcodes.** WordPress shortcodes (`[caption …]`, gallery shortcodes, etc.) are not expanded — they appear verbatim in the Markdown. Edit any you care about after the import.
- **Media.** Run a quick `git status` on `src/assets/` to see what was downloaded.

## Related

- [Known import](known-import.md) — the sibling importer this one shares its image-download and Markdown-conversion pipeline with.
- [Lamb import](lamb-import.md): Restore a Lamb export, rather than converting from another platform.
- [Cross-posting](cross-posting.md) — outbound syndication, the opposite direction.
- [Feeds](feeds.md) — how Lamb publishes content for other readers to ingest.
- [Media](media.md) — how uploaded images are stored and converted.
- [Export](export.md) — the same Markdown format, in the opposite direction.

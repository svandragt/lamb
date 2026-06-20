---
title: WordPress import
---

# Importing from WordPress

Lamb ships a CLI script that reads a [WordPress WXR export](https://wordpress.com/support/export/) and feeds each published post and page through Lamb's existing post-creation pipeline. The importer is fully offline — no credentials, no API access — and re-running it is safe.

## What you get

The first-pass scope is intentionally small:

- Published **Posts** and **Pages** are imported. Drafts, private posts, custom post types, comments, menus and theme settings are skipped.
- HTML post bodies are sanitised (`<script>`, `<style>`, `<iframe>` and `on*` event attributes are stripped) and converted to Markdown.
- Categories and tags become inline `#hashtags` at the end of the body — Lamb's tag index picks them up automatically.
- Images referenced in the body that live on the source site are downloaded into `src/assets/YYYY/MM/` (using the post's own creation date), re-encoded to WebP where the [upload pipeline](media.md) does so, and the body links are rewritten to point at the local copies. Off-site images and failed downloads are left as remote `<img>` references.
- Imported posts are **silent**: the importer calls the low-level save pipeline directly, so no outbound webmentions or WebSub hub pings are emitted. The content already exists somewhere else.

## Exporting from WordPress

In your WordPress admin, go to **Tools → Export** and download **All content**. You'll get a `.xml` file (the WXR format).

## Running the importer

From the project root:

```bash
# Preview what would be imported without writing anything
php import-wordpress.php /path/to/wordpress.WordPress.xml --dry-run

# Run it for real
php import-wordpress.php /path/to/wordpress.WordPress.xml
```

The script prints one line per item (`imported:` or `would import:`) plus a final summary with the totals (created, existed, skipped). An item that was already imported in a previous run is recognised by its `feeditem_uuid` (md5 of `'wordpress-' + guid`) and left alone.

## After the import

The importer writes directly to the same database your site uses, so the posts are visible immediately. There is no separate review queue.

A few things worth checking manually:

- **Slugs and redirects.** Page-like posts keep their original WordPress permalink leaf (`<wp:post_name>`), written into front matter as `slug:` so the URL is identical to the WP one (relative to your new domain). Titleless WordPress status posts whose old URL was `/status/<id>/` are imported as Lamb status posts and get an automatic 301 redirect from the old WordPress path to the new local `/status/<local-id>` URL. Where a WP slug collides with an existing Lamb post or a reserved route, Lamb appends the post id (matching the standard create flow), so that particular post's URL will differ — set up a 301 if it matters.
- **Embedded shortcodes.** WordPress shortcodes (`[caption …]`, gallery shortcodes, etc.) are not expanded — they appear verbatim in the Markdown. Edit any you care about after the import.
- **Media.** Run a quick `git status` on `src/assets/` to see what was downloaded.

## Related

- [Cross-posting](cross-posting.md) — outbound syndication, the opposite direction.
- [Feeds](feeds.md) — how Lamb publishes content for other readers to ingest.
- [Media](media.md) — how uploaded images are stored and converted.

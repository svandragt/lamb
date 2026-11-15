---
title: Import & export
has_children: true
---

# Import & export

Move content in and out of Lamb. [Export]({{ site.baseurl }}{% link export.md %}) downloads your posts and images as an archive. `bin/lamb import` brings content in from one of three sources:

| Source | Reads | Guide |
|---|---|---|
| WordPress | A WXR export file | [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}) |
| Known | An RSS export | [Known import]({{ site.baseurl }}{% link known-import.md %}) |
| Lamb | A Lamb export, as a `.zip` or an unpacked directory | [Lamb import]({{ site.baseurl }}{% link lamb-import.md %}) |

This page covers what the three importers have in common. Each guide covers what is specific to its source.

## Before you start

**Experimental.** The importers are still gathering real-world testing. Pass `--experimental` to `bin/lamb import`, or the importer refuses to run.

**Run as the web server user.** Run `bin/lamb` from the project root as the user your web server or PHP-FPM runs as, usually `www-data`, not as your own shell user. `bin/lamb` refuses to run as another user. Running as your own user would leave the database's `-wal` and `-shm` files owned by you, and every later web request would then fail with "attempt to write a readonly database". [NGINX configuration]({{ site.baseurl }}{% link nginx.md %}#running-as-the-web-server-user) explains the failure and how to recover.

## Run an importer

Replace `<source>` with `wordpress`, `known` or `lamb`:

```bash
# Preview what would be imported without writing anything
sudo -u www-data bin/lamb import <source> /path/to/export --dry-run --experimental

# Run it for real
sudo -u www-data bin/lamb import <source> /path/to/export --experimental
```

The importer prints one line per item (`imported:`, `would import:`, `replaced:` or `would replace:`), then a summary with the totals created, existed and skipped.

Running an importer again is safe. Each imported post carries an `import_uuid`, and the importer leaves a post alone when it has already seen that value, counting it as `existed`.

## Replacing imported posts

To apply an item again instead of skipping it, pass `--replace`. The importer sets the post's body, title, slug, `created` and `updated` timestamps and draft state back to what the source describes. The post keeps its id and its `import_uuid`, so its permalink and every redirect pointing at it still work.

This is deliberately total, not a merge. Local edits made to a post since the import are lost. Use it after a fix to the conversion, when you want the freshly converted version.

## Imported posts are silent

The importers call the low-level save pipeline directly, so an import run sends no outbound webmentions and no WebSub hub pings. The content already exists somewhere else.

Imported posts are your own content, not syndicated feed items. They carry no "Via …" attribution line, and they take part in webmentions and WebSub like any post you wrote in Lamb. Editing an imported post later notifies as any other edit does.

## After the import

The importer writes to the same database your site uses, so the posts are visible at once. There is no review queue.

Run `git status` on `src/assets/` to see which images and video the importer added.

The WordPress and Known importers download the images and video a post references into `src/assets/YYYY/MM/`, using the post's own creation date. Images go through the same conversion as an editor upload, and video is stored as downloaded. [Media]({{ site.baseurl }}{% link media.md %}) describes both. The downloader names each file after `sha1(<source url>)` and reuses a file already on disk, so a second run or a `--replace` does not download it again.

---
title: Export
---

# Export

Lamb can hand you everything you've written as a single zip file. Go to **Settings → Export** and click **Download export**, or go to `/export` directly while logged in.

The archive is a complete backup of your writing: every post, the images and videos those posts use, and a machine-readable description of each post's state. It includes drafts and trashed posts too, because a backup that quietly dropped your unpublished work wouldn't be much of a backup.

Exporting deletes and changes nothing. You can do it as often as you like.

## What's in the archive

```
lamb-export-2026-07-26.zip
├── manifest.json
├── posts/
│   └── 2026/07/hello-world.md
└── assets/
    └── 2026/07/photo.webp
```

**`posts/`** holds one Markdown file per post, foldered by the year and month you created the post. Each file is exactly what Lamb stores: the YAML front matter (`title:`, `slug:`) followed by the body, with tags included as inline `#hashtags`. There's no export-specific wrapper to strip. These are ordinary Markdown files that you can open in any editor, keep in a Git repository, or feed to a static site generator.

The filename comes from the post's slug. Lamb names a post with no slug after its id, such as `post-42.md`. In the rare case where two posts would land on the same filename, it appends the id to keep them distinct.

**`assets/`** holds the images and videos your posts link to, at the same `YYYY/MM` paths the posts reference. Lamb includes only files that posts actually use, so an old upload that nothing links to any more doesn't appear. If a post links to a file that's no longer on disk, the export skips the file rather than failing. To find those files, compare `manifest.json` against your posts.

**`manifest.json`** describes the export and every post in it.

## The manifest

```json
{
  "format": "lamb-export/1",
  "generator": "lamb",
  "exported_at": "2026-07-26T14:32:44+00:00",
  "site": { "title": "My Microblog", "url": "https://example.com" },
  "counts": { "posts": 2, "assets": 1 },
  "posts": [
    {
      "path": "posts/2026/07/hello-world.md",
      "id": 1,
      "slug": "hello-world",
      "created": "2026-07-14 09:30:00",
      "updated": "2026-07-14 09:30:00",
      "draft": false,
      "deleted": false,
      "deleted_at": null,
      "post_version": 3,
      "feed_name": null,
      "feeditem_uuid": null,
      "source_url": null
    }
  ],
  "assets": ["assets/2026/07/photo.webp"]
}
```

Each entry in `posts` points at a file in the archive and records the things a Markdown file can't express on its own:

| Field | Meaning |
| --- | --- |
| `path` | Where the post's Markdown file sits in the archive. |
| `id` | The post's database id. |
| `slug` | The post's URL slug, as served. |
| `created` / `updated` | Local timestamps, `Y-m-d H:i:s`. A `created` date in the future means the post is [scheduled]({{ site.baseurl }}{% link scheduling.md %}). |
| `draft` | `true` for an unpublished [draft]({{ site.baseurl }}{% link drafts.md %}). |
| `deleted` / `deleted_at` | `true` and a timestamp for a post in the [trash]({{ site.baseurl }}{% link trash.md %}). |
| `post_version` | Which revision of Lamb's post pipeline last rendered the post. |
| `feed_name`, `feeditem_uuid`, `source_url` | Set when the post came from a subscribed feed rather than being written locally. Locally authored posts have `null` in all three. |

Two things are deliberately absent. Titles and bodies live in the Markdown file, so there's only ever one source of truth for your content. Preview tokens are access credentials for unpublished posts, and an export is a file you may pass around.

## Work with an export

Because the Markdown files are Lamb's own storage format, the export is the mirror image of an import. It's the same shape the [WordPress]({{ site.baseurl }}{% link wordpress-import.md %}) and [Known]({{ site.baseurl }}{% link known-import.md %}) importers produce when they convert a foreign export into Lamb posts.

In practice, that means you can:

- **Keep offsite backups** without copying `data/lamb.db`, and read them years later without Lamb or SQLite.
- **Move to another tool.** Most static site generators read front-matter Markdown directly. For formats that need conversion, such as WordPress WXR, the archive is a stable input for a converter to work from.
- **Search or process your archive** with ordinary tools: `grep`, a script over `manifest.json`, or whatever you prefer.

The `format` field identifies the layout described on this page. It changes only if a future version of Lamb makes a breaking change. New fields may be added within `lamb-export/1`, so a consumer should ignore fields it doesn't recognise.

## Requirements

Export needs PHP's `zip` extension. The Docker image and most PHP installations have it. If your server doesn't, `/export` tells you so instead of producing a broken file. Everything else Lamb does works without it.

## Related

- [Trash]({{ site.baseurl }}{% link trash.md %}): Exports include trashed posts and flag them in the manifest.
- [Drafts]({{ site.baseurl }}{% link drafts.md %}): Exports include drafts too.
- [Media]({{ site.baseurl }}{% link media.md %}): How Lamb stored and converted the assets in the archive.
- [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}): The same format, in the opposite direction.
- [Known import]({{ site.baseurl }}{% link known-import.md %}): Likewise.

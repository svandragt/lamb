---
title: Export
---

# Export

Lamb can hand you everything you have written as a single zip file. Go to **Settings → Export** and click **Download export**, or visit `/export` directly while logged in.

The archive is a complete backup of your writing: every post, the images and videos those posts use, and a machine-readable description of each post's state. Drafts and trashed posts are included too — a backup that quietly dropped your unpublished work would not be much of a backup.

Nothing is deleted or changed by exporting. You can do it as often as you like.

## What is in the archive

```
lamb-export-2026-07-26.zip
├── manifest.json
├── posts/
│   └── 2026/07/hello-world.md
└── assets/
    └── 2026/07/photo.webp
```

**`posts/`** holds one Markdown file per post, foldered by the year and month the post was created. Each file is exactly what Lamb stores: the YAML front matter (`title:`, `slug:`) followed by the body, tags included as inline `#hashtags`. There is no export-specific wrapper to strip — these are ordinary Markdown files you can open in any editor, keep in a git repository, or feed to a static site generator.

The filename comes from the post's slug. A post with no slug is named after its id (`post-42.md`), and in the rare case two posts would land on the same filename the id is appended to keep them distinct.

**`assets/`** holds the images and videos your posts link to, at the same `YYYY/MM` paths the posts reference. Only files that are actually used are included, so an old upload nothing links to any more will not appear. If a post links to a file that is no longer on disk, the export skips it rather than failing — compare `manifest.json` against your posts if you want to find those.

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

Each entry in `posts` points at a file in the archive and records the things a Markdown file cannot express on its own:

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

Deliberately absent: titles and bodies (they live in the Markdown file, so there is only ever one source of truth for your content) and preview tokens (they are access credentials for unpublished posts, and an export is a file you may pass around).

## Working with an export

Because the Markdown files are Lamb's own storage format, the export is the mirror image of an import — the same shape the [WordPress]({{ site.baseurl }}{% link wordpress-import.md %}) and [Known]({{ site.baseurl }}{% link known-import.md %}) importers produce when they convert a foreign export into Lamb posts.

Practically, that means you can:

- **Keep offsite backups** without copying `data/lamb.db`, and read them years later without Lamb or SQLite.
- **Move to another tool.** Most static site generators read front-matter Markdown directly. For formats that need conversion (WordPress WXR, for instance), the archive is a stable input for a converter to work from.
- **Search or process your archive** with ordinary tools — `grep`, a script over `manifest.json`, whatever you prefer.

The `format` field identifies the layout described on this page. It changes only if a future version of Lamb makes a breaking change; new fields may be added within `lamb-export/1`, so a consumer should ignore fields it does not recognise.

## Requirements

Export needs PHP's `zip` extension. It is present in the Docker image and in most PHP installations; if your server does not have it, `/export` says so instead of producing a broken file. Everything else Lamb does works without it.

## Related

- [Trash]({{ site.baseurl }}{% link trash.md %}) — trashed posts are included in the export and flagged in the manifest.
- [Drafts]({{ site.baseurl }}{% link drafts.md %}) — so are drafts.
- [Media]({{ site.baseurl }}{% link media.md %}) — how the assets in the archive were stored and converted.
- [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}) — the same format, in the opposite direction.
- [Known import]({{ site.baseurl }}{% link known-import.md %}) — likewise.

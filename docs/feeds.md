---
title: Feeds
---

# Feeds

Lamb publishes its content as both an [Atom](https://www.rfc-editor.org/rfc/rfc4287) feed and a [JSON Feed](https://www.jsonfeed.org/) (v1.1). Readers and indieweb tools can pick whichever format they prefer.

## Available endpoints

| Path | Format |
|------|--------|
| `/feed` | Atom |
| `/feed.json` | JSON Feed |
| `/tag/<tag>/feed` | Atom (single tag) |
| `/tag/<tag>/feed.json` | JSON Feed (single tag) |

Both formats are autodiscoverable via `<link rel="alternate">` tags in the HTML `<head>`, so a feed reader given the site URL will find them automatically.

## Titleless posts

Status-style posts without a title produce a feed entry with an empty `<title>` (Atom) or no `title` field at all (JSON Feed). This follows the [micro.blog convention](https://book.micro.blog/) so timeline-style readers can render them as short notes rather than empty-titled articles.

## Related

* [Cross-posting From Feeds]({% link cross-posting.md %}) — consuming external feeds into Lamb
* [Themes]({% link themes.md %}) — overriding `feed.php` / `feed_json.php` in a custom theme

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

## Feed icon and logo

The Atom feed can advertise an avatar and a banner image. Feed readers such as
micro.blog render the icon as the feed's avatar in their timeline.

These are sourced by convention from the web root (next to `index.php`) — no
configuration is needed:

| File | Atom element | Shape |
|------|--------------|-------|
| `favicon.png` | `<icon>` | small square avatar |
| `logo.png` | `<logo>` | wider banner (roughly 2:1) |

Drop either file into the `src/` directory (the web root). Each element is only
included when its file exists, so the feed never points at a missing image.

## Related

* [Cross-posting From Feeds]({{ site.baseurl }}{% link cross-posting.md %}) — consuming external feeds into Lamb
* [Themes]({{ site.baseurl }}{% link themes.md %}) — overriding `feed.php` / `feed_json.php` in a custom theme

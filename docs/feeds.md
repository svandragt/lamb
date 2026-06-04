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

| File | Atom element | Aspect ratio ([RFC 4287](https://www.rfc-editor.org/rfc/rfc4287)) |
|------|--------------|------------------------------------------------------------------|
| `favicon.png` | `<icon>` | 1:1 — small square avatar ([§4.2.5](https://www.rfc-editor.org/rfc/rfc4287#section-4.2.5)) |
| `logo.png` | `<logo>` | 2:1 — twice as wide as tall ([§4.2.8](https://www.rfc-editor.org/rfc/rfc4287#section-4.2.8)) |

The RFC recommends these aspect ratios but does not mandate pixel sizes. Drop
either file into the `src/` directory (the web root). Each element is only
included when its file exists, so the feed never points at a missing image.

## Real-time push (WebSub)

Feed readers normally poll for changes. With [WebSub](https://www.w3.org/TR/websub/),
subscribers can instead be pushed new posts the moment you publish.

Set a hub in the [site configuration]({{ site.baseurl }}{% link site-configuration.md %}):

```
websub_hub = https://pubsubhubbub.superfeedr.com/
```

With a hub configured, Lamb:

* advertises it in the Atom feed (`<link rel="hub">`) and the JSON Feed (`hubs` field), so WebSub-aware readers subscribe automatically;
* pings the hub whenever a post is published or updated (from the web interface or via Micropub), so it fetches the updated `/feed` and `/feed.json` and pushes them to subscribers.

Any public hub works — [Superfeedr](https://pubsubhubbub.superfeedr.com/) is a free
option. Drafts, scheduled posts, and cross-posted feed items do not trigger pings.

## Related

* [Cross-posting From Feeds]({{ site.baseurl }}{% link cross-posting.md %}) — consuming external feeds into Lamb
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}) — the `websub_hub` setting
* [Themes]({{ site.baseurl }}{% link themes.md %}) — overriding `feed.php` / `feed_json.php` in a custom theme

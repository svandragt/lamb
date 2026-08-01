---
title: Feeds
nav_order: 17
---

# Feeds

Lamb publishes its content as both an [Atom](https://www.rfc-editor.org/rfc/rfc4287) feed and a [JSON Feed](https://www.jsonfeed.org/) (v1.1). Readers and IndieWeb tools can pick whichever format they prefer.

## Available endpoints

| Path | Format |
|------|--------|
| `/feed` | Atom |
| `/feed.json` | JSON Feed |
| `/tag/<tag>/feed` | Atom (single tag) |
| `/tag/<tag>/feed.json` | JSON Feed (single tag) |

Both formats are autodiscoverable through `<link rel="alternate">` tags in the HTML `<head>`, so a feed reader finds them automatically when you give it the site URL.

## Titleless posts

Status-style posts without a title produce a feed entry with an empty `<title>` in Atom, or no `title` field at all in JSON Feed. This follows the [micro.blog convention](https://book.micro.blog/), so timeline-style readers can render them as short notes rather than as empty-titled articles.

## Feed icon and logo

The Atom feed can advertise an avatar and a banner image. Feed readers such as
micro.blog render the icon as the feed's avatar in their timeline.

Lamb sources these by convention from the web root, next to `index.php`. No
configuration is needed:

| File | Atom element | Aspect ratio ([RFC 4287](https://www.rfc-editor.org/rfc/rfc4287)) |
|------|--------------|------------------------------------------------------------------|
| `favicon.png` | `<icon>` | 1:1, a small square avatar ([§4.2.5](https://www.rfc-editor.org/rfc/rfc4287#section-4.2.5)) |
| `logo.png` | `<logo>` | 2:1, twice as wide as tall ([§4.2.8](https://www.rfc-editor.org/rfc/rfc4287#section-4.2.8)) |

The RFC recommends these aspect ratios but doesn't mandate pixel sizes. Put
either file in the `src/` directory, which is the web root. Lamb includes each
element only when its file exists, so the feed never points at a missing image.

## Real-time push (WebSub)

Feed readers normally poll for changes. With [WebSub](https://www.w3.org/TR/websub/),
subscribers can instead be pushed new posts the moment you publish.

Set one or more hubs, separated by commas, in the
[site configuration]({{ site.baseurl }}{% link site-configuration.md %}):

```
websub_hubs = https://hub.example.com/
```

With a hub configured, Lamb:

* advertises it in the Atom feed (`<link rel="hub">`) and the JSON Feed (`hubs` field), so WebSub-aware readers subscribe automatically;
* pings the hub whenever you publish or update a post, from the web interface or through Micropub, so the hub fetches the updated `/feed` and `/feed.json` and pushes them to subscribers.

Any public hub works. The IndieWeb wiki keeps a
[list of hubs](https://indieweb.org/WebSub#Hubs) to choose from.
Drafts and cross-posted feed items don't trigger pings. A **scheduled** post
can't ping at save time, because its publication time hasn't arrived yet, so
Lamb pings the hub for it on the next
[`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run after it
goes live. Keep `/_cron` on a schedule for timely real-time push of scheduled
posts.

## Related

* [Search engines]({{ site.baseurl }}{% link search-engines.md %}): `sitemap.xml` and `robots.txt`. Webmaster tools can ingest these feeds too.
* [Cross-posting from feeds]({{ site.baseurl }}{% link cross-posting.md %}): Consuming external feeds into Lamb.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): The `websub_hubs` setting.
* [Social embeds]({{ site.baseurl }}{% link social-embeds.md %}): The related `og-image.*` web-root convention for social preview cards.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Overriding `feed.php` and `feed_json.php` in a custom theme.

---
title: Search engines
---

# Search engines

Lamb helps search engines and other crawlers discover your content out of the
box. It generates a `sitemap.xml` and a `robots.txt` automatically. There's
nothing to configure and no files to add by hand.

## Available endpoints

| Path | Purpose |
|------|---------|
| `/sitemap.xml` | Lists every public URL for crawlers |
| `/robots.txt` | Allows crawling, points at the sitemap, hides admin paths |

## Sitemap

`/sitemap.xml` is a standard [sitemaps.org](https://www.sitemaps.org/) document.
It lists the home page followed by every publicly visible post and page, newest
first, each with a `<lastmod>` timestamp taken from when you last updated the
post.

The sitemap contains exactly what an anonymous visitor can see, so it omits:

* [drafts]({{ site.baseurl }}{% link drafts.md %});
* posts in the [trash]({{ site.baseurl }}{% link trash.md %});
* [scheduled]({{ site.baseurl }}{% link scheduling.md %}) posts dated in the future.

Unlike the timeline, the sitemap **does** include
[menu-item pages]({{ site.baseurl }}{% link menu-items.md %}), such as an
"About" page. They're real public URLs worth indexing.

Lamb caches the sitemap and supports conditional requests, so crawlers that
revisit it only re-download it when your content has actually changed.

## robots.txt

`/robots.txt` allows crawling, advertises the sitemap, and asks crawlers not to
waste time on the private routes: the login-gated admin pages and actions
(`/settings`, `/edit`, `/drafts`, `/trash`, `/scheduled`, `/delete`, `/restore`,
`/upload`, `/checkbox`) plus the internal `/login`, `/logout`, and `/_cron`
endpoints. Lamb derives the list from the routes themselves, so it stays
complete as the app grows. Those routes already require a login or are internal,
so this is a hint to crawlers rather than a security control.

### Override robots.txt

For full control, put your own `robots.txt` in the web root, which is the
`src/` directory next to `index.php`. When that file exists, Lamb serves it
verbatim and skips the generated one, so your version always wins.

## Submit your site

Point a search engine's webmaster tools at `https://your-site/sitemap.xml`. For
example, use [Google Search Console](https://search.google.com/search-console)
or [Bing Webmaster Tools](https://www.bing.com/webmasters/).

Many of those tools can also ingest a feed directly, so you can give them your
[Atom or JSON feed]({{ site.baseurl }}{% link feeds.md %}) instead, or as well.
The sitemap is the broadest signal, because it lists every public page rather
than only recent posts, so it's the recommended starting point.

## Related

* [Feeds]({{ site.baseurl }}{% link feeds.md %}): Atom and JSON Feed, which many webmaster tools also accept.
* [Menu items]({{ site.baseurl }}{% link menu-items.md %}): Pages that the sitemap includes but the timeline hides.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}) and [Drafts]({{ site.baseurl }}{% link drafts.md %}): Content the sitemap deliberately leaves out.

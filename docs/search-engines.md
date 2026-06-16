---
title: Search Engines
---

# Search Engines

Lamb helps search engines and other crawlers discover your content out of the
box. It generates a `sitemap.xml` and a `robots.txt` automatically — there is
nothing to configure and no files to drop in by hand.

## Available endpoints

| Path | Purpose |
|------|---------|
| `/sitemap.xml` | Lists every public URL for crawlers |
| `/robots.txt` | Allows crawling, points at the sitemap, hides admin paths |

## Sitemap

`/sitemap.xml` is a standard [sitemaps.org](https://www.sitemaps.org/) document.
It lists the home page followed by every publicly visible post and page, newest
first, each with a `<lastmod>` timestamp taken from when the post was last
updated.

It contains exactly what an anonymous visitor can see, so it omits:

* [drafts]({{ site.baseurl }}{% link drafts.md %});
* posts in the [trash]({{ site.baseurl }}{% link trash.md %});
* [scheduled]({{ site.baseurl }}{% link scheduling.md %}) posts dated in the future.

Unlike the timeline, the sitemap **does** include
[menu-item pages]({{ site.baseurl }}{% link menu-items.md %}) (such as an
"About" page) — they are real public URLs worth indexing.

The sitemap is cached and supports conditional requests, so crawlers that
revisit it only re-download it when your content has actually changed.

## robots.txt

`/robots.txt` allows crawling, advertises the sitemap, and asks crawlers not to
waste time on the private routes — the login-gated admin pages and actions
(`/settings`, `/edit`, `/drafts`, `/trash`, `/scheduled`, `/delete`, `/restore`,
`/upload`, `/checkbox`) plus the internal `/login`, `/logout`, and `/_cron`
endpoints. The list is derived automatically from the routes themselves, so it
stays complete as the app grows. Those routes already require a login (or are
internal), so this is a hint to crawlers rather than a security control.

### Overriding robots.txt

If you want full control, drop your own `robots.txt` into the web root (the
`src/` directory, next to `index.php`). When that file exists Lamb serves it
verbatim and skips the generated one, so your version always wins.

## Submitting your site

Point a search engine's webmaster tools (for example
[Google Search Console](https://search.google.com/search-console) or
[Bing Webmaster Tools](https://www.bing.com/webmasters/)) at
`https://your-site/sitemap.xml`.

Many of those tools can also ingest a feed directly, so as an alternative — or
in addition — you can give them your
[Atom or JSON feed]({{ site.baseurl }}{% link feeds.md %}). The sitemap is the
broadest signal (it lists every public page, not just recent posts), so it is
the recommended starting point.

## Related

* [Feeds]({{ site.baseurl }}{% link feeds.md %}) — Atom / JSON Feed, also accepted by many webmaster tools
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %}) — pages that the sitemap includes but the timeline hides
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}) and [Drafts]({{ site.baseurl }}{% link drafts.md %}) — content the sitemap deliberately leaves out

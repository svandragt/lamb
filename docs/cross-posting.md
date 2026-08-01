---
title: Cross-posting from feeds
nav_order: 20
---

# Cross-posting from feeds

You can set Lamb up with a network of feeds, so that it periodically cross-posts external content to your blog.

Although each blog supports only one author, there's no limit to the number of network feeds. You can therefore
create a group or team blog by subscribing to other Lamb blogs, or centralise content from your accounts on other
services.

Lamb supports all [feed types that SimplePie supports](https://www.simplepie.org/wiki/faq/what_versions_of_rss_or_atom_do_you_support)
(RSS and Atom), as well as [JSON Feed](https://www.jsonfeed.org/) (v1 and v1.1).
Lamb parses a feed whose URL ends in `.json` as JSON Feed, and handles everything else as RSS or Atom.
Imported items get the same treatment whatever the source format: drafted by default, deduplicated,
and shown with a citation back to the original.

## Set up feeds

Add a `[feeds]` section to your site configuration at `/settings`, with one or more entries
in the format `name = feed url`:

```ini
[feeds]
Test Feed = https://vandragt.com/feed
```

You also need to call the `<your_site>/_cron` endpoint whenever you want to check for new content. You could do this
by adding a cron job on the server, or through an external service that calls the endpoint.
Lamb can't check more often than once a minute, and it caches each feed individually for 30 minutes
to avoid spamming the endpoint.

Calling the [`_cron` endpoint]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) is your responsibility, unlike in other CMSes you might be used to.

## What Lamb imports when you add a feed

The first cron run after you add a feed imports every item currently in the feed, which is typically the publisher's most recent 10 to 20 entries, depending on how many they include. It doesn't reach back through the publisher's full archive.

After that first run, Lamb imports an item when it's newer than the newest entry the feed has offered before, and re-syncs an already-imported post when the publisher has changed the item since Lamb last copied it.

Lamb matches an item to its existing post by a stable identifier, so a later crawl never duplicates something it has already imported, even if the publisher restamps the item's date.

Both comparisons use dates the *feed* supplies, never the time of the crawl. Earlier versions compared an
item's date against the clock time of the last successful crawl, which quietly dropped items: if a crawl succeeded
against a cached or lagging copy of the feed that didn't yet list the item, the item's own date was by then older than
that crawl, so Lamb never imported it. Upgrading fixes this, and the first crawl after you upgrade imports anything
still in the feed window that was lost this way.

## Edit an imported post

Imported posts start as [drafts]({{ site.baseurl }}{% link drafts.md %}) by default. As soon as you edit one through Lamb, for example to publish it, change its slug, or tidy the text, Lamb treats it as yours: later crawls stop syncing changes from the source onto it, so nothing overwrites your edits, and Lamb still never duplicates the post. Posts you haven't edited continue to pick up updates from the source feed.

## Check crawl status

When `/_cron` runs unattended you don't see its live output, so Lamb records the health
of each feed and shows it on the **Logs** tab of `/settings`, which requires a login:

| Column | Meaning |
|--------|---------|
| Feed | The feed name and URL from your `[feeds]` configuration. |
| Last success | When Lamb last crawled the feed successfully, or *Never*. |
| Items | How many items Lamb created or updated on the last successful run. |
| Last error | The most recent fetch error and when it occurred, if any. |

A feed that fails to fetch, through a DNS failure, timeout, or malformed response, keeps its previous
*Last success* time and shows the error instead, so a failing feed no longer masquerades as
up to date. Lamb retries failed feeds on the normal 30-minute schedule, and cleans up status rows for
feeds you remove from the configuration on the next cron run.

## Related

* [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): How to call the `/_cron` endpoint periodically.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The Atom and JSON feeds Lamb publishes for your own posts.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Lamb saves feed-ingested posts as drafts by default.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): Where you configure the `[feeds]` section.
* [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}): One-off migration from a WordPress WXR export.

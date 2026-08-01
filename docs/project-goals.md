---
title: Project goals
nav_order: 36
---

# Project goals

These design goals shape Lamb. They're recorded here as the project's guiding
intent rather than as open work items. The [README](https://github.com/svandragt/lamb/blob/main/README.md)
captures the underlying philosophy:

- Simple over complex
- Opinionated defaults over settings
- Assume success, communicate failure

## A frictionless blog

Posting should get out of the way. Lamb deliberately has:

- **No post screen or admin dashboard.** You write from the site itself.
- **Inline tagging.** Type `#hashtags` directly in a post, Twitter-style. See [Search]({{ site.baseurl }}{% link search.md %}).
- **Drag-and-drop images.** Drop an image into the post to embed it in the content. See [Media]({{ site.baseurl }}{% link media.md %}).
- **Config-file menus.** Edit the menu by adding a line to the configuration. See [Menu items]({{ site.baseurl }}{% link menu-items.md %}).
- **Markdown front matter.** Set a title and a custom slug through YAML front matter. See [Post types]({{ site.baseurl }}{% link post-types.md %}).

Lamb delivers all of these today.

## An RSS aggregator

Lamb doubles as a personal feed reader. You can pull in external feeds and read
them alongside your own posts.

- **Import existing feeds.** List your subscriptions as network feeds and Lamb ingests them. See [Cross-posting from feeds]({{ site.baseurl }}{% link cross-posting.md %}).
- **Per-tag feeds.** Every tag has its own Atom and JSON feed. See [Feeds]({{ site.baseurl }}{% link feeds.md %}).
- **Scheduled crawling.** Lamb fetches feeds on a schedule. See [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}).

Email delivery of new items, such as a daily digest, has been considered but is
intentionally out of scope, to keep the aggregator focused.

## Related

* [Feeds]({{ site.baseurl }}{% link feeds.md %})
* [Cross-posting from feeds]({{ site.baseurl }}{% link cross-posting.md %})
* [Post types]({{ site.baseurl }}{% link post-types.md %})
* [Media]({{ site.baseurl }}{% link media.md %})

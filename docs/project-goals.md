---
title: Project Goals
---

# Project Goals

These are the design goals that shape Lamb. They are recorded here as the
project's guiding intent rather than as open work items. The underlying
philosophy is captured in the [README](https://github.com/svandragt/lamb/blob/main/README.md):

- Simple over complex
- Opinionated defaults over settings
- Assume success, communicate failure

## A frictionless blog

Posting should get out of the way. Lamb deliberately has:

- **No post screen or admin dashboard** — you write from the site itself.
- **Inline tagging** — type `#hashtags` directly in a post, Twitter-style. See [Search]({{ site.baseurl }}{% link search.md %}).
- **Drag-and-drop images** — drop an image into the post to embed it in the content. See [Media]({{ site.baseurl }}{% link media.md %}).
- **Config-file menus** — edit the menu by adding a line to the configuration. See [Menu Items]({{ site.baseurl }}{% link menu-items.md %}).
- **Markdown front matter** — set a title and a custom slug via YAML front matter. See [Post Types]({{ site.baseurl }}{% link post-types.md %}).

These are all delivered today.

## An RSS aggregator

Lamb doubles as a personal feed reader: external feeds can be pulled in and
read alongside your own posts.

- **Import existing feeds** — list your subscriptions as network feeds and Lamb ingests them. See [Cross-posting From Feeds]({{ site.baseurl }}{% link cross-posting.md %}).
- **Per-tag feeds** — every tag has its own Atom and JSON feed. See [Feeds]({{ site.baseurl }}{% link feeds.md %}).
- **Scheduled crawling** — feeds are fetched on a schedule. See [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}).

Email delivery of new items (e.g. a daily digest) has been considered but is
intentionally left out of scope to keep the aggregator focused.

## Related

* [Feeds]({{ site.baseurl }}{% link feeds.md %})
* [Cross-posting From Feeds]({{ site.baseurl }}{% link cross-posting.md %})
* [Post Types]({{ site.baseurl }}{% link post-types.md %})
* [Media]({{ site.baseurl }}{% link media.md %})

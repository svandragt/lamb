---
title: Cross-posting From Feeds
---

# Cross-posting From Feeds

Lamb can be setup with a network of feeds so that external content is periodically cross-posted to your blog.

Although each blog supports only one author, there is no limit to the number of network feeds. Therefore, this feature
enables the creation of a group or team blog by subscribing to other Lamb blogs, or can help centralize content
from accounts on other services.

All [feed types supported by SimplePie](https://www.simplepie.org/wiki/faq/what_versions_of_rss_or_atom_do_you_support)
are supported.

## Setup

To set up feeds, add a `[feeds]` section to your site configuration at `/settings`, with one or more entries
in the format of `name = feed url`:

```ini
[feeds]
Test Feed = https://vandragt.com/feed
```

You will also need to call the `<your_site>/_cron` endpoint whenever you want to check for new content. One of the ways
this can be done is by adding a cron job on the server or via an external service that calls this endpoint.
It's not possible to check more often than once every minute, and each feed individually will be cached for 30 minutes
to avoid spamming the endpoint.

It is your responsibility to [call the `_cron` endpoint]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}), unlike other CMSes you might be used to.

## What gets imported when you add a feed

The first cron run after adding a feed imports every item currently present in the feed itself — typically the publisher's most recent 10–20 entries, depending on how many they include. It does not reach back through the publisher's full archive.

After that first run, only items published or updated since the previous run are imported.

## Related

* [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): How to call the `/_cron` endpoint periodically.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The Atom and JSON feeds Lamb publishes for your own posts.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Feed-ingested posts are saved as drafts by default.
* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): The `[feeds]` section is configured here.

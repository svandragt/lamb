# Cross-posting From Feeds

Lamb can be setup with a network of feeds so that external content is periodically cross-posted to your blog.

Although each blog supports only one author, there is no limit to the number of network feeds. Therefore, this feature
enables the creation of a group or team blog by subscribing to other Lamb blogs, or can help centralize content
from accounts on other services.

All [feed types supported by SimplePie](https://www.simplepie.org/wiki/faq/what_versions_of_rss_or_atom_do_you_support)
are supported.

## Setup

You must have a `src/config.ini`. To setup an example feed add a new `feeds` section and one or more subs
in the format of `name = feed url`:

```ini
; Example 
[feeds]
Test Feed = https://vandragt.com/feed
```

You will also need to call the `<your_site>/_cron` endpoint whenever you want to check for new content. One of the ways
this can be done is by adding a cron job on the server or via an external service that calls this endpoint.
It's not possible to check more often than once every minute, and each feed individually will be cached for 30 minutes
to avoid spamming the endpoint.

It is your responsibility to call the `_cron` endpoint, unlike other CMSes you might be used to. If you don't have a
server, you could setup a website monitor, a local scheduled task, maybe even IFTTT or Zapier. Be creative!

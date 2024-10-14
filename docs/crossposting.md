# Crossposting From Feeds

Lamb can be setup with a network of feeds so that external content is periodically cross-posted to your blog.

Although each blog supports only one author, there is no limit to the number of network feeds. Therefore, this feature
enables the
creation of a group or team blog by subscribing to other Lamb blogs; or can help centralize content
from accounts on other services.

## Setup

You must have a `src/config.ini`. To setup an example feed add a new `network_feeds` section and one or more subs
in the format of `name = url`:

```ini
; Example 
[network_feeds]
Test Feed = https://2022.vandragt.com/feed/
```

You will also need to call the `<your_site>/_cron` endpoint whenever you want to check for new content. One of the ways
this can be done is by adding a cron job on the server or via an external service that calls this endpoint.

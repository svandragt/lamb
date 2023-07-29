# Feed Subscriptions

Lamb introduces the concept of a Flock, and you may know this as RSS feed subscriptions. 
A Lamb blog can subscribe to multiple RSS sources and create posts whenever there is new content in the source feed.

Each lamb blog supports only one author, but can have multiple RSS sources. Therefore, this feature enables the 
creation of a group or team blog by subscribing to other Lamb blogs; or can help you centralize content 
from your accounts on other services. 

## Setup

You must have a `src/config.ini`. To setup an example feed add a new `flock_subscriptions` section and one or more subs
in the format of `name = url`:

```ini
; Example 
[flock_subscriptions]
Test Feed = https://2022.vandragt.com/feed/
```

You will also need to call the `<your_site>/_flock` endpoint whenever you want to check for new content. One of the ways this
can be done is by adding a cron job on the server or via an external service that calls this endpoint.

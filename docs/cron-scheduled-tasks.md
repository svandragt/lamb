---
title: Cron Scheduled Tasks
---

# Cron Scheduled Tasks

Lamb has an scheduled task endpoint that can be called periodically to run tasks in the background, available at `/_cron`.

The following tasks run periodically:

1. [Crossposting]({% link cross-posting.md %}) new content from feeds.

Note that the feed system has its own rate limiting system to prevent sending too many requests to the feed provider, so checking more often than every 30 minutes or so is not typically useful.

## How-to

It's your responsibility to call the endpoint periodically. If you don't have a server, you could setup a website monitor, a local scheduled task, maybe even IFTTT or Zapier. Be creative!

For example the linux cron system can be setup as follows:

```
# Schedule taks every 30 minutes
*/30 * * * * /usr/bin/curl -s https://example.com/_cron > /dev/null 2>&1
```

## Related

* [Cross-posting]({% link cross-posting.md %}): Feed syndication that runs via the cron endpoint.
* [Drafts]({% link drafts.md %}): Feed-ingested posts are saved as drafts by default.

---
title: Cron Scheduled Tasks
---

# Cron Scheduled Tasks

Lamb has an scheduled task endpoint that can be called periodically to run tasks in the background, available at `/_cron`.

(Looking to publish a post at a future date instead? See [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).)

The following tasks run periodically:

1. **Purging trash.** Posts in the [trash]({{ site.baseurl }}{% link trash.md %}) are permanently deleted once they have been there for 30 days.
2. **Crossposting.** New content from your configured [feeds]({{ site.baseurl }}{% link feeds.md %}) is [ingested]({{ site.baseurl }}{% link cross-posting.md %}) as posts.
3. **Sending webmentions.** Any pending outbound [webmentions]({{ site.baseurl }}{% link webmentions.md %}) for your posts are delivered.

Note that the feed system has its own rate limiting system to prevent sending too many requests to the feed provider, so checking more often than every 30 minutes or so is not typically useful.

## How-to

It's your responsibility to call the endpoint periodically. If you don't have a server, you could setup a website monitor, a local scheduled task, maybe even IFTTT or Zapier. Be creative!

For example the linux cron system can be setup as follows:

```
# Schedule taks every 30 minutes
*/30 * * * * /usr/bin/curl -s https://example.com/_cron > /dev/null 2>&1
```

## Related

* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed syndication that runs via the cron endpoint.
* [Trash]({{ site.baseurl }}{% link trash.md %}): Trashed posts are purged after 30 days by the cron.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Outbound webmentions are sent during the cron run.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Feed-ingested posts are saved as drafts by default.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Publishing a post at a future date (a different feature, despite the similar name).

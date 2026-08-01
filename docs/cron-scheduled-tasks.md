---
title: Cron scheduled tasks
nav_order: 28
---

# Cron scheduled tasks

Lamb has a scheduled-task endpoint at `/_cron`. Call it periodically to run background tasks.

To publish a post at a future date instead, see [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).

The following tasks run periodically:

1. **Purging trash.** Lamb permanently deletes posts that have been in the [trash]({{ site.baseurl }}{% link trash.md %}) for 30 days.
2. **Cross-posting.** Lamb [ingests]({{ site.baseurl }}{% link cross-posting.md %}) new content from your configured [feeds]({{ site.baseurl }}{% link feeds.md %}) as posts.
3. **Sending webmentions.** Lamb delivers any pending outbound [webmentions]({{ site.baseurl }}{% link webmentions.md %}) for your posts.

The feed system has its own rate limiting to avoid sending too many requests to the feed provider, so calling the endpoint more often than every 30 minutes rarely helps.

## Call the endpoint on a schedule

Calling the endpoint periodically is your responsibility. If you don't have a server, you could set up a website monitor, a local scheduled task, or a service such as IFTTT or Zapier.

For example, set up the Linux cron system as follows:

```
# Schedule the task every 30 minutes.
*/30 * * * * /usr/bin/curl -s https://example.com/_cron > /dev/null 2>&1
```

## Related

* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed syndication that runs through the cron endpoint.
* [Trash]({{ site.baseurl }}{% link trash.md %}): The cron run purges trashed posts after 30 days.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Lamb sends outbound webmentions during the cron run.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Lamb saves feed-ingested posts as drafts by default.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Publishing a post at a future date. This is a different feature, despite the similar name.

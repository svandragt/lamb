---
title: Cron Scheduled Tasks
parent: Installation & hosting
---

# Cron Scheduled Tasks

Lamb has an scheduled task endpoint that can be called periodically to run tasks in the background, available at `/_cron`.

(Looking to publish a post at a future date instead? See [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).)

The following tasks run periodically:

1. **Migrating data after an upgrade.** When the code of Lamb has changed since the last run, the first run applies the [data migrations]({{ site.baseurl }}{% link upgrading.md %}#data-migrations) before the other tasks, even when they are not due yet. The reply opens with a `Data migrations:` line that says `migrated`, `already current`, `busy` or `failed`. A failure answers with HTTP 500, writes the error to the PHP error log and stops the run, so no other task works on half-migrated data. The same code is tried again no sooner than a minute later. With PHP-FPM or FrankenPHP, a run that migrated ends its reply after that line while the other tasks keep running. A run with nothing to migrate keeps its reply open and prints each task's result, as before. The PHP built-in server (`viv run serve`) keeps the connection open until every task finishes.
2. **Purging trash.** Posts that have been in the [trash]({{ site.baseurl }}{% link trash.md %}#permanent-deletion) long enough are permanently deleted.
3. **Crossposting.** New content from your configured [feeds]({{ site.baseurl }}{% link feeds.md %}) is [ingested]({{ site.baseurl }}{% link cross-posting.md %}) as posts.
4. **Queueing missed webmentions.** If saving a post could not queue its [webmentions]({{ site.baseurl }}{% link webmentions.md %}), the next run queues them.
5. **Sending webmentions.** Any pending outbound [webmentions]({{ site.baseurl }}{% link webmentions.md %}) for your posts are delivered.

Note that the feed system has its own rate limiting system to prevent sending too many requests to the feed provider, so checking more often than every 30 minutes or so is not typically useful.

## How-to

It's your responsibility to call the endpoint periodically. If you don't have a server, you could setup a website monitor, a local scheduled task, maybe even IFTTT or Zapier. Be creative!

For example the linux cron system can be setup as follows:

```
# Schedule taks every 30 minutes
*/30 * * * * /usr/bin/curl -s https://example.com/_cron > /dev/null 2>&1
```

For a Docker install, schedule the request from the host with `docker exec`. See [Scheduled tasks]({{ site.baseurl }}{% link docker.md %}#scheduled-tasks).

## Related

* [Upgrading]({{ site.baseurl }}{% link upgrading.md %}#data-migrations): The first run after an upgrade applies the data migrations.
* [Docker]({{ site.baseurl }}{% link docker.md %}#scheduled-tasks): Scheduling the cron request for a container.
* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed syndication that runs via the cron endpoint.
* [Trash]({{ site.baseurl }}{% link trash.md %}): Trashed posts are purged by the cron.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Outbound webmentions are sent during the cron run.
* [SocialSpec]({{ site.baseurl }}{% link socialspec.md %}): The following list at `following_url` is refreshed during the cron run.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Feed-ingested posts are saved as drafts by default.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Publishing a post at a future date (a different feature, despite the similar name).

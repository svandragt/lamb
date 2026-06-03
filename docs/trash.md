---
title: Trash
---

# Trash

When you delete a post, it is soft-deleted — moved to the trash rather than permanently removed. This lets you recover posts if you delete them by mistake.

## Viewing the trash

Deleted posts are accessible at `/trash` when logged in. They are listed in reverse order of deletion.

## Restoring a post

From the `/trash` page, use the restore button on any post to move it back to published status.

## Permanent deletion

Posts in the trash are permanently deleted once they have been there for 30 days. This happens automatically during the [cron run]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}), so the trash purges itself as long as the cron endpoint is being called.

## Related

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): The types of posts that can be deleted.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Drafts are separate from the trash.
* [Cron / Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): Runs the 30-day trash purge.

---
title: Trash
---

# Trash

When you delete a post, Lamb soft-deletes it: the post moves to the trash instead of being removed permanently. This lets you recover posts you delete by mistake.

## View the trash

When you're logged in, deleted posts are available at `/trash`, listed in reverse order of deletion.

## Restore a post

On the `/trash` page, click the restore button on any post to move it back to published status.

## Permanent deletion

Lamb permanently deletes posts that have been in the trash for 30 days. This happens during the [cron run]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}), so the trash purges itself as long as something calls the cron endpoint.

## Related

* [Post types]({{ site.baseurl }}{% link post-types.md %}): The types of posts you can delete.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Drafts are separate from the trash.
* [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): Runs the 30-day trash purge.
* [Export]({{ site.baseurl }}{% link export.md %}): Exports include trashed posts and flag them in the manifest.

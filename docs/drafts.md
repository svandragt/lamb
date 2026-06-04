---
title: Drafts
---

# Drafts

Posts can be saved as drafts by adding `draft: true` to the front-matter.

When logged in, all drafts are available from `/drafts`.

Feed ingested posts are saved as drafts by default to prioritize authorship over syndication. Should you prefer, add `feeds_draft = false` to the site settings and they will be published instead.

## Related

- [Post Types]({{ site.baseurl }}{% link post-types.md %}): Front-matter is used to set `draft: true`.
- [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Hide a post until a future `created` date.
- [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed ingestion that produces drafts.
- [Micropub]({{ site.baseurl }}{% link micropub.md %}): Drafts created via Micropub get a 24-hour preview link.
- [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): The cron endpoint triggers feed ingestion.

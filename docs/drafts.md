---
title: Drafts
---

# Drafts

Posts can be saved as drafts by adding `draft: true` to the front-matter.

When logged in, all drafts are available from `/drafts`.

Each draft (and scheduled post) shows a **Preview** link next to its Edit button. The link carries an expiring token valid for 24 hours, so you can share it with someone who isn't logged in. A fresh token is issued whenever you save the post after the old one expires.

Feed ingested posts are saved as drafts by default to prioritize authorship over syndication. Should you prefer, add `feeds_draft = false` to the site settings and they will be published instead. Place it in the top-level section, above any `[section]` headers — inside `[feeds]` it would be read as a feed entry, not a setting.

## Related

- [Post Types]({{ site.baseurl }}{% link post-types.md %}): Front-matter is used to set `draft: true`.
- [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Hide a post until a future `created` date.
- [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed ingestion that produces drafts.
- [Micropub]({{ site.baseurl }}{% link micropub.md %}): Drafts created via Micropub get the same 24-hour preview link.
- [Cron Scheduled Tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): The cron endpoint triggers feed ingestion.

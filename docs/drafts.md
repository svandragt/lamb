---
title: Drafts
---

# Drafts

Posts can be saved as drafts by adding `draft: true` to the front-matter.

When logged in, all drafts are available from `/drafts`.

Feed ingested posts are saved as drafts by default to prioritize authorship over syndication. Should you prefer, add `feeds_draft = false` to the site settings and they will be published instead.

## Related

- [Post Types]({% link post-types.md %}): Front-matter is used to set `draft: true`.
- [Cross-posting]({% link cross-posting.md %}): Feed ingestion that produces drafts.
- [Cron Scheduled Tasks]({% link cron-scheduled-tasks.md %}): The cron endpoint triggers feed ingestion.

---
title: Drafts
---

# Drafts

To save a post as a draft, add `draft: true` to its front matter.

When you're logged in, all drafts are available at `/drafts`.

Each draft and scheduled post shows a **Preview** link next to its **Edit** button. The link carries a token that's valid for 24 hours, so you can share the post with someone who isn't logged in. Lamb issues a fresh token whenever you save the post after the old one expires.

Lamb saves feed-ingested posts as drafts by default, to prioritize your own writing over syndication. To publish them instead, add `feeds_draft = false` to the site settings. Put it in the top-level section, above any `[section]` headers. Inside `[feeds]`, Lamb reads it as a feed entry rather than a setting.

## Related

- [Post types]({{ site.baseurl }}{% link post-types.md %}): Front matter sets `draft: true`.
- [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Hide a post until a future `created` date.
- [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Feed ingestion that produces drafts.
- [Micropub]({{ site.baseurl }}{% link micropub.md %}): Drafts created through Micropub get the same 24-hour preview link.
- [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): The cron endpoint triggers feed ingestion.
- [Export]({{ site.baseurl }}{% link export.md %}): Exports include drafts and flag them in the manifest.

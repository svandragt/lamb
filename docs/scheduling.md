---
title: Scheduling
---

# Scheduling

You can schedule a post to publish in the future by giving it a `created` date that is later than now. Until that date arrives the post is hidden from the homepage, feeds, tag pages, search, and its public URL. When the time passes it appears automatically — no cron job or extra step required.

## Scheduling a post

Add a `created` date to the front-matter:

```
---
title: Happy New Year
created: 2099-01-01 09:00:00
---

Wishing you all the best for the year ahead. #news
```

The date is interpreted in the server's timezone and uses the `YYYY-MM-DD HH:MM:SS` format. A date in the past publishes immediately (and back-dates the post); a date in the future schedules it.

## Viewing scheduled posts

When logged in and one or more posts are scheduled, a **Scheduled** link appears in the admin toolbar, listing future-dated posts soonest-first at `/scheduled`. You can still open a scheduled post directly via its `/status/<id>` URL to preview it before it goes live.

## Via Micropub

Micropub clients can schedule a post by sending a future `published` date. Sending `post-status: scheduled` is also accepted; the post stays hidden until its `published` date arrives and is never treated as a draft.

## Related

* [Post Types]({% link post-types.md %}): Front-matter is used to set the `created` date.
* [Drafts]({% link drafts.md %}): Drafts are hidden until published; scheduled posts are hidden until their date.
* [Micropub]({% link micropub.md %}): Scheduling posts from a Micropub client.

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

A date in the past publishes immediately (and back-dates the post); a date in the future schedules it.

### Accepted date formats

The `created` value is flexible. All of these work:

| Example | Result |
|---|---|
| `2099-01-01 09:00:00` | Exact date and time |
| `2099-01-01` | That date at midnight |
| `next friday 3pm` | The coming Friday at 15:00 |
| `+1 week` | One week from now |
| `tomorrow` | Tomorrow at midnight |
| `1 Jan 2099 18:30` | Named-month form |

The time you write is the time the post publishes — it is taken at face value and **not** shifted between timezones. If the value can't be understood as a date, the post simply publishes immediately.

Relative phrases are resolved **when you save**, then pinned: Lamb rewrites the front-matter to the absolute date it worked out (so `created: next friday` becomes e.g. `created: '2026-06-05 00:00:00'`). This means a later edit won't quietly move the date to the *next* Friday — what you scheduled stays scheduled.

## Timezone

Servers are usually set to UTC, which may not be your timezone. Set yours once in the site configuration at `/settings` so post dates, scheduling, and relative phrases like `next friday` all use your local clock:

```ini
timezone = Europe/London
```

Use a name from the [list of supported timezones](https://www.php.net/manual/en/timezones.php). It defaults to `UTC`.

## Viewing scheduled posts

When logged in and one or more posts are scheduled, a **Scheduled** link appears in the admin toolbar, listing future-dated posts soonest-first at `/scheduled`. You can still open a scheduled post directly via its `/status/<id>` URL to preview it before it goes live.

## Via Micropub

Micropub clients can schedule a post by sending a future `published` date. Sending `post-status: scheduled` is also accepted; the post stays hidden until its `published` date arrives and is never treated as a draft.

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): Set your `timezone` so scheduled posts go live at the right local time.
* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Front-matter is used to set the `created` date.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Drafts are hidden until published; scheduled posts are hidden until their date.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Scheduling posts from a Micropub client.

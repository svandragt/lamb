---
title: Scheduling
---

# Scheduling

To schedule a post to publish in the future, give it a `created` date later than now. Until that date arrives, Lamb hides the post from the homepage, feeds, tag pages, search, and its public URL. When the time passes, the post appears automatically. You don't need a cron job or any extra step.

## Schedule a post

Add a `created` date to the front matter:

```
---
title: Happy New Year
created: 2099-01-01 09:00:00
---

Wishing you all the best for the year ahead. #news
```

A date in the past publishes the post immediately and back-dates it. A date in the future schedules it.

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

Lamb publishes the post at the time you write. It takes the time at face value and doesn't shift it between timezones. If Lamb can't read the value as a date, it publishes the post immediately.

Lamb resolves relative phrases when you save, then pins them: it rewrites the front matter to the absolute date it worked out, so `created: next friday` becomes something like `created: '2026-06-05 00:00:00'`. A later edit therefore can't quietly move the date to the *next* Friday. What you scheduled stays scheduled.

## Timezone

Servers usually run on UTC, which may not be your timezone. Set yours once in the site configuration at `/settings`, so post dates, scheduling, and relative phrases such as `next friday` all use your local clock:

```ini
timezone = Europe/London
```

Use a name from the [list of supported timezones](https://www.php.net/manual/en/timezones.php). The default is `UTC`.

## View scheduled posts

When you're logged in and at least one post is scheduled, a **Scheduled** link appears in the admin toolbar. It lists future-dated posts soonest-first at `/scheduled`. You can also open a scheduled post directly at its `/status/<id>` URL to preview it before it goes live. Each scheduled post shows a **Preview** link next to its **Edit** button: a shareable URL with a token that expires after 24 hours and works without a login.

## Schedule through Micropub

Micropub clients can schedule a post by sending a future `published` date. Lamb also accepts `post-status: scheduled`. The post stays hidden until its `published` date arrives, and Lamb never treats it as a draft.

## Related

* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): Set your `timezone` so scheduled posts go live at the right local time.
* [Post types]({{ site.baseurl }}{% link post-types.md %}): Front matter sets the `created` date.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Lamb hides drafts until you publish them, and scheduled posts until their date.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Schedule posts from a Micropub client.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Lamb sends outbound webmentions for a scheduled post when it goes live, not when you save it.

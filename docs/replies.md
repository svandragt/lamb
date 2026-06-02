---
title: Reply posts
---

# Reply posts

A reply post links back to another page as its conversational parent. On [micro.blog](https://micro.blog) and across the IndieWeb this `in-reply-to` relationship lets your post be shown as a reply, and lets [webmentions]({{ site.baseurl }}{% link webmentions.md %}) be categorised correctly by the site you are replying to.

## Marking a post as a reply

Add an `in-reply-to` value to the post's YAML front matter:

```markdown
---
in-reply-to: https://example.com/their-post
---

Great point — here's my reply.
```

`in_reply_to` (underscore) is accepted as well. [Micropub]({{ site.baseurl }}{% link micropub.md %}) clients can send the standard `in-reply-to` property, which Lamb stores the same way.

Remove the value from the front matter and re-save to turn the post back into a normal post.

## What it does

- **On the post page** a small "In reply to …" line is shown above the content, linked to the parent and marked up with `u-in-reply-to` so Webmention receivers treat it as a reply.
- **Atom feed**: emits `<thr:in-reply-to ref="…" href="…" />` (the `http://purl.org/syndication/thread/1.0` thread extension).
- **JSON feed**: emits `_microblog.in_reply_to_url` (the micro.blog reply convention).

If you also have [webmention sending]({{ site.baseurl }}{% link webmentions.md %}) configured, replying to a page that links back is the most common kind of webmention — the link in your reply is picked up and the parent is notified on the next `/_cron` run.

## Related

* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Send and receive mentions; replies are the most common webmention type.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish replies from a Micropub client with the `in-reply-to` property.
* [Post types]({{ site.baseurl }}{% link post-types.md %}): Statuses, pages, and other post formats.

---
title: Reply posts
nav_order: 16
---

# Reply posts

A reply post links back to another page as its conversational parent. On [micro.blog](https://micro.blog) and across the IndieWeb, this `in-reply-to` relationship lets your post appear as a reply, and lets the site you're replying to categorise your [webmention]({{ site.baseurl }}{% link webmentions.md %}) correctly.

## Mark a post as a reply

Add an `in-reply-to` value to the post's YAML front matter:

```markdown
---
in-reply-to: https://example.com/their-post
---

Great point — here's my reply.
```

Lamb also accepts `in_reply_to` with an underscore. [Micropub]({{ site.baseurl }}{% link micropub.md %}) clients can send the standard `in-reply-to` property, which Lamb stores the same way.

To turn the post back into a normal post, remove the value from the front matter and save again.

## What a reply post does

- **On the post page**, Lamb shows a short "In reply to …" line above the content. The line links to the parent and carries the `u-in-reply-to` class, so webmention receivers treat the post as a reply.
- **In the Atom feed**, Lamb emits `<thr:in-reply-to ref="…" href="…" />` from the `http://purl.org/syndication/thread/1.0` thread extension.
- **In the JSON feed**, Lamb emits `_microblog.in_reply_to_url`, the micro.blog reply convention.

Replying to a page is the most common kind of [webmention]({{ site.baseurl }}{% link webmentions.md %}), and Lamb sends them automatically. There's nothing to configure: Lamb picks up the link in your reply and notifies the parent on the next `/_cron` run.

## Related

* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Send and receive mentions. Replies are the most common webmention type.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish replies from a Micropub client with the `in-reply-to` property.
* [Post types]({{ site.baseurl }}{% link post-types.md %}): Statuses, pages, and other post formats.

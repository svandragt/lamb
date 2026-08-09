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

`in_reply_to` (underscore) is accepted as well. [Micropub]({{ site.baseurl }}{% link micropub.md %}) clients can send the standard `in-reply-to` property — either a plain URL or an embedded `h-cite` object — which Lamb stores the same way. Only one reply target is kept: a list collapses to its first entry.

Remove the value from the front matter and re-save to turn the post back into a normal post.

## What it does

- **On the post page and in listings** a small "In reply to …" line is shown above the content, linked to the parent and marked up with `u-in-reply-to` so Webmention receivers treat it as a reply.
- **Atom feed**: emits `<thr:in-reply-to ref="…" href="…" />` (the `http://purl.org/syndication/thread/1.0` thread extension).
- **JSON feed**: emits `_microblog.in_reply_to_url` (the micro.blog reply convention).
- **Both feeds** also carry the same "In reply to …" line inside the item's HTML content, because the two metadata fields above are extensions most readers ignore, and services that thread replies look for the `u-in-reply-to` markup itself.

## Editing a reply over Micropub

A Micropub client sees the reply target in a `q=source` response as the `in-reply-to`
property, and can change it with an update:

- `replace` sets a new target (an empty value list clears it);
- `add` sets the target on a post that has none;
- `delete` removes it, either as a whole property or by naming the current value.

Because a post stores a single target, *adding* a second one is refused with
`invalid_request` rather than silently overwriting or dropping it.

Replying to a page is the most common kind of [webmention]({{ site.baseurl }}{% link webmentions.md %}), and Lamb sends them automatically — there is nothing to configure. The link in your reply is picked up and the parent is notified on the next `/_cron` run.

## Related

* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Send and receive mentions; replies are the most common webmention type.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish replies from a Micropub client with the `in-reply-to` property.
* [Post types]({{ site.baseurl }}{% link post-types.md %}): Statuses, pages, and other post formats.

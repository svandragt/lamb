---
title: RSVP posts
parent: Content
---

# RSVP posts

An [RSVP](https://indieweb.org/rsvp) is a [reply]({{ site.baseurl }}{% link replies.md %}) to an event page that also says whether you are going. It is an ordinary post with one extra piece of metadata, published to your own site and delivered to the event with a [webmention]({{ site.baseurl }}{% link webmentions.md %}) — so the event's attendee list can pick it up without you signing in anywhere.

## Marking a post as an RSVP

Add `rsvp` to the post's YAML front matter, alongside the `in-reply-to` that points at the event:

```markdown
---
in-reply-to: https://example.com/the-event
rsvp: yes
---

Looking forward to it!
```

The four replies microformats2 defines are the ones Lamb accepts:

| Value | Means |
|-------|-------|
| `yes` | You are going |
| `no` | You are not going |
| `maybe` | You might go |
| `interested` | You would like to go, but are not committing |

Case does not matter (`Yes` and `YES` both work), and `rsvp: true` / `rsvp: false` are read as `yes` / `no`. Anything else is ignored — the post saves as a normal post rather than as an RSVP claiming a reply nobody can read.

`rsvp` works on its own, but an RSVP without an `in-reply-to` has nothing to attend: without the event URL there is no webmention to send, and no event for a reader to follow. Set both.

Remove the `rsvp` line and re-save to turn the post back into a plain reply.

## Posting one from the editor

Front matter is typed straight into the post box — the block above is the whole mechanism, and it works in both the quick-post box and the edit form.

To skip the typing, the entry form can be pre-filled from the query string. `?rsvp=` and `?in-reply-to=` build the front-matter block for you, and `?text=` fills in the body:

```
https://yoursite.example/?in-reply-to=https%3A%2F%2Fexample.com%2Fthe-event&rsvp=yes
```

Nothing is published by opening that URL: the post box opens with the block already written, and you still review it and press the publish button.

That makes a one-click RSVP bookmarklet — save this as a browser bookmark, then click it while you are on an event page:

```javascript
javascript:location.href='https://yoursite.example/?rsvp=yes&in-reply-to='+encodeURIComponent(location.href)
```

Make one per reply you use (`rsvp=yes`, `rsvp=maybe`, …), replacing `yoursite.example` with your own domain. A target that is not an `http(s)` URL, or a reply outside the four values above, is left out of the pre-filled block.

## What it does

- **On the post page and in listings** the reply line reads "RSVP yes to example.com", linking to the event. The reply is marked up as the microformats2 `p-rsvp` property, so a parser reads the bare `yes`/`no`/`maybe`/`interested` value whatever the line says in words.
- **Webmention**: because an RSVP is a reply, the event is notified on the next [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run, exactly as a reply is. An event that shows an attendee list reads the `p-rsvp` out of your post when it verifies the mention.
- **Feeds**: the same line is carried inside the Atom and JSON feed item content, so readers see the RSVP too.

## Over Micropub

[Micropub]({{ site.baseurl }}{% link micropub.md %}) clients send the standard `rsvp` property, which Lamb stores the same way; a `q=source` response reports it back. An update can `replace` the value with another reply, or `delete` the property to turn the post back into a plain reply. As with the other single-valued properties, `add` is refused — there is nothing to append to — and a value outside the four above is refused with `invalid_request` rather than saved and silently dropped later.

## Related

* [Reply posts]({{ site.baseurl }}{% link replies.md %}): The `in-reply-to` an RSVP is built on.
* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): How the event is notified of your RSVP.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish an RSVP from a Micropub client.
* [Post types]({{ site.baseurl }}{% link post-types.md %}): Statuses, pages, and other post formats.

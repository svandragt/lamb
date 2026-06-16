---
title: Webmentions
---

# Webmentions

[Webmention](https://www.w3.org/TR/webmention/) is an open standard that lets one site notify another when it links to it. When someone replies to, likes, or links one of your posts from their own Webmention-enabled site, they can send your blog a notification so you know about the mention.

Lamb can **receive** webmentions, and **send** them when you publish a post that links to other sites.

## How it works

Lamb exposes a `/webmention` endpoint. Other sites discover it two ways:

- An HTTP `Link: <…/webmention>; rel="webmention"` header on every page.
- A `<link rel="webmention" href="…/webmention">` tag in your page `<head>`.

When a sender POSTs a `source` (their page) and `target` (your post URL) to the endpoint, Lamb:

1. Checks that `target` is a real post on your site.
2. Fetches the `source` page and verifies it actually links to `target`.
3. Stores the verified mention against the post.

Senders that don't follow the rules are rejected: a missing or non-`http(s)` `source`/`target`, a `target` that isn't one of your posts, or a `source` that doesn't link back all return `400`. If a previously received `source` is re-checked and no longer links to the `target`, the stored mention is removed.

## Seeing your mentions

Received webmentions appear at the bottom of the relevant post page, visible to the **logged-in author only**. Lamb treats them as a private notification for you rather than public comments, so they are not displayed to visitors. Each entry links to the source page, with the author and a short snippet where they can be detected.

## Sending

When you publish or edit a post, Lamb scans its rendered HTML for links to **other** sites and queues a webmention for each. The queue is worked through on the next [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run rather than during the publish request, so saving a post stays fast. For each queued target Lamb:

1. Fetches the target page and discovers its webmention endpoint (HTTP `Link` header → `<link rel="webmention">` → `<a rel="webmention">`).
2. POSTs your post URL (`source`) and the linked URL (`target`) to that endpoint.

Each source/target pair is sent once — re-editing a post does not re-notify targets it already reached, so receivers are not spammed. Drafts and posts ingested from other feeds do not send webmentions.

[Scheduled posts]({{ site.baseurl }}{% link scheduling.md %}) are queued when you save them, but nothing is sent until the publication time has passed — the first `/_cron` run after the post goes live delivers its webmentions, once the post is publicly reachable for receivers to verify. Editing a scheduled post before it publishes updates the queue (removed links are dropped), and deleting it cancels the queued mentions entirely.

### Deleting a post that sent webmentions

When you delete a post that already sent webmentions, Lamb re-sends them on the next `/_cron` run. The receiver re-fetches your post URL, finds it gone, and removes the mention it was displaying — so a deleted reply or like stops showing on the other site, as the [Webmention spec recommends](https://www.w3.org/TR/webmention/#sending-webmentions-for-deleted-posts). If you [restore]({{ site.baseurl }}{% link trash.md %}) the post from Trash before that cron run, the re-sends are abandoned and nothing is notified.

## Related

* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Mark a post as `in-reply-to` another URL — the most common webmention type.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts from any Micropub client; uses the same IndieWeb discovery pattern.
* [Cron / scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): How `/_cron` drives feed ingestion and outbound webmentions.
* [Scheduling posts]({{ site.baseurl }}{% link scheduling.md %}): Scheduled posts send their webmentions when they go live.
* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Pull posts in from other feeds.
* [Theme functions]({{ site.baseurl }}{% link theme-functions.md %}): The microformats2 (h-entry / h-card) markup the themes emit, which Webmention receivers parse.

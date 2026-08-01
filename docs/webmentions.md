---
title: Webmentions
nav_order: 18
---

# Webmentions

[Webmention](https://www.w3.org/TR/webmention/) is an open standard that lets one site notify another when it links to it. When someone replies to, likes, or links one of your posts from their own webmention-enabled site, they can send your blog a notification so you know about the mention.

Lamb can **receive** webmentions, and **send** them when you publish a post that links to other sites.

## How webmentions work

Lamb exposes a `/webmention` endpoint. Other sites discover it two ways:

- An HTTP `Link: <…/webmention>; rel="webmention"` header on every page.
- A `<link rel="webmention" href="…/webmention">` tag in your page `<head>`.

When a sender POSTs a `source` (their page) and `target` (your post URL) to the endpoint, Lamb:

1. Checks that `target` is a real post on your site.
2. Fetches the `source` page and verifies that it actually links to `target`.
3. Stores the verified mention against the post.

Lamb rejects senders that don't follow the rules. A missing or non-`http(s)` `source` or `target`, a `target` that isn't one of your posts, and a `source` that doesn't link back all return `400`. If Lamb re-checks a `source` it received earlier and that page no longer links to the `target`, it removes the stored mention.

## See your mentions

Received webmentions appear at the bottom of the relevant post page, visible to the **logged-in author only**. Lamb treats them as a private notification for you rather than as public comments, so visitors don't see them. Each entry links to the source page, and shows the author and a short snippet where Lamb can detect them.

## Send webmentions

When you publish or edit a post, Lamb scans its rendered HTML for links to **other** sites and queues a webmention for each one. Lamb works through the queue on the next [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run rather than during the publish request, so saving a post stays fast. For each queued target, Lamb:

1. Fetches the target page and discovers its webmention endpoint, in this order: the HTTP `Link` header, then `<link rel="webmention">`, then `<a rel="webmention">`.
2. POSTs your post URL (`source`) and the linked URL (`target`) to that endpoint.

Lamb sends each source and target pair once, so re-editing a post doesn't re-notify targets it already reached and receivers aren't spammed. Drafts and posts ingested from other feeds don't send webmentions.

Lamb queues [scheduled posts]({{ site.baseurl }}{% link scheduling.md %}) when you save them, but sends nothing until the publication time passes. The first `/_cron` run after the post goes live delivers its webmentions, once receivers can reach the post to verify it. Editing a scheduled post before it publishes updates the queue and drops any removed links, and deleting the post cancels the queued mentions entirely.

### Delete a post that sent webmentions

When you delete a post that already sent webmentions, Lamb re-sends them on the next `/_cron` run. The receiver re-fetches your post URL, finds it gone, and removes the mention it was displaying, so a deleted reply or like stops showing on the other site. This is what the [Webmention spec recommends](https://www.w3.org/TR/webmention/#sending-webmentions-for-deleted-posts). If you [restore]({{ site.baseurl }}{% link trash.md %}) the post from the trash before that cron run, Lamb abandons the re-sends and notifies nobody.

## Related

* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Mark a post as `in-reply-to` another URL, the most common webmention type.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts from any Micropub client. It uses the same IndieWeb discovery pattern.
* [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): How `/_cron` drives feed ingestion and outbound webmentions.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Scheduled posts send their webmentions when they go live.
* [Cross-posting]({{ site.baseurl }}{% link cross-posting.md %}): Pull posts in from other feeds.
* [Theme functions]({{ site.baseurl }}{% link theme-functions.md %}): The microformats2 (h-entry and h-card) markup the themes emit, which webmention receivers parse.

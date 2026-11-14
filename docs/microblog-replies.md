---
title: Micro.blog replies
parent: Sharing & discovery
---

# Micro.blog replies

If your blog's feed is followed on [Micro.blog](https://micro.blog), people can reply to your posts there. Lamb can fetch those replies and show them under each post, for every visitor.

Micro.blog offers a `conversation.js` script for this, but Lamb does not use it. That script writes the replies into the page while it loads, so a slow or unavailable micro.blog would hold up your page, and every visitor's browser would contact micro.blog. Instead Lamb fetches the replies itself and serves them from your own site.

## Turning it on

1. Add your blog's feed to your Micro.blog account, so Micro.blog knows your posts.
2. Set **Micro.blog username** at `/settings` (under *Identity links*), or add it to your settings:

   ```ini
   microblog_username = yourname
   ```

3. Make sure [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) runs regularly, and that `site_url` is set, so the post addresses Lamb asks about match the ones Micro.blog knows.

Leave the username empty to turn it off.

## How it works

On each `/_cron` run, Lamb asks Micro.blog for the replies to a few of your published posts from the last 30 days, using the JSON form of the conversation (`conversation.js?url=…&format=jf2`). Each post is checked at most once an hour, so a new reply appears within about an hour of being posted.

Replies are stored in Lamb's database and shown under the post with the author's name, the reply text and a link to the original reply. A **Reply on Micro.blog** link to your profile invites readers to join in. Replies are shown as plain text; no HTML or scripts from Micro.blog reach your pages, and author avatars are not shown, so visitors' browsers never contact micro.blog.

A reply deleted on Micro.blog disappears from your post on the next check.

## When Micro.blog is down

Nothing on your pages changes. If a check fails (micro.blog unreachable, an error page, or something that isn't the expected JSON), Lamb keeps the replies it already has and tries that post again an hour later. The `/_cron` output reports how many checks failed.

## Notes

* Replies are a cache of what Micro.blog holds: they are not included in [exports]({{ site.baseurl }}{% link export.md %}), and are fetched again after a restore.
* Posts older than 30 days keep the replies they have but are no longer checked for new ones.

## Related

* [Webmentions]({{ site.baseurl }}{% link webmentions.md %}): Mentions sent straight to your site; shown to you only, not to visitors.
* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Reply to other people's posts from your own blog.
* [Cron / scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}): The `/_cron` run that fetches replies.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): Where `microblog_username` is set.

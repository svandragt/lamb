---
title: Write your first post
parent: Content
nav_order: 1
---

# Write your first post

This tutorial walks you through your first ten minutes with Lamb: logging in, publishing a status, adding a hashtag and an image, turning a post into a page, and saving a draft. Follow it start to finish and you will have used every part of the writing flow once.

It assumes Lamb is already installed and running. If it isn't, start with [Installation & hosting]({{ site.baseurl }}{% link installation.md %}).

## Log in

Go to `/login` and enter your password. Lamb has a single author — you — so there is no username. Once you are in, the homepage gains a text box at the top. Everything below happens from there.

## Publish a status

Type a sentence into the box and press **Publish**. That's a status post, and it's already live at its own address, `/status/<number>`.

The box takes Markdown, so `**bold**`, `_italic_`, and `- ` lists all work. You don't need any of it for a status — plain text is a complete post — but it's there when you want it.

## Add a hashtag

Write a word with a `#` in front of it, like `#coffee`, anywhere in a post. Lamb turns it into a link automatically. Publish the post, then select the hashtag: it takes you to `/tag/coffee`, an archive of every post with that tag. Tags are lower-cased in the link, so `#Coffee` and `#coffee` land on the same archive.

You don't create tags anywhere — writing one is enough.

## Drag in an image

Drag an image file from your desktop onto the text box. Lamb uploads it and drops a Markdown image link at your cursor, so you can keep typing around it. Pasting an image from the clipboard — a screenshot, say — does the same thing.

JPEG and PNG files are re-encoded to WebP on upload to keep them small; the link points at the converted file. GIF, WebP, and AVIF are kept as they are. For the full picture, including video and size limits, see [Media]({{ site.baseurl }}{% link media.md %}).

## Turn a post into a page

So far every post has been a status with an automatic `/status/<number>` address. To make a lasting page instead — an about page, say — give it a title in front matter at the very top of the box:

```markdown
---
title: About me
---

I write about coffee and code.
```

Publish it, and the post becomes a page with a readable address built from its title, like `/about-me`, instead of a numbered status. Front matter is metadata: the `title` block itself isn't shown in the post body. [Post Types]({{ site.baseurl }}{% link post-types.md %}) covers the difference in full.

## Save a draft

To hold a post back instead of publishing it, add `draft: true` to the front matter:

```markdown
---
draft: true
---

Still thinking about this one.
```

The post is saved but not shown on your blog. Every draft is listed at `/drafts` while you are logged in, each with an **Edit** link to finish it and a **Preview** link you can share with someone who isn't logged in. See [Drafts]({{ site.baseurl }}{% link drafts.md %}) for how previews and their expiring links work.

## Where to go next

You have now published a status, tagged it, added an image, made a page, and saved a draft. Each of those has a reference page with the details this tutorial skipped:

- [Post Types]({{ site.baseurl }}{% link post-types.md %}) — statuses, pages, task lists, and front matter.
- [Media]({{ site.baseurl }}{% link media.md %}) — images and video, formats, and upload limits.
- [Drafts]({{ site.baseurl }}{% link drafts.md %}) — drafts, previews, and shareable links.
- [Scheduling]({{ site.baseurl }}{% link scheduling.md %}) — publish a post at a future date.
- [Reply posts]({{ site.baseurl }}{% link replies.md %}) — reply to another page and join the conversation.

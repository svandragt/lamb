---
title: Post Types
---

There are two kinds of posts:

## Status

The default, add some words into the textarea and press the publish button. Any markdown is valid.

Any tags are automatically linked to tag archives.

Select some text and paste a link over it to turn the selection into a markdown link, e.g. selecting `Lamb` and pasting `https://example.com` produces `[Lamb](https://example.com)`.

Add images by dragging files onto the editor, or by pasting an image straight from the clipboard (for example a screenshot). Either way the image is uploaded and a markdown image link is inserted at the cursor. JPEG and PNG uploads are automatically converted to WebP to keep files small; GIF, WebP, and AVIF are stored as-is. Video files (`mp4`, `webm`, `mov`) can be dragged onto the editor the same way and are embedded as a playable video.

Permalinks for statuses are in the form of `/status/<integer>`.

```markdown
This is a status post #hello
```

### Task lists

Write GitHub-style task lists with `- [ ]` for an open item and `- [x]` for a done one:

```markdown
- [ ] buy milk
- [x] walk the dog
```

These render as real checkboxes. When you are logged in the checkboxes are interactive: tick or untick one straight on the page and Lamb saves it as an edit, rewriting the `[ ]`/`[x]` in the post source for you. Visitors see the checkboxes as read-only.

## Page

This is a status plus YAML-parsed front-matter, this is metadata and will not be rendered.

```markdown
---
title: About me
---

Hi I'm John Sheeple and the example author of this site.
```

You don't have to write front-matter by hand: if a post has no `title:` but its body opens with a top-level Markdown heading, Lamb treats that heading as the title. Writing

```markdown
# About me

Hi I'm John Sheeple and the example author of this site.
```

is the same as the front-matter version above — Lamb moves the heading into a `title:` for you, so the title isn't also repeated as a heading inside the post. Any leading heading level works (`#` through `######`); the first heading is the title whatever level you typed. A heading that isn't the very first line is left in place as a section.

Slugs for pages are derived from the title on creation unless you explicitly provide `slug:` in the front-matter. The slug for the example above is `about-me` and the permalink is `/about-me`.

Headings inside a post body are levelled to fit beneath the post title automatically, so the page outline stays in order (the post title is a heading, and your body headings sit one level below it).

Editing the `slug:` line (or the title, when no explicit slug is set) reslugs the post, and Lamb automatically stores a 301 redirect from the old slug — _good URLs don't change_, so bookmarks and inbound links keep working. See [Redirections]({{ site.baseurl }}{% link redirections.md %}).

Slugs are unique. If a slug is already taken by another post (or matches a built-in route like `/search`), Lamb appends the post's id to keep the URL distinct, and writes the final slug back into the post's front-matter so you can see — and edit — the slug the post is actually served under.

You can also set a `created:` date in the front-matter. A future date schedules the post — see [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).

By default Lamb derives a post's description from its first line. Set a `summary:` in the front-matter to write that description yourself — it is used for the post's [social-embed]({{ site.baseurl }}{% link social-embeds.md %}) description (the OpenGraph/Twitter `description` tag) and its feed summary. `description:` works as an alias.

```markdown
---
title: My weekend project
summary: A short, hand-written description for search engines and social cards.
---

The full post body goes here.
```

Front-matter keys are forgiving: they are matched case-insensitively, and underscores and dashes are interchangeable. So `Title`, `title`, `in_reply_to` and `in-reply-to` all work — handy on mobile keyboards that auto-capitalise the first letter of a line.

> **iOS note:** iOS "Smart Punctuation" rewrites a typed `---` into em/en dashes (for example `—-`). Lamb recognises a mangled opening and closing fence and restores it to `---` automatically — whether you add the front-matter when first writing the post or by editing it later — so front-matter still works from an iPhone or iPad. If you'd rather type plain dashes everywhere, turn the feature off under _Settings → General → Keyboard → Smart Punctuation_.

# System types

The following sections of the site are special:

- `/tags/<name>` are tags linked in content
- `/search/<keywords>` search the content for keywords
- `/login` and `/logout` to login and out.
- `/feed` for the Atom newsfeed.

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Add images and video by drag-and-drop or paste; JPEG/PNG are converted to WebP, video is stored as-is.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Add `draft: true` to front-matter to save a post as a draft.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Add a future `created:` date to publish a post later.
* [Social Embeds]({{ site.baseurl }}{% link social-embeds.md %}): A `summary:` in front-matter sets the description used in social preview cards.
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %}): Page posts with slugs can be pinned as menu items.
* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Add `in-reply-to:` to front-matter to mark a post as a reply to another URL.
* [Syntax Highlighting]({{ site.baseurl }}{% link syntax-highlighting.md %}): Fenced code blocks with a language hint are highlighted server-side.

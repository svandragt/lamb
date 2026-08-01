---
title: Post types
---

There are two kinds of posts.

## Status

The default. Add some words to the textarea and click the publish button. Any Markdown is valid.

Lamb links any tags to tag archives automatically.

To turn a text selection into a Markdown link, select the text and paste a link over it. For example, selecting `Lamb` and pasting `https://example.com` produces `[Lamb](https://example.com)`.

To add images, drag files onto the editor, or paste an image straight from the clipboard, such as a screenshot. Either way, Lamb uploads the image and inserts a Markdown image link at the cursor. Lamb converts JPEG and PNG uploads to WebP to keep files small, and stores GIF, WebP, and AVIF unchanged. You can drag video files (`mp4`, `webm`, `mov`) onto the editor the same way, and Lamb embeds them as a playable video.

Permalinks for statuses take the form `/status/<integer>`.

```markdown
This is a status post #hello
```

### Task lists

Write GitHub-style task lists with `- [ ]` for an open item and `- [x]` for a done one:

```markdown
- [ ] buy milk
- [x] walk the dog
```

These render as real checkboxes. When you're logged in, the checkboxes are interactive: tick or untick one straight on the page and Lamb saves it as an edit, rewriting the `[ ]` or `[x]` in the post source for you. Visitors see the checkboxes as read-only.

## Page

A page is a status plus YAML-parsed front matter. The front matter is metadata, and Lamb doesn't render it.

```markdown
---
title: About me
---

Hi I'm John Sheeple and the example author of this site.
```

You don't have to write front matter by hand. If a post has no `title:` but its body opens with a top-level Markdown heading, Lamb treats that heading as the title. So writing

```markdown
# About me

Hi I'm John Sheeple and the example author of this site.
```

is the same as the front-matter version above. Lamb moves the heading into a `title:` for you, so the title isn't also repeated as a heading inside the post. Any leading heading level works, from `#` through `######`, and the first heading is the title whatever level you typed. Lamb leaves a heading that isn't the very first line in place as a section.

Lamb derives slugs for pages from the title on creation, unless you provide `slug:` in the front matter. The slug for the preceding example is `about-me`, and the permalink is `/about-me`.

Lamb levels headings inside a post body to fit beneath the post title, so the page outline stays in order. The post title is a heading, and your body headings sit one level below it.

Editing the `slug:` line, or the title when you haven't set an explicit slug, reslugs the post. Lamb then stores a 301 redirect from the old slug, because _good URLs don't change_, so bookmarks and inbound links keep working. See [Redirections]({{ site.baseurl }}{% link redirections.md %}).

Slugs are unique. If another post already uses a slug, or the slug matches a built-in route such as `/search`, Lamb appends the post's id to keep the URL distinct. It writes the final slug back into the post's front matter, so you can see and edit the slug the post is actually served under.

You can also set a `created:` date in the front matter. A future date schedules the post. See [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).

By default, Lamb derives a post's description from its first line. To write that description yourself, set a `summary:` in the front matter. Lamb uses it for the post's [social-embed]({{ site.baseurl }}{% link social-embeds.md %}) description, which is the OpenGraph and Twitter `description` tag, and for the feed summary. `description:` works as an alias.

```markdown
---
title: My weekend project
summary: A short, hand-written description for search engines and social cards.
---

The full post body goes here.
```

Front-matter keys are forgiving. Lamb matches them case-insensitively, and treats underscores and dashes as interchangeable, so `Title`, `title`, `in_reply_to`, and `in-reply-to` all work. This helps on mobile keyboards that auto-capitalise the first letter of a line.

> **iOS note:** iOS Smart Punctuation rewrites a typed `---` into em dashes or en dashes, for example `—-`. Lamb recognises a mangled opening and closing fence and restores it to `---` automatically, whether you add the front matter when first writing the post or when editing it later, so front matter still works from an iPhone or iPad. To type plain dashes everywhere instead, turn the feature off under _Settings → General → Keyboard → Smart Punctuation_.

# System types

The following sections of the site are special:

- `/tags/<name>`: tags linked in content.
- `/search/<keywords>`: search the content for keywords.
- `/login` and `/logout`: log in and out.
- `/feed`: the Atom newsfeed.

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Add images and video by drag-and-drop or paste. Lamb converts JPEG and PNG to WebP, and stores video unchanged.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Add `draft: true` to front matter to save a post as a draft.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Add a future `created:` date to publish a post later.
* [Social embeds]({{ site.baseurl }}{% link social-embeds.md %}): A `summary:` in front matter sets the description used in social preview cards.
* [Menu items]({{ site.baseurl }}{% link menu-items.md %}): You can pin page posts with slugs as menu items.
* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Add `in-reply-to:` to front matter to mark a post as a reply to another URL.
* [Syntax highlighting]({{ site.baseurl }}{% link syntax-highlighting.md %}): Lamb highlights fenced code blocks with a language hint on the server.

---
title: Post Types
---

There are two kinds of posts:

## Status

The default, add some words into the textarea and press the publish button. Any markdown is valid.

Any tags are automatically linked to tag archives.

Select some text and paste a link over it to turn the selection into a markdown link, e.g. selecting `Lamb` and pasting `https://example.com` produces `[Lamb](https://example.com)`.

Add images by dragging files onto the editor, or by pasting an image straight from the clipboard (for example a screenshot). Either way the image is uploaded and a markdown image link is inserted at the cursor. JPEG and PNG uploads are automatically converted to WebP to keep files small; GIF, WebP, and AVIF are stored as-is.

Permalinks for statuses are in the form of `/status/<integer>`.

```markdown
This is a status post #hello
```

## Page

This is a status plus YAML-parsed front-matter, this is metadata and will not be rendered.

```markdown
---
title: About me
---

Hi I'm John Sheeple and the example author of this site.
```

Slugs for pages are derived from the title on creation unless you explicitly provide `slug:` in the front-matter. The slug for the example above is `about-me` and the permalink is `/about-me`.

A slug is preserved after creation. Changing the title later does not automatically reslug the post. _Good URLs don't change_, so although it's possible to set a slug in the front-matter when creating a page, Lamb will derive it from the title if it isn't set.

Slugs are unique. If a slug is already taken by another post (or matches a built-in route like `/search`), Lamb appends the post's id to keep the URL distinct, and writes the final slug back into the post's front-matter so you can see — and edit — the slug the post is actually served under.

You can also set a `created:` date in the front-matter. A future date schedules the post — see [Scheduling]({{ site.baseurl }}{% link scheduling.md %}).

> **iOS note:** iOS "Smart Punctuation" rewrites a typed `---` into em/en dashes (for example `—-`). Lamb recognises a mangled opening and closing fence and restores it to `---` automatically, so front-matter still works from an iPhone or iPad. If you'd rather type plain dashes everywhere, turn the feature off under _Settings → General → Keyboard → Smart Punctuation_.

# System types

The following sections of the site are special:

- `/tags/<name>` are tags linked in content
- `/search/<keywords>` search the content for keywords
- `/login` and `/logout` to login and out.
- `/feed` for the Atom newsfeed.

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Add images by drag-and-drop or paste; JPEG/PNG are converted to WebP.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): Add `draft: true` to front-matter to save a post as a draft.
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): Add a future `created:` date to publish a post later.
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %}): Page posts with slugs can be pinned as menu items.
* [Reply posts]({{ site.baseurl }}{% link replies.md %}): Add `in-reply-to:` to front-matter to mark a post as a reply to another URL.
* [Syntax Highlighting]({{ site.baseurl }}{% link syntax-highlighting.md %}): Fenced code blocks with a language hint are highlighted server-side.

---
title: Post Types
---

There are two kinds of posts:

## Status

The default, add some words into the textarea and press the publish button. Any markdown is valid.

Any tags are automatically linked to tag archives.

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

# System types

The following sections of the site are special:

- `/tags/<name>` are tags linked in content
- `/search/<keywords>` search the content for keywords
- `/login` and `/logout` to login and out.
- `/feed` for the Atom newsfeed.

## Related

* [Drafts]({% link drafts.md %}): Add `draft: true` to front-matter to save a post as a draft.
* [Menu Items]({% link menu-items.md %}): Page posts with slugs can be pinned as menu items.

# Post Types

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

Links for statuses are derived from the title on creation. The permalink for the example below is `/about-me`.

```markdown
---
title: About me
---

Hi I'm John Sheeple and the example author of this site.
```

# System types

The following sections of the site are special:

- `/tags/<name>` are tags linked in content
- `/search/<keywords>` search the content for keywords
- `/login` and `/logout` to login and out.
- `/feed` for the Atom newsfeed.

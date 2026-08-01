---
title: Search
---

# Search

Lamb includes a full-text search that queries post bodies and titles.

## Search your posts

Go to `/search/<keywords>`, or use the search form if your theme provides one. Search accepts multiple keywords and ignores case.

For example: `/search/hello+world`

You can also pass keywords in the `s` query parameter. Lamb redirects to the canonical URL form:

`/search?s=hello+world` → `/search/hello+world`

## Search results

Lamb displays matching posts in reverse-chronological order. The results page heading shows the search query and the number of matches.

Lamb highlights keywords in the results with `<mark>` tags, which the active theme styles.

## Related

* [Post types]({{ site.baseurl }}{% link post-types.md %}): The content that Lamb searches.

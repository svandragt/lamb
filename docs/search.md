---
title: Search
---

# Search

Lamb has a built-in full-text search that queries post bodies and titles.

## How to search

Navigate to `/search/<keywords>` or use the search form if your theme provides one. Multiple keywords are supported and the search is case-insensitive.

Example: `/search/hello+world`

You can also pass keywords via the `s` query parameter, which will redirect to the canonical URL form:

`/search?s=hello+world` → `/search/hello+world`

## Search results

Matching posts are displayed in reverse-chronological order. The results page includes a heading showing the search query and the number of matches found.

Keywords are highlighted in the search results using `<mark>` tags (styled by the active theme).

## Related

* [Post Types]({% link post-types.md %}): The content that is searched.

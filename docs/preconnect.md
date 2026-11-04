---
title: Preconnect
parent: Site customisation
---

# Preconnect

Lamb adds preconnect hints to your pages itself. You don't need to configure anything.

When a post shows an image or video from another website, the browser has to open a connection to that site before it can load the file. A preconnect hint tells the browser to open the connection early, while it is still reading the page, so the file arrives sooner. Lamb also adds a [`dns-prefetch`](https://developer.mozilla.org/en-US/docs/Web/Performance/dns-prefetch) hint for browsers that do not support preconnect.

Lamb looks at the posts on the page it is showing, and adds the hints for the websites those posts load images and video from. This works for addresses that start with `http://` or `https://`. Lamb skips addresses that start with `//`, such as `//images.example.com/photo.jpg`. A page of posts, a tag page, a search page and a single post all work this way. Lamb leaves out your own site and any image or video stored on it.

Lamb adds at most three sites per page, in the order they first appear. Each hint opens a connection, and more than a few connections compete with the page's own files for bandwidth.

For a post with an image on `https://images.example.com`, the page's `<head>` contains:

```
<link rel="preconnect" href="https://images.example.com">
<link rel="dns-prefetch" href="https://images.example.com">
```

## The `[preconnect]` setting is deprecated

Earlier versions listed the sites to connect to by hand in a section called `preconnect` on the settings page. That setting is deprecated, and Lamb removes it in 0.17. Until then, Lamb still adds the sites you listed, before the ones it finds itself, and the three-site limit covers both.

Lamb only finds sites that posts load images and video from. A hint for any other site you listed, such as a font provider, stops when 0.17 removes the setting.

## Related

* [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %}): More information on the settings page.

---
title: Social Embeds
---

# Social Embeds

When a post is shared on social media or chat apps, those services render a
preview card from the [OpenGraph](https://ogp.me/) and Twitter Card `<meta>`
tags in the post's HTML `<head>`. Lamb emits these automatically for individual
posts (the `/status/<id>` and slugged-post pages).

## How the card image is chosen

Lamb picks the embed image (`og:image` / `twitter:image`) in this order, most
specific first:

1. **The first image in the post.** If the post body embeds an image, that image
   becomes the card image and the Twitter card is upgraded to
   `summary_large_image` (a large, image-led preview). So sharing a photo or
   screenshot post previews *that* image.
2. **A site-wide default you provide.** If the post has no image, Lamb looks for
   an `og-image.*` file in the web root (next to `index.php`) and uses it.
3. **The built-in Lamb card.** If you haven't provided one, the shipped
   `og-image-lamb.webp` is used.

## Setting a site-wide card

Drop an image named `og-image` into the `src/` directory (the web root), using
any of these extensions:

`png`, `jpg`, `jpeg`, `webp`, `gif`

For example, `src/og-image.png`. No configuration is needed — the file is picked
up by convention, exactly like the [feed icon and logo]({{ site.baseurl }}{% link feeds.md %}#feed-icon-and-logo).
A 1200×630 image is the conventional size for a social card.

If your blog is mostly short text posts without images, setting a site-wide
card is worthwhile: it gives every shared link a consistent, branded preview —
your *blog's* identity — rather than the generic built-in card. Image posts
still preview their own first image automatically.

Lamb reads the image's real dimensions and type for the `og:image:width`,
`og:image:height`, and `og:image:type` tags when the file is readable, so you
don't need to declare them anywhere.

## Related

* [Media]({{ site.baseurl }}{% link media.md %}) — uploading images into posts (these become the card image automatically)
* [Feeds]({{ site.baseurl }}{% link feeds.md %}) — the related `favicon.png` / `logo.png` web-root convention for feed readers

---
title: Social embeds
nav_order: 21
---

# Social embeds

When someone shares a post on social media or in a chat app, those services
render a preview card from the [OpenGraph](https://ogp.me/) and Twitter Card
`<meta>` tags in the post's HTML `<head>`. Lamb emits these automatically for
individual posts: the `/status/<id>` and slugged-post pages.

## How Lamb chooses the card image

Lamb picks the embed image (`og:image` and `twitter:image`) in this order, most
specific first:

1. **The first image in the post.** If the post body embeds an image, that image
   becomes the card image and Lamb upgrades the Twitter card to
   `summary_large_image`, a large image-led preview. Sharing a photo or
   screenshot post therefore previews *that* image. Lamb doesn't consider video
   here, because it doesn't extract a poster frame, so a post whose only media
   is a video falls through to the next rule.
2. **A site-wide default you provide.** If the post has no image, Lamb looks for
   an `og-image.*` file in the web root, next to `index.php`, and uses it.
3. **The built-in Lamb card.** If you haven't provided one, Lamb uses the
   shipped `og-image-lamb.webp`.

## Set a site-wide card

Put an image named `og-image` in the `src/` directory, which is the web root,
using any of these extensions:

`png`, `jpg`, `jpeg`, `webp`, `gif`

For example, `src/og-image.png`. No configuration is needed: Lamb picks the file
up by convention, exactly like the
[feed icon and logo]({{ site.baseurl }}{% link feeds.md %}#feed-icon-and-logo).
A 1200×630 image is the conventional size for a social card.

If your blog is mostly short text posts without images, a site-wide card is
worthwhile. It gives every shared link a consistent, branded preview of your
*blog's* identity rather than the generic built-in card. Image posts still
preview their own first image automatically.

Lamb reads the image's real dimensions and type for the `og:image:width`,
`og:image:height`, and `og:image:type` tags when it can read the file, so you
don't need to declare them anywhere.

## The card description

The card's description text (`og:description` and `twitter:description`) comes
from the post's description. By default, Lamb uses the post's first line. To
write the description yourself, set a `summary:` in the post's front matter. See
[Post types]({{ site.baseurl }}{% link post-types.md %}).

## Related

* [Media]({{ site.baseurl }}{% link media.md %}): Uploading images into posts. These become the card image automatically.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The related `favicon.png` and `logo.png` web-root convention for feed readers.

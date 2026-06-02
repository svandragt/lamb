---
title: Media
---

# Media

Lamb lets you add images to posts without leaving the editor. Uploaded files are stored under `src/assets/YYYY/MM/` and served from your own site — there is no external image host.

## Adding images

When logged in, there are two ways to add an image to the post editor:

- **Drag and drop** one or more image files onto the editor textarea.
- **Paste** an image straight from the clipboard, for example a screenshot.

Either way the file is uploaded and a markdown image link (`![name](url)`) is inserted at the cursor. Pasted screenshots arrive without a real filename, so each is given a unique name before upload.

## Supported formats

These image types are accepted:

`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`

SVG is **not** accepted, because SVG files can carry scripts.

## WebP conversion

To keep stored files small, **JPEG and PNG uploads are automatically re-encoded to WebP**. The markdown link inserted into your post points at the converted `.webp` file. Transparency in PNGs is preserved.

`gif`, `webp`, and `avif` uploads are stored as-is:

- **GIF** may be animated, and converting would flatten it to a single frame.
- **WebP** and **AVIF** are already efficient formats.

If conversion ever fails (for example on a server whose PHP GD extension lacks WebP support), Lamb falls back to storing the original file unchanged, so uploads never break.

## Micropub uploads

Images sent via Micropub — both inline `photo` files on a post and files sent to the media endpoint — go through the same storage and WebP conversion as editor uploads.

## Server requirements

Uploads require the directory `src/assets/` to be writable by the web server or PHP-FPM user. WebP conversion requires PHP's GD extension built with WebP support (the common default); without it, files are stored in their original format.

## Related

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Add images to status and page posts.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts and upload photos from external apps.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Uploaded files live in `src/assets/`, not in theme directories.

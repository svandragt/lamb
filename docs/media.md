---
title: Media
nav_order: 10
---

# Media

Lamb lets you add images and video to posts without leaving the editor. It stores uploaded files under `src/assets/YYYY/MM/` and serves them from your own site, with no external host.

## Add images

When you're logged in, there are two ways to add an image to the post editor:

- **Drag and drop** one or more image files onto the editor textarea.
- **Paste** an image straight from the clipboard, such as a screenshot.

Either way, Lamb uploads the file and inserts a Markdown image link (`![name](url)`) at the cursor. Pasted screenshots arrive without a real filename, so Lamb gives each one a unique name before upload.

## Add video

**Drag and drop** one or more video files onto the editor textarea, the same way you would an image. Lamb uploads the file and inserts a Markdown link at the cursor, just as it does for an image. It tells the two apart by file extension, and renders the published post with an embedded `<video controls>` player instead of an `<img>`.

Lamb doesn't re-encode or resize video. Unlike JPEG and PNG, the file is stored exactly as you uploaded it, so keep an eye on the file size. See [Upload size limits](#upload-size-limits). Playback depends on the visitor's browser and operating system being able to decode the file, because Lamb doesn't transcode. `mp4` and `webm` play natively in effectively every modern browser. `mov`, the common iPhone export format, plays reliably in Safari, macOS, and iOS, but may fail to decode in some Linux browser builds that lack HEVC support.

A video-only post has no image for social sharing previews to pick up. See [Social embeds]({{ site.baseurl }}{% link social-embeds.md %}).

## Supported formats

Lamb accepts these image types:

`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`

Lamb **doesn't** accept SVG, because SVG files can carry scripts.

Lamb accepts these video types:

`mp4`, `webm`, `mov`

## WebP conversion

To keep stored files small, **Lamb re-encodes JPEG and PNG uploads to WebP**. The Markdown link it inserts into your post points at the converted `.webp` file. Lamb preserves transparency in PNGs.

Lamb stores `gif`, `webp`, and `avif` uploads unchanged:

- **GIF** may be animated, and converting would flatten it to a single frame.
- **WebP** and **AVIF** are already efficient formats.

**Lamb always stores video unchanged.** There's no server-side re-encoding or resizing for video, so a large source file stays large.

If conversion fails, for example on a server whose PHP GD extension lacks WebP support, Lamb falls back to storing the original file unchanged, so uploads never break.

## Image dimensions and layout shift

Lamb renders images in a post with their real pixel `width` and `height` on the `<img>` tag. Because images also load lazily, without those attributes the browser has no idea how tall an image will be until it arrives, so the text below it jumps down as you scroll. With them, the browser reserves the space up front and the page stays still.

Lamb reads the dimensions from the stored file when it renders the post, so they cost nothing on each page view. They apply to older posts too: an existing post picks them up the next time Lamb re-renders it. Lamb leaves images hosted elsewhere, a plain `https://…` link in your Markdown, alone, because it won't fetch a remote file to measure it.

This only sets the image's *intrinsic* size. How large the image actually appears is still up to your theme's CSS. If you write your own theme, see [Themes]({{ site.baseurl }}{% link themes.md %}).

## Micropub uploads

Images and video sent through Micropub, both inline `photo` files on a post and files sent to the media endpoint, go through the same storage as editor uploads, and images go through the same WebP conversion.

## Server requirements

Uploads require the web server or PHP-FPM user to have write access to `src/assets/`.

### Upload size limits

Lamb converts images to WebP and resizes them server-side, so the original file size mostly doesn't matter and large phone photos are fine. It stores video unchanged, so video file size is exactly what limits the upload. Either way, the server configuration caps the upload:

- **PHP** caps uploads with `upload_max_filesize` (default **2M**) and `post_max_size` (default **8M**). The Lamb Docker images raise these to `100M` and `100M`. On other hosts, raise them in `php.ini` or a `conf.d` file. Raise them further if you plan to share longer or higher-resolution clips.
- **NGINX** also caps the request body with `client_max_body_size` (default **1m**). The shipped `.nginx/snippets/lamb.conf` sets it to `100m`.

An upload over the limit fails silently from the editor's point of view, so check the server limits first when a large image or video won't upload.

### Pixel cap and memory

Before decoding a JPEG or PNG for WebP conversion, Lamb rejects anything whose declared width × height exceeds `MAX_UPLOAD_PIXELS`, which is 40 megapixels by default. This guards against a small file declaring an enormous size, a "decompression bomb". 40 megapixels comfortably covers real photos, including high-resolution phone camera modes.

The pixel cap **doesn't** protect against memory exhaustion the way PHP's `memory_limit` might suggest. GD allocates its decoded pixel buffer outside PHP's memory manager, so the buffer neither counts against `memory_limit` nor is limited by it. The real ceiling is the host's actual free RAM: roughly 4 bytes per pixel for the decode, plus a second buffer of similar size while resizing. A 40&nbsp;MP image can transiently use several hundred megabytes of real memory during conversion.

If you run Lamb on a memory-constrained host and see conversions crash, or the process get killed on large uploads, lower the cap with the `LAMB_MAX_UPLOAD_PIXELS` environment variable:

```shell
-e LAMB_MAX_UPLOAD_PIXELS=12000000   # ~12 megapixels
```

Lamb accepts any positive integer. An unset, non-numeric, zero, or negative value falls back to the 40-megapixel default. Lamb stores uploads whose declared pixel count exceeds the cap in their original format, unconverted, the same as when WebP support is missing.

WebP conversion relies on PHP's [GD extension](https://www.php.net/manual/en/book.image.php) being built **with WebP support**. This is the common default, but it isn't guaranteed on every host. WebP support is the only thing the conversion needs, and if it's missing, nothing breaks: Lamb stores each upload in its original format instead, so it saves JPEG and PNG files unchanged rather than converting them. You only lose the smaller WebP files.

### Check for WebP support

Run this on the server, using the same PHP binary your site uses:

```bash
php -r 'echo function_exists("imagewebp") ? "WebP: yes\n" : "WebP: no\n";'
```

For more detail, inspect GD's reported capabilities:

```bash
php -r 'print_r(gd_info());'
```

Look for `[WebP Support] => 1` in the output. `1` means Lamb converts uploads. `0`, or a missing line, means it stores them in their original format.

If you can't run the CLI, for example on shared hosting where only the web server's PHP is configured, put a one-line script such as `phpinfo();` into a temporary file in `src/`, load it in your browser, and search the page for "WebP" under the **gd** section. Delete the file afterwards.

If WebP support is missing and you want it, install or enable the WebP-capable GD build for your platform, for example `apt install php-gd` on Debian and Ubuntu, which bundles WebP support in current releases. Then restart PHP-FPM or your web server.

## Related

* [Post types]({{ site.baseurl }}{% link post-types.md %}): Add images and video to status and page posts.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts and upload photos from external apps.
* [Social embeds]({{ site.baseurl }}{% link social-embeds.md %}): A post's first image becomes its social preview card, and video-only posts fall back to the default card.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Uploaded files live in `src/assets/`, not in theme directories.
* [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}): The importer downloads referenced images into `src/assets/` too.
* [Export]({{ site.baseurl }}{% link export.md %}): Exports bundle referenced assets into the archive.

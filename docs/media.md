---
title: Media
---

# Media

Lamb lets you add images and video to posts without leaving the editor. Uploaded files are stored under `src/assets/YYYY/MM/` and served from your own site — there is no external host.

## Adding images

When logged in, there are two ways to add an image to the post editor:

- **Drag and drop** one or more image files onto the editor textarea.
- **Paste** an image straight from the clipboard, for example a screenshot.

Either way the file is uploaded and a markdown image link (`![name](url)`) is inserted at the cursor. Pasted screenshots arrive without a real filename, so each is given a unique name before upload.

## Adding video

**Drag and drop** one or more video files onto the editor textarea, the same way you would an image. The file is uploaded and a markdown link is inserted at the cursor, just like an image — Lamb tells the two apart by file extension and renders the published post with an embedded `<video controls>` player instead of an `<img>`.

Video is not re-encoded or resized: unlike JPEG/PNG, the file is stored exactly as uploaded, so keep an eye on the file size (see [Upload size limits](#upload-size-limits) below). Playback depends on the visitor's browser and operating system being able to decode the file — Lamb does not transcode. `mp4` and `webm` play natively in effectively every modern browser; `mov` (the common iPhone export format) plays reliably in Safari/macOS/iOS but may fail to decode in some Linux browser builds that lack HEVC support.

A video-only post has no image for social sharing previews to pick up — see [Social Embeds]({{ site.baseurl }}{% link social-embeds.md %}).

## Supported formats

These image types are accepted:

`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`

SVG is **not** accepted, because SVG files can carry scripts.

These video types are accepted:

`mp4`, `webm`, `mov`

## WebP conversion

To keep stored files small, **JPEG and PNG uploads are automatically re-encoded to WebP**. The markdown link inserted into your post points at the converted `.webp` file. Transparency in PNGs is preserved.

`gif`, `webp`, and `avif` uploads are stored as-is:

- **GIF** may be animated, and converting would flatten it to a single frame.
- **WebP** and **AVIF** are already efficient formats.

**Video is always stored as-is** — there is no server-side re-encoding or resizing for video, so a large source file stays large.

If conversion ever fails (for example on a server whose PHP GD extension lacks WebP support), Lamb falls back to storing the original file unchanged, so uploads never break.

## Micropub uploads

Images and video sent via Micropub — both inline `photo` files on a post and files sent to the media endpoint — go through the same storage (and, for images, WebP conversion) as editor uploads.

## Server requirements

Uploads require the directory `src/assets/` to be writable by the web server or PHP-FPM user.

### Upload size limits

Images are converted to WebP and resized server-side, so the original file size mostly doesn't matter — large phone photos are fine. Video is stored unchanged, so its file size is exactly what limits the upload. Either way, what caps the upload is the server configuration:

- **PHP** caps uploads with `upload_max_filesize` (default **2M**) and `post_max_size` (default **8M**). The Lamb Docker images raise these to `100M` / `100M`; on other hosts raise them in `php.ini` or a `conf.d` file. Raise them further still if you plan to share longer or higher-resolution clips.
- **NGINX** additionally caps the request body with `client_max_body_size` (default **1m**). The shipped `.nginx/snippets/lamb.conf` sets it to `100m`.

If an upload over the limit fails, it fails silently from the editor's point of view — check the server limits first when a large image or video won't upload.

WebP conversion relies on PHP's [GD extension](https://www.php.net/manual/en/book.image.php) being built **with WebP support**. This is the common default, but it isn't guaranteed on every host. WebP support is the only thing the conversion needs — if it's missing, nothing breaks: Lamb stores each upload in its original format instead, so JPEG and PNG files are saved as-is rather than being converted. You simply don't get the smaller WebP files.

### Checking for WebP support

Run this on the server (the same PHP binary your site uses):

```bash
php -r 'echo function_exists("imagewebp") ? "WebP: yes\n" : "WebP: no\n";'
```

For more detail, inspect GD's reported capabilities:

```bash
php -r 'print_r(gd_info());'
```

Look for `[WebP Support] => 1` in the output. `1` means uploads will be converted; `0` (or a missing line) means they'll be stored in their original format.

If you can't run the CLI — for example on shared hosting where only the web server's PHP is configured — drop a one-line script such as `phpinfo();` into a temporary file in `src/`, load it in your browser, and search the page for "WebP" under the **gd** section. Delete the file afterwards.

If WebP support is missing and you want it, install or enable the WebP-capable GD build for your platform (for example `apt install php-gd` on Debian/Ubuntu, which bundles WebP support in current releases) and restart PHP-FPM or your web server.

## Related

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Add images and video to status and page posts.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts and upload photos from external apps.
* [Social Embeds]({{ site.baseurl }}{% link social-embeds.md %}): A post's first image becomes its social preview card; video-only posts fall back to the default card.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Uploaded files live in `src/assets/`, not in theme directories.
* [WordPress import]({{ site.baseurl }}{% link wordpress-import.md %}): The importer downloads referenced images into `src/assets/` too.

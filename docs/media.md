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

Uploads require the directory `src/assets/` to be writable by the web server or PHP-FPM user.

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

* [Post Types]({{ site.baseurl }}{% link post-types.md %}): Add images to status and page posts.
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): Publish posts and upload photos from external apps.
* [Social Embeds]({{ site.baseurl }}{% link social-embeds.md %}): A post's first image becomes its social preview card.
* [Themes]({{ site.baseurl }}{% link themes.md %}): Uploaded files live in `src/assets/`, not in theme directories.

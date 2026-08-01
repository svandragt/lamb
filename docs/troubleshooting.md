---
title: Troubleshooting
nav_order: 35
---

# Troubleshooting

Common problems, and the page that explains each one in full.

## Posting and media

**A large image or video won't upload, and the editor gives no error.**
An upload over the server limit fails silently from the editor's point of view. Check PHP's `upload_max_filesize` and `post_max_size`, and NGINX's `client_max_body_size`. See [Upload size limits]({{ site.baseurl }}{% link media.md %}#upload-size-limits).

**Images are stored as JPEG or PNG instead of WebP.**
PHP's GD extension is built without WebP support. Nothing breaks — Lamb stores the original — but you don't get the smaller files. See [Check for WebP support]({{ site.baseurl }}{% link media.md %}#check-for-webp-support).

**Conversion crashes, or the PHP process is killed, on large photos.**
GD allocates its pixel buffer outside PHP's `memory_limit`, so the real ceiling is host RAM. Lower the cap with `LAMB_MAX_UPLOAD_PIXELS`. See [Pixel cap and memory]({{ site.baseurl }}{% link media.md %}#pixel-cap-and-memory).

**A video plays for you but not for a visitor.**
Lamb doesn't transcode. `mov` in particular can fail to decode on Linux browser builds without HEVC. See [Add video]({{ site.baseurl }}{% link media.md %}#add-video).

**Front matter isn't recognised when I write from an iPhone.**
iOS Smart Punctuation rewrites `---` into dashes. Lamb repairs the common cases automatically. See [Post types]({{ site.baseurl }}{% link post-types.md %}).

## Publishing

**A scheduled post went live at the wrong time.**
The server is probably on UTC. Set `timezone` in the site configuration. See [Timezone]({{ site.baseurl }}{% link scheduling.md %}#timezone).

**A scheduled post hasn't published, or its webmentions never went out.**
Publication itself needs no cron, but outbound webmentions and WebSub pings for a scheduled post wait for the next `/_cron` run. See [Cron scheduled tasks]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}).

**Feeds aren't bringing in new items.**
Something has to call `/_cron`, each feed is cached for 30 minutes, and a failing feed keeps its old success time. Check the **Logs** tab of `/settings`. See [Check crawl status]({{ site.baseurl }}{% link cross-posting.md %}#check-crawl-status).

**An old URL 404s after I renamed a page.**
Changing a slug creates a 301 automatically, but a live post always wins over a redirect to the same slug. See [Redirections]({{ site.baseurl }}{% link redirections.md %}).

## Configuration and access

**A setting I changed has no effect.**
The most common cause is writing a single value where a `[section]` belongs, or putting a single value below a section header. Lamb ignores a malformed setting and tells you which ones it ignored when you save. See [Values and sections]({{ site.baseurl }}{% link site-configuration.md %}#values-and-sections).

**I'm locked out with `429 Too Many Requests`.**
Ten failed logins from one address block further attempts for 15 minutes. There's no reset command by design; the block lapses on its own. See [Login security]({{ site.baseurl }}{% link login-security.md %}).

**Behind a proxy, everyone shares one login throttle and the logs show the proxy's address.**
Lamb reads `REMOTE_ADDR` and ignores `X-Forwarded-For`, which any client can forge. Configure your proxy to present the real client address. See [Behind a reverse proxy]({{ site.baseurl }}{% link login-security.md %}#behind-a-reverse-proxy).

**A Micropub client can't connect.**
Check `site_url` first — without it Lamb refuses every token. Then turn on `micropub_debug` and read `data/micropub.log`. See [Troubleshooting Micropub]({{ site.baseurl }}{% link micropub.md %}#troubleshooting).

**`/export` says it can't produce an archive.**
PHP's `zip` extension is missing. Everything else works without it. See [Export requirements]({{ site.baseurl }}{% link export.md %}#requirements).

## Themes

**Images look squashed or stretched.**
Rendered images carry intrinsic `width` and `height`. If your stylesheet sets `img { max-width: 100% }`, pair it with `height: auto`. See [Themes]({{ site.baseurl }}{% link themes.md %}).

**A theme change didn't appear.**
Asset URLs carry a content hash, so a changed file gets a new URL. If you're behind a CDN or an aggressive proxy cache, that's the next place to look. See [Cache static assets]({{ site.baseurl }}{% link nginx.md %}#cache-static-assets).

**Anonymous visitors see a stale page after I publish.**
Anonymous responses carry `max-age=300`, so there's a five-minute window by design. If you enabled `fastcgi_cache`, its TTL applies too. See [Cache PHP responses]({{ site.baseurl }}{% link nginx.md %}#cache-php-responses-optional).

## Still stuck

Failed logins are written to PHP's error log, and your web server or PHP-FPM configuration decides where that lands. Check there first — most server-side problems leave a line in it.

If the problem looks like a bug, [open an issue](https://github.com/svandragt/lamb/issues) with your PHP version, how you installed Lamb, and what you saw.

## Related

* [Backup and restore]({{ site.baseurl }}{% link backup-restore.md %}): Before you change anything you can't undo.
* [Site configuration]({{ site.baseurl }}{% link site-configuration.md %}): Every setting, and the two shapes they take.
* [Media]({{ site.baseurl }}{% link media.md %}): Upload limits, formats, and WebP conversion.
* [Login security]({{ site.baseurl }}{% link login-security.md %}): The throttle and the failed-attempt log.

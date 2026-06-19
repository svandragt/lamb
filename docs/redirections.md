---
title: Redirections
---

## How redirects work

When a URL is requested, Lamb checks in this order:

1.  Is there a live post with this slug? → Serve it.
2.  Is there a manual redirect in `[redirections]` config? → 301 redirect.
3.  Is there an automatic redirect stored from a previous slug change? → 301 redirect.
4.  → 404.

## Manual redirects

Add a `[redirections]` section to your site configuration at `/settings`:

```
[redirections]
;; Format: <old-path-segment> = <destination>
;; Destination can be:
;;   - A root-relative URL
old-post = /new-post
;;   - A bare slug (treated as root-relative)
another-old = new-page
;;   - A full external URL
legacy-page = https://archive.example.com/page
```

The key is the old URL path segment (the part after `/`, no leading slash). The value is where visitors should be sent.

## Automatic redirects (reslugging)

When you edit a page post and change its `slug:` front-matter:

1.  The post's slug is updated to the new value.
2.  A 301 redirect is created from the old slug to the new one automatically.

**Before reslugging**, a post at `/old-slug` is served normally.

**After reslugging** to `/new-slug`:

*   `/old-slug` → 301 → `/new-slug`
*   `/new-slug` serves the post directly.

### Removal of an automatic redirect

Publishing a new post whose slug matches an existing redirect's source automatically removes the redirect — the new post takes over that URL.

### Chain flattening

Reslugging the same post more than once would otherwise leave a chain of redirects (`old → /newer`, `newer → /newest`), making a visitor follow several 301s. The [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run flattens these so every hop points straight at the final destination, breaks any redirect loops it finds, and removes redirects whose destination no longer resolves to a post (a redirect to a [trashed]({{ site.baseurl }}{% link trash.md %}) post is kept, since the post may be restored). This is automatic maintenance — there is nothing to configure.

## Precedence rules

A live post is always served directly, regardless of any redirect (manual or automatic) pointing to the same slug. A redirect only fires when no published post has that slug.

If you create a post whose slug matches an entry in `[redirections]`, Lamb will show a notice:

> A manual redirect for `old-slug` still exists in Settings → \[redirections\]. You may want to remove it.

Once the post exists, the config entry has no effect and can be safely deleted from `/settings`.

## Related

*   [Site Configuration]({{ site.baseurl }}{% link site-configuration.md %})
*   [Post Types]({{ site.baseurl }}{% link post-types.md %})

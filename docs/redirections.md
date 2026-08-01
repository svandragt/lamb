---
title: Redirections
---

# Redirections

## How redirects work

When someone requests a URL, Lamb checks the following in order:

1.  Is there a live post with this slug? If so, serve it.
2.  Is there a manual redirect in the `[redirections]` config? If so, return a 301 redirect.
3.  Is there an automatic redirect stored from a previous slug change? If so, return a 301 redirect.
4.  Otherwise, return a 404.

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

The key is the old URL path segment, which is the part after `/` with no leading slash. The value is where Lamb sends visitors.

## Automatic redirects (reslugging)

When you edit a page post and change its `slug:` front matter:

1.  Lamb updates the post's slug to the new value.
2.  Lamb creates a 301 redirect from the old slug to the new one.

**Before reslugging**, Lamb serves a post at `/old-slug` normally.

**After reslugging** to `/new-slug`:

*   `/old-slug` returns a 301 to `/new-slug`.
*   `/new-slug` serves the post directly.

### Remove an automatic redirect

When you publish a new post whose slug matches an existing redirect's source, Lamb removes the redirect. The new post takes over that URL.

### Chain flattening

Reslugging the same post more than once would otherwise leave a chain of redirects (`old → /newer`, `newer → /newest`), making a visitor follow several 301s. The [`/_cron`]({{ site.baseurl }}{% link cron-scheduled-tasks.md %}) run flattens these so every hop points straight at the final destination, breaks any redirect loops it finds, and removes redirects whose destination no longer resolves to a post. It keeps a redirect to a [trashed]({{ site.baseurl }}{% link trash.md %}) post, since you may restore that post. This maintenance is automatic, and there's nothing to configure.

## Precedence rules

Lamb always serves a live post directly, regardless of any manual or automatic redirect pointing at the same slug. A redirect only fires when no published post has that slug.

If you create a post whose slug matches an entry in `[redirections]`, Lamb shows a notice:

> A manual redirect for `old-slug` still exists in Settings → \[redirections\]. You may want to remove it.

Once the post exists, the config entry has no effect and you can safely delete it at `/settings`.

## Related

*   [Site configuration]({{ site.baseurl }}{% link site-configuration.md %})
*   [Post types]({{ site.baseurl }}{% link post-types.md %})

---
title: Site Configuration
---

# Site Configuration

Lamb does not need a configuration file, it will run happily without it. It does provide a settings page after logging in where the instance can be configured.

The default configuration. Real defaults ship as active lines so you can edit
one value rather than write it from scratch; personal details stay commented:

```
;; Title of the site, shown in the HTML and feed views
site_title = My Microblog

;; The canonical URL of your site, e.g. https://example.com (no trailing slash).
;; Required for Micropub, and pins absolute URLs to your real domain.
;; Overridden by the LAMB_SITE_URL environment variable.
;site_url = https://example.com

;; Author email in feed
;author_email = joe.sheeple@example.com

;; Author name in feed
;author_name = Joe Sheeple

;; Active theme directory name. New installs default to 2026; `base` is the fallback library.
theme = 2026

;; Number of posts shown per page in lists and feeds.
posts_per_page = 10

;; Your timezone, used for post dates and scheduling (the server is often UTC).
;; Use a name from https://www.php.net/manual/en/timezones.php.
timezone = UTC

;; Feed-ingested posts are saved as drafts by default for editorial review.
;; Set to false to publish feed items directly.
feeds_draft = true

;; IndieAuth endpoints used for Micropub discovery. Override to use your own server.
authorization_endpoint = https://indieauth.com/auth
token_endpoint = https://tokens.indieauth.com/token

;; When content is not found, instead of a 404, the user is redirected to the same
;; relative path on another site. Useful for archived or under-construction sites.
;404_fallback = https://my.oldsite.com

;; WebSub hubs used to push new posts to feed subscribers in real time.
;; Hubs are advertised in the Atom and JSON feeds, and pinged when you publish.
;; Separate multiple hubs with commas.
;websub_hubs = https://hub.example.com/

;; Gates features still gathering real-world testing before general release
;; (currently: the WordPress, Known, and Lamb-export import CLI scripts — see
;; wordpress-import.md, known-import.md, lamb-import.md). Off by default, and
;; reset to false automatically whenever an upgrade changes what this gates,
;; so check the docs for what's currently covered before turning it back on.
experimental_features = false

[menu_items]
;; Add <label>=<url> entries. URL can be:
;;   - A post slug, which hides the post from the feed and timeline
;About Me = about
;;   - A root-relative link to built-in pages
;Subscribe = /feed
;;   - A full URL to an external site
;Source = https://github.com/svandragt/lamb
Home = /
Feed = /feed

[footer_items]
;; Add <label>=<url> entries for the site footer. Same format as [menu_items].
;; Useful for secondary navigation: privacy policy, colophon, social links, etc.
;Privacy = /privacy

[redirections]
;; Add 301 redirects for old URL path segments.
;; Format: <old-slug> = <destination>
;; Destination can be a root-relative URL, a bare slug, or a full external URL.
;old-post = /new-post
;legacy-page = https://archive.example.com

[feeds]
;; Add feeds whose content gets cross-posted into the blog.
;; Format: <name>=<url> where URL is an RSS or Atom feed.
;; Test feed compatibility at https://simplepie.org/demo/
;lamb-releases=https://github.com/svandragt/lamb/releases.atom

[preconnect]
;; List external origins to preconnect to, improving load time for external resources.
;; Format: <label>=<origin>
;google-fonts = https://fonts.googleapis.com
;google-fonts-static = https://fonts.gstatic.com

[me]
;; Add rel="me" identity links for IndieAuth verification.
;; Each entry is <label>=<url>. Links appear as <link rel="me"> in the HTML head.
;Github = https://github.com/yourusername
;Email = mailto:you@example.com

[syndicate_to]
;; Add POSSE syndication targets shown to Micropub clients (e.g. Quill).
;; Format: <uid>=<name>  where uid is the profile URL of the target silo.
;https://bsky.app/profile/yourusername = Bluesky
;https://mastodon.social/@yourusername = Mastodon
```

## Values and sections

Every setting is one of two shapes, and the difference is a pair of brackets:

* A **single value**, written `key = value` — `site_title`, `theme`,
  `posts_per_page`, `timezone` and the rest of the keys above the first
  `[section]` header.
* A **section** of `label = value` entries under a `[name]` header —
  `[menu_items]`, `[footer_items]`, `[feeds]`, `[preconnect]`, `[me]`,
  `[redirections]` and `[syndicate_to]`.

Getting the shape wrong is easy to do and easy to miss, because the file is
still valid INI either way:

```
[site_title]          ;; wrong: a section where a single value belongs
feeds = https://…     ;; wrong: a single value where the [feeds] section belongs

[menu_items]
Home = /
Home = /start         ;; wrong: a repeated label makes the entry a list
```

Lamb ignores a setting written in the wrong shape and uses the default instead,
so a slip cannot take the site down — and it tells you which ones it ignored
when you save, alongside the "Settings saved successfully" message. If a
setting you changed appears to have no effect, that message is the first place
to look.

Note that everything after a `[section]` header belongs to that section until
the next one. A single-value setting written below a section header becomes an
entry in it, so keep them all at the top of the file.

## Site URL

`site_url` is the canonical address of your site, e.g. `https://example.com`. It is
optional for a plain blog, but worth setting:

* **Micropub needs it.** IndieAuth tokens are issued for an identity, and Lamb has
  to know its own identity to tell whether a token belongs to you. Without
  `site_url` the Micropub endpoint refuses every token, and logs
  `micropub: rejecting token, no site_url configured` via PHP's error log.
* **It pins absolute URLs.** Feeds, social embed tags, and the endpoint discovery
  links otherwise use whatever host the incoming request asked for. Setting
  `site_url` keeps them on your real domain even if a request arrives with a
  different `Host` header.

You can also set it outside the settings page with the `LAMB_SITE_URL` environment
variable, which takes precedence over the INI value — handy when the same
configuration is deployed to more than one hostname.

### Serve from the root of a domain

Give `site_url` the domain on its own, with no path. Lamb does not support an
install under a subdirectory, so `https://example.com/blog` cannot work: Lamb
reads the domain and drops the path, while your IndieAuth identity is the whole
address. Micropub then refuses every token, because the identity it compares
against is not the one your tokens were issued for.

The settings page warns you if you save a `site_url` with a path. To run Lamb at
`example.com/blog` today, give it its own subdomain — `blog.example.com` — and
point `site_url` at that.

## Related

* [Setting up Cross-Posting]({{ site.baseurl }}{% link cross-posting.md %}#setup) requires site configuration changes.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): The `feeds_draft` setting controls whether ingested posts are published or saved as drafts.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The `websub_hubs` setting enables real-time push to feed subscribers.
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): The `[me]`, `authorization_endpoint`, `token_endpoint`, and `[syndicate_to]` settings enable Micropub publishing and POSSE syndication.
* [Preconnect]({{ site.baseurl }}{% link preconnect.md %})
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): The `timezone` setting determines when scheduled posts go live.
* [Themes]({{ site.baseurl }}{% link themes.md %}): The `theme` key selects the active theme.

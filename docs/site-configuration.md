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
```

## Related

* [Setting up Cross-Posting]({{ site.baseurl }}{% link cross-posting.md %}#setup) requires site configuration changes.
* [Drafts]({{ site.baseurl }}{% link drafts.md %}): The `feeds_draft` setting controls whether ingested posts are published or saved as drafts.
* [Feeds]({{ site.baseurl }}{% link feeds.md %}): The `websub_hubs` setting enables real-time push to feed subscribers.
* [Menu Items]({{ site.baseurl }}{% link menu-items.md %})
* [Micropub]({{ site.baseurl }}{% link micropub.md %}): The `[me]`, `authorization_endpoint`, and `token_endpoint` settings enable Micropub publishing.
* [Preconnect]({{ site.baseurl }}{% link preconnect.md %})
* [Redirections]({{ site.baseurl }}{% link redirections.md %})
* [Scheduling]({{ site.baseurl }}{% link scheduling.md %}): The `timezone` setting determines when scheduled posts go live.
* [Themes]({{ site.baseurl }}{% link themes.md %}): The `theme` key selects the active theme.

---
title: Site Configuration
---

# Site Configuration

Lamb does not need a configuration file, it will run happily without it. It does provide a settings page after logging in where the instance can be configured.

> Note: the `[redirections]` section described below is still work in progress on the `issue-88-redirection-feature` branch. It is not available on the current `main` or `release` branches.

The full default configuration (all keys commented out = use built-in defaults):

```
;; Author email in feed
;author_email = joe.sheeple@example.com

;; Author name in feed
;author_name = Joe Sheeple

;; Title of the site, in html and feed views
;site_title = My Microblog

;; When content is not found, instead of a 404, the user is redirected to the same
;; relative path on another site. Useful for archived or under-construction sites.
;404_fallback = https://my.oldsite.com

[menu_items]
;; Add <label>=<url> entries. URL can be:
;;   - A post slug, which hides the post from the feed and timeline
;About Me = about
;;   - A root-relative link to built-in pages
;Subscribe = /feed
;;   - A full URL to an external site
;Source = https://github.com/svandragt/lamb

;; Planned, not yet available on main/release:
;[redirections]
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

;; Feed-ingested posts are saved as drafts by default for editorial review.
;; Set to false to publish feed items directly.
;feeds_draft = false

[preconnect]
;; List external origins to preconnect to, improving load time for external resources.
;; Format: <label>=<origin>
;google-fonts = https://fonts.googleapis.com
;google-fonts-static = https://fonts.gstatic.com

;; IndieAuth endpoints used for Micropub discovery.
;; Override to use your own IndieAuth server.
;authorization_endpoint = https://indieauth.com/auth
;token_endpoint = https://tokens.indieauth.com/token

[me]
;; Add rel="me" identity links for IndieAuth verification.
;; Each entry is <label>=<url>. Links appear as <link rel="me"> in the HTML head.
;Github = https://github.com/yourusername
;Email = mailto:you@example.com
```

## Related

* [Setting up Cross-Posting]({% link cross-posting.md %}#setup) requires site configuration changes.
* [Drafts]({% link drafts.md %}): The `feeds_draft` setting controls whether ingested posts are published or saved as drafts.
* [Menu Items]({% link menu-items.md %})
* [Micropub]({% link micropub.md %}): The `[me]`, `authorization_endpoint`, and `token_endpoint` settings enable Micropub publishing.
* [Preconnect]({% link preconnect.md %})
* [Redirections]({% link redirections.md %})
* [Themes]({% link themes.md %}): The `theme` key selects the active theme.

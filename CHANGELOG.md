# Changelog

All notable changes to Lamb are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and Lamb uses
[SemVer](https://semver.org/) with `0.y` as the compatibility boundary until 1.0.

Every user-visible change adds a bullet under Unreleased in the same commit as
the code. At release, that block becomes the version block (see `RELEASING.md`).

## [Unreleased]

### Removed

- The `import-wordpress.php`, `import-known.php` and `import-lamb.php` shims are removed. Run `bin/lamb import <wordpress|known|lamb> <path>` instead; the flags and output are the same.
- A theme's `feed.php` or `feed_json.php` override is no longer read. Lamb's built-in feeds are used; delete the file from your theme.
- The prebuilt Docker image at `ghcr.io/svandragt/lamb` is no longer published. Build from `Dockerfile.release` with `docker-compose.yml`; images up to 0.14.0 stay pullable.

### Added

- The new-entry editor autosaves your draft in the browser, so a reload or accidental navigation doesn't lose the post. The edit form offers a "Restore unsaved changes" link when a saved draft differs from the stored post.
- An attach-photo button on the entry and edit forms opens the phone's camera or photo library.
- Uploaded images get 800px and 1200px WebP variants, and posts render them with `srcset` so phones download a smaller file.
- `sitemap.xml` becomes a sitemap index once a site passes 50,000 URLs.
- A "Write your first post" tutorial in the docs.
- The deprecation policy is written down: a deprecation lasts one published release and is removed in the next.

### Changed

- The database schema is declared explicitly and frozen. Existing installs get any missing columns added on first boot. Code that writes an undeclared column now fails instead of silently adding one.
- Outbound DNS lookups for feeds and webmentions are capped by `DNS_RESOLVE_TIMEOUT` (5 seconds), so a stalled resolver can no longer hold the `/_cron` lock.
- Tag archives covering several tags are sorted by date across all of them.
- Micropub responses send private cache headers.
- The 404 page's search suggestion searches for the words in the missing path rather than the raw path.

### Fixed

- WordPress import: images renamed at the source (PNG bytes under a `.jpg` name) now import; an assets directory that can't be created is logged instead of leaving the image remote; numeric slugs are dropped even without an old path to redirect.
- Search shows one empty-state message, not two.
- Hashtag linking no longer rewrites hashtags inside existing link text.
- Multi-word tags round-trip through add and remove.
- Login throttling: closed a check-then-act race before the password check and fixed the counter's read-increment-write under load.
- Micropub: a token without `update` scope can no longer tell a real post from a missing one.
- Theme CSS minification no longer strips comment-like text inside string literals and `url()`.
- XML output strips control characters that XML 1.0 forbids, and the sitemap host is escaped.
- Webmention reads on the post page are bounded.

### Upgrade notes

- Replace any cron or script calling the `import-*.php` shims with `bin/lamb import`.
- If you deploy with Docker, switch from pulling `ghcr.io/svandragt/lamb` to building the image locally; see `docs/docker.md`.
- No config changes are needed. `DNS_RESOLVE_TIMEOUT` is a new tunable constant with a safe default.
- The database now uses SQLite's WAL mode, which keeps `lamb.db-wal` and `lamb.db-shm` beside `lamb.db`. Run `bin/lamb` as the same user as your web server (for example `sudo -u www-data bin/lamb import …`). Run as another user, it leaves those files owned by that user and every page then fails with "attempt to write a readonly database" until you delete them. Tracked in #831.

## [0.14.0] - 2026-08-24

This release is mostly internal: a sweep that converged ten duplicated mechanisms behind the recurring bugs (visibility rules, the post-write path, feed generation, config resolution, XML escaping), so the same fix no longer has to be made in several places. A handful of those changes are visible when you run a blog.

### Fixed

- Menu pages no longer show up in the "Related" list on a post.
- A feed that fails to fetch now backs off instead of being retried on every `/_cron` run, and one slow or erroring feed can no longer stall webmention and WebSub delivery for the others.
- A post's creation date is kept when its feed re-syncs, so re-synced posts no longer jump to the top of the timeline.
- Exporting your site now fails loudly if a post can't be written to the archive, instead of producing a backup that silently drops it.
- Scheduled posts are no longer pinged or announced before they actually publish.
- Reply context in Atom feeds is tidied up: the superseded `rel="in-reply-to"` link is dropped and reply entries follow RFC 4685 threading.

### Changed

- The WordPress and other importers now run through a single `bin/lamb import` command.

### Upgrade notes

No action required: no new PHP extensions, config keys, or deployment changes. If you script the importer, switch to `bin/lamb import`.


## [0.13.0] - 2026-08-22

site export, tighter Micropub handling, faster feeds and listings on big sites,
and a broad security and bug-fix sweep. The minimum PHP version is now 8.4.

### Upgrade notes

- **PHP 8.4 or newer is now required.** Update your runtime before upgrading.
- **Make sure your database's PDO driver is installed.** Lamb now fails fast if
  the driver it connects through is missing — `pdo_sqlite` for the default
  SQLite setup, `pdo_mysql` for MySQL. The Docker image already includes them.
- **The WordPress, Known, and Lamb importers now require `experimental_features`**
  to be enabled in your site configuration. Turn it on before running an import.
- **Subdirectory installs:** a `site_url` that carries a path now logs a warning
  instead of failing silently. Micropub under a subpath is still limited.

### Added

- **Site export.** A new endpoint exports your whole site as Markdown posts plus
  a JSON manifest, ready to archive or move.
- **Restore from a Lamb export.** `import-lamb.php` imports an export archive
  back into a Lamb install, assets included.
- **Known importer.** Bring posts across from a Known site.
- **Login throttling.** Repeated failed admin logins from one address are now
  rate-limited.
- **Reply context in feeds and Micropub.** `in-reply-to` is carried through the
  Atom and JSON feeds and the Micropub property paths, so replies keep their
  context.
- **Richer Micropub responses.** A successful create (201) now returns a JSON
  body with preview and edit links.
- **`LAMB_MAX_UPLOAD_PIXELS`.** A new limit on upload dimensions, alongside a
  lighter WebP conversion path.

### Changed

- Images now reserve their box with intrinsic width and height, reducing layout
  shift as a page loads.
- Preview links, private pages, and menu pages are kept out of search and tag
  listings.
- Micropub `q=config` now requires a token, per the spec.
- **Faster on large sites:** the sitemap, tag pages, tag feed, and related-post
  lookups now page through the database instead of loading whole archives into
  memory, the post table is indexed, and the sitemap is cached.
- Conditional requests use the ETag to decide a `304`, following RFC 9110.
- The release Docker image now runs as a non-root user.

### Fixed

- The admin login hash is no longer written to a world-readable `.env`.
- Editing a post no longer mangles the body or serves a stale rendered version,
  and front matter survives edits to posts saved with Windows line endings.
- A rejected image upload no longer pastes its error message into your post.
- Micropub updates no longer drop front matter or report phantom successes, and
  malformed update actions are refused instead of returning a 500.
- A failed media upload now answers with the correct error, and a failed post
  edit no longer points its own URL at a 404.
- Imported hashtags no longer arrive escaped or duplicated.
- 404 page, OpenGraph timestamps, menu-page filtering, task-list checkboxes, and
  category editing all have correctness fixes.
- Feed ingestion is steadier: outbound fetches are bounded in time, feed bodies
  are capped, and the crawl watermark no longer skips or repeats items.
- A post can no longer redirect to itself, and an imported slug can no longer
  shadow a built-in route.

### Security

- Outbound fetches are pinned to the resolved IP, closing a DNS-rebinding SSRF
  gap, and every outbound fetch is bounded in time.
- Protocol-relative and non-`http(s)` targets are rejected in the redirect guard
  and in reply links.
- Micropub content HTML is sanitised at the attribute level, not just tags.
- The OpenGraph image sizer is confined to the web root.

**Full commit history:** https://github.com/svandragt/lamb/compare/0.12.0...0.13.0


## [0.12.0] - 2026-07-25

### Added

- **WordPress import.** Bring an existing WordPress export (WXR file) in as posts, downloading its images and converting HTML to Markdown along the way. See [WordPress import](https://svandragt.github.io/lamb/wordpress-import).
- **JSON Feed support.** Cross-posting from feeds now understands [JSON Feed](https://jsonfeed.org) sources, not just RSS/Atom. See [Cross-posting from feeds](https://svandragt.github.io/lamb/cross-posting).
- **Sitemap and robots.txt.** `/sitemap.xml` and `/robots.txt` are generated automatically, with private routes excluded from crawling.
- **microformats2 markup.** Themes now emit `h-entry`/`h-card` markup, so posts are readable by IndieWeb readers and tools.
- **POSSE syndication tracking.** Configure syndication targets and Lamb records what a post syndicated to, showing the links on the post. See [POSSE syndication](https://svandragt.github.io/lamb/micropub/#posse-syndication).
- **Redirections tab.** Settings now lists the automatic redirects Lamb has created when slugs changed.
- **Drag-and-drop video uploads** in the post editor, alongside images.
- **Meta description** on pages, for link previews and search results.

### Changed

- **Micropub now requires a canonical site URL to be configured** (see Upgrade notes — this is the one to read).
- Deleted/trashed posts with a missing deletion timestamp are now correctly excluded from the trash purge.
- Root-relative image URLs are absolutised in feeds and OpenGraph tags, so images show up correctly for readers off-site.
- Configuration values and front-matter fields written in the wrong shape (e.g. a list where text is expected) are now coerced or dropped with a warning at save time, instead of crashing the page.

### Fixed

Eleven security fixes, the two most serious remotely triggerable without any credentials:

- **Micropub authentication bypass via a spoofed `Host` header (critical).** A token issued for an attacker's own domain could be replayed against your site by sending a matching `Host` header, granting unauthenticated create/update/delete on posts. Fixed by comparing tokens against an explicitly configured site URL rather than the request's `Host` header.
- **Front matter could overwrite or delete arbitrary posts (high).** A Micropub client with only `create` scope could smuggle an `id:` or `deleted:` front-matter key to overwrite or trash any post, bypassing scope checks.
- **Stored XSS via hashtag linkification (high).** A hashtag inside an image `alt` or link `title` (including from an ingested feed) could break out of the attribute and inject a working event handler.
- **Unbounded outbound HTTP requests.** Webmention and feed-crawl fetches lacked response-size caps, and Micropub token verification followed redirects — a malicious redirect could leak the bearer token to another host.
- **WebSub hub pings had no URL guard,** allowing a hub value to reach an internal address (SSRF).
- **Upload content wasn't checked against its extension,** so non-image bytes (e.g. HTML/SVG) could be stored and served from your own domain under an image filename; uploads are now sniffed by content, not just name.
- **PHP execution under `/assets` is now blocked** at the web server level.
- Several SSRF fixes in feed ingestion, webmention fetching, and the WordPress image importer (redirects and hostnames weren't fully guarded).
- Open redirect via slug-based auto-redirect; existence oracle for draft/trashed/scheduled posts via webmention target; missing scope checks on Micropub delete/undelete and the media endpoint; source-query disclosure of unpublished content.
- Decompression-bomb DoS in image upload, and predictable upload filenames that could silently overwrite another file.
- A `/_cron` race (TOCTOU) that allowed concurrent runs under load.
- Security response headers (`X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`) are now set; `expose_php` is off in the release image.

### Upgrade notes

- **Set a canonical site URL before upgrading, or Micropub will stop working.** Add `site_url = https://your-site.example` in Settings, or set the `LAMB_SITE_URL` environment variable. Token verification now fails closed without it — this is what closes the Micropub auth bypass described above. See [Site configuration](https://svandragt.github.io/lamb/site-configuration) and [Micropub](https://svandragt.github.io/lamb/micropub).
- No new PHP extensions are required for WordPress import (uses the existing `simplexml`/DOM support).



## [0.11.0] - 2026-06-19

### Added

- **Task lists.** Write `- [ ]` or `- [x]` in a post and you get checkboxes. When you're logged in you can tick them straight from the post and it saves – no need to open the editor.
- **Image viewer.** Tap an image that's wider than the text column and it opens full-size in a small modal; the page behind it stops scrolling while it's open. See [Media](https://svandragt.github.io/lamb/media).
- **Per-post social image.** The social/OpenGraph embed image is now tied to the post rather than one image for the whole site, so you can set a different one per post. See [Social embeds](https://svandragt.github.io/lamb/social-embeds).
- **Micropub diagnostics.** Optional logging on the Micropub endpoint, off by default, for when a posting app is misbehaving and you need to see what it actually sent. See [Micropub](https://svandragt.github.io/lamb/micropub).

### Changed

- A leading `# Heading` at the top of a post now becomes the title on its own; any other headings in the body get demoted so the outline stays sensible. See [Post types](https://svandragt.github.io/lamb/post-types).
- Logins last a week now, and survive a browser restart, instead of dropping you out quickly.
- Pagination uses clean path URLs, and a search now redirects permanently (301) rather than temporarily.
- A fresh install starts with a site title and Home/Feed menu items already filled in, so a new blog doesn't look half-built on first run. See [Menu items](https://svandragt.github.io/lamb/menu-items).
- The 2026 theme's editor no longer ligatures the font, so symbols read literally as you type them.
- Deleting a post returns you to the page you deleted it from – the drafts or trash list, say – rather than always the homepage.
- `make-password.php` warns you when a password is weak, and keeps the cleartext out of `.env` by default.

### Fixed

- Front matter is only recognised as a leading `---` fence, so a `---` you've used as a horizontal rule further down the post survives. Front-matter keys like `Title`/`title` and `in_reply_to`/`in-reply-to` are normalised the same way on every save path, web editor and Micropub alike. See [Post types](https://svandragt.github.io/lamb/post-types).
- Re-ingested feed items no longer turn into duplicate posts, and a post you've edited yourself is no longer overwritten the next time the feed updates. See [Cross-posting from feeds](https://svandragt.github.io/lamb/cross-posting).
- Pasting a URL over selected text wraps the selection in a Markdown link on iOS Safari, which it wasn't doing before.
- A failed login sends you back to the login page so you actually see the error, instead of swallowing it.
- The drafts, trash and scheduled pages no longer show two sets of pagination controls.
- Editing near the end of a long post no longer jumps the scroll position.
- Feed and home pages no longer error when the site title hasn't been set.
- A Micropub request with a token that lacks the right scope now gets a proper 403 with a `WWW-Authenticate` challenge, rather than a confusing failure.
- Redirects are hardened against CR/LF header injection.

### Upgrade notes

- Sessions now live in their own directory under your data directory. Active logins get dropped once on upgrade – you'll sign in again – and the data directory has to stay writable by the web server or PHP-FPM user, which it already is for the database. See [Upgrading](https://svandragt.github.io/lamb/upgrading).


## [0.10.0] - 2026-06-07

This release brings your blog into the IndieWeb conversation — webmentions, reply posts, and WebSub — and makes Lamb much easier to install and upgrade.

### Added

- **Webmentions**: your blog now sends webmentions to sites you link to when you publish (including reply-to targets), and can receive webmentions from other sites. Received mention authors are shown on the post for the author when logged in.
- **Reply posts**: link to a post you're replying to and Lamb shows the reply context above your post.
- **Easier installs**: each release now ships a tarball with all dependencies bundled (no git or Composer needed) and a ready-to-run Docker image at `ghcr.io/svandragt/lamb`.
- **One-command upgrades**: git installs get `bin/upgrade` — it updates code and dependencies, then health-checks your site and tells you how to roll back if that fails. Cron-friendly.
- **WebSub**: feeds advertise a hub and Lamb pings it when you publish, so subscribers get updates instantly.
- Server-side syntax highlighting for fenced code blocks.
- Feed crawl status: the settings page shows when each subscribed feed was last crawled and whether it succeeded.
- Drafts and scheduled posts get expiring preview links — in the web editor **(new since rc2)** and via Micropub — so you can check them before they go live.
- Paste images straight from the clipboard into the editor.
- Pasting a URL while text is selected wraps the selection in a markdown link.
- Atom feed `<icon>` and `<logo>`: drop `favicon.ico` / `logo.png` in your web root and the feed picks them up.

### Changed

- JPEG and PNG uploads are converted to WebP for smaller files (originals are kept if conversion isn't possible).
- Oversized image uploads are downscaled to a 1600px longest edge.
- Upload size limits in the shipped Docker images and nginx config are raised to 20M, so phone photos upload without tweaking PHP defaults. **(new since rc2)**
- New installs default to the 2026 "Notes" theme.
- The default theme is now named `base`; existing installs are migrated automatically.
- The settings page is now self-documenting, with defaults shown inline.
- Faster for visitors: anonymous pages are cacheable with ETag/304 support, and CSS/JS URLs are cache-busted on change.
- Small theme stylesheets are inlined to cut render-blocking CSS.
- Static assets are cached immutably in the shipped server configs.
- FrankenPHP is now the standard for dev and self-hosted setups; nginx instructions remain available.

### Fixed

- Draft and scheduled post permalinks are no longer visible to logged-out visitors — and no longer 404 for the logged-in author. **(new since rc2)**
- Scheduled posts no longer appear in related posts.
- Scheduled posts send their webmentions at publication time, not when saved.
- Outbound webmentions are only sent to `http(s)` endpoints a site advertises. **(new since rc2)**
- Webmention delivery is no longer recorded as failed when the receiving server's status line omits a reason phrase. **(new since rc2)**
- A regex failure during syntax highlighting of a very large post no longer corrupts the stored post HTML. **(new since rc2)**
- The release Docker image includes the `gd` extension, fixing a 500 on image upload. **(new since rc2)**
- Removing the title from a post's front matter on edit now clears the stored title.
- Front matter typed on iOS with smart punctuation is recognised.
- Feed-ingested posts keep their feed-name slug prefix.
- Cron runs no longer warn about a missing SimplePie cache directory, and non-URL feed entries are skipped.

### Upgrade notes

- Git installs: after pulling this release you can switch your upgrade routine to `bin/upgrade`. Set `SITE_URL` in `.env` to your public URL to enable the post-upgrade health check.
- WebP conversion uses PHP's GD extension; without it, uploads are stored unchanged.
- If you run your own nginx/PHP config, raise `client_max_body_size` / `upload_max_filesize` / `post_max_size` to match the new 20M upload limit (see the [media docs](https://svandragt.github.io/lamb/media)).

## [0.9.0] - 2026-06-01

### 🆕 Since 0.9.0-rc1

Security hardening and a scheduling fix landed after the release candidate.

### Security

* Post titles are now escaped on output — closes a stored-XSS vector for titles coming from ingested feeds
* Uploads are restricted to an image-extension allowlist, so a crafted filename can no longer write `.php`/`.phtml` into the web root (SVG is excluded as it can carry scripts); also fixes a warning on extensionless uploads
* The post-login redirect is constrained to same-site paths, defeating open-redirect phishing
* CSRF tokens are generated with `random_bytes()`
* Remote feed titles are sanitised before being written to front matter, and feed ingestion now uses a fetch timeout so a slow or hostile feed can't stall the cron run

### Fixed

* Editing a scheduled post no longer reschedules it: a relative date (e.g. `created: next friday`) is now resolved **once** and rewritten to an absolute value on save, so later edits keep the post in place

---

### ✨ Scheduling (the 0.9.0 headline)

Publish posts in the future — they stay hidden until their time, then appear automatically. No cron job required.

* Give a post a future `created` date in front-matter to schedule it
* Flexible date formats: `2099-01-01 09:00:00`, `next friday 3pm`, `+1 week`, `tomorrow`, …
* A new **`timezone`** setting (in Settings) makes dates and scheduling use **your** local clock, even when the server runs in UTC
* A **Scheduled** view (and admin-toolbar count) lists upcoming posts; you can still preview one directly via its `/status/<id>` link
* **Micropub**: a future `published` date schedules a post, and `post-status: scheduled` is honoured

See the [Scheduling docs](https://svandragt.github.io/lamb/scheduling).

---

### 🎨 New "Notes" theme (2026)

A fresh default-quality theme with refined typography, motion and radius design tokens, an amber text/graphical split, plus accessibility, performance, responsive and dark-mode polish on the homepage and related-posts list.

<img width="1904" height="1910" alt="image" src="https://github.com/user-attachments/assets/2c5dc08d-5070-49d2-b8a7-86270fda11e7" />


---

### 📡 Feeds

* **JSON Feed** is now offered alongside Atom (`/feed.json`)
* Atom entry links now carry `rel="alternate"` and `type="text/html"`
* Author email removed from the Atom feed; author **URI** added instead
* Titleless entries use an empty title (micro.blog convention)
* Fixed feed-validation warnings (blank titles, invalid HTML entities)

---

### 🐛 Fixes

* Tag archives no longer drop posts where the tag is immediately followed by punctuation
* Fixed double-encoded HTML entities in post descriptions
* Search-highlight script relocated and covered by a test

---

### 🔧 Under the hood

* Dependency security patches and routine updates (RedBeanPHP, PHPStan, …)
* Dependabot auto-merge, a CI aggregator job for branch protection, and scoped Playwright runs

---

### ⬆️ Upgrade notes

* **`pdo_mysql` is now a required PHP extension.** RedBeanPHP 5.7 references a `pdo_mysql` constant at load time, so PHP without the extension fatals — even though Lamb only uses SQLite. The Docker image now installs it; minimal hand-rolled PHP installs must add `pdo_mysql`. DDev already bundles it.

---



## [0.8.0] - 2026-03-29

### Micropub (Post from Anywhere)

You can publish and manage posts on your site using compatible apps like Quill, iA Writer or other IndieWeb tools.

**Create posts with ease:**

* Write in plain text or rich text (HTML)
* Add tags (as hashtags)
* Upload photos or attach image URLs
* Set custom publish dates (including scheduling)
* Save drafts to finish later

**Edit or manage posts:**

* Update content anytime
* Add or remove tags
* Delete posts (with the option to restore them later)

**Media uploads:**

* Upload images and files directly through supported apps

**Works with your favorite tools:**

* Compatible with a wide range of Micropub clients
* Simple sign-in using IndieAuth
* Automatically connects to supported apps

---

### Trash & Recovery

Mistakes are recoverable.

* Deleted posts go to a **Trash** instead of being removed immediately
* You can restore posts at any time
* Items in Trash are permanently deleted after 30 days
* Admin view shows how many drafts and deleted posts you have

---

### Search

Find content quickly:

* Search highlights matching keywords
* Your search term stays in the box as you browse results

---

### Redirects

Moved or renamed a page?

* Set up automatic redirects so old links still work

---

### Reliability Improvements

General improvements to make the site more stable and predictable:

* Missing posts and empty pages now show proper “not found” pages
* Cleaner, more consistent page structure
* Dates display more clearly, including the year when needed
* Faster asset loading with improved caching

---

### Documentation

* Clear, user-friendly documentation available online
* Regularly updated with new features and guidance

---

### Behind the Scenes

Ongoing improvements ensure the platform stays fast, secure, and compatible with modern systems.


### Pull Requests
* 0.5.3 by @svandragt in https://github.com/svandragt/lamb/pull/127
* Redirect after login by @svandragt in https://github.com/svandragt/lamb/pull/180
* Sync by @svandragt in https://github.com/svandragt/lamb/pull/184
* Document theme system in CLAUDE.md by @svandragt in https://github.com/svandragt/lamb/pull/185
* Fix mobile display issues in both themes by @svandragt in https://github.com/svandragt/lamb/pull/187
* Allow slug in front-matter by @svandragt in https://github.com/svandragt/lamb/pull/188
* chore: upgrade GitHub Actions to latest versions for Node.js 24 compatibility by @svandragt in https://github.com/svandragt/lamb/pull/191
* Add `title_link` function for post titles with links, update themes, and add unit tests by @svandragt in https://github.com/svandragt/lamb/pull/189
* Add unit tests for previously uncovered functions by @svandragt in https://github.com/svandragt/lamb/pull/195
* Increase required test coverage threshold to 60% by @svandragt in https://github.com/svandragt/lamb/pull/196
* Improve handling and documentation of slugs, uploads, and assets by @svandragt in https://github.com/svandragt/lamb/pull/200
* Feat/php stan by @svandragt in https://github.com/svandragt/lamb/pull/197
* Fix feed attribution by @svandragt in https://github.com/svandragt/lamb/pull/194
* Migrate wiki to github pages by @svandragt in https://github.com/svandragt/lamb/pull/202
* Merge origin/main into issue-88-redirection-feature by @svandragt in https://github.com/svandragt/lamb/pull/206
* Fix PHPStan errors: implicit nullables, missing global, and baseline counts by @svandragt in https://github.com/svandragt/lamb/pull/207
* Add support for managing 301 redirects in config and database by @svandragt in https://github.com/svandragt/lamb/pull/192
* Persist search query in the search box across paginated results by @svandragt in https://github.com/svandragt/lamb/pull/193
* Fix related posts: restore visibility and improve quality by @svandragt in https://github.com/svandragt/lamb/pull/204
* Micropub by @svandragt in https://github.com/svandragt/lamb/pull/203
* Highlight search keywords on search results page by @svandragt in https://github.com/svandragt/lamb/pull/210
* Add year to human_time when the date is not in the current year by @svandragt in https://github.com/svandragt/lamb/pull/211
* Add and update docs for completeness by @svandragt in https://github.com/svandragt/lamb/pull/213
* support PHP 8.2-8.5 by @svandragt in https://github.com/svandragt/lamb/pull/214
* Switch docs to just-the-docs theme by @svandragt in https://github.com/svandragt/lamb/pull/215
* Refactor: extract SQL constants, consolidate upgrade_posts, and add can_act_on helper by @svandragt in https://github.com/svandragt/lamb/pull/216
* docker: fix empty vendor/ on host by using selective bind mounts by @svandragt in https://github.com/svandragt/lamb/pull/217
* chore: replace roave/security-advisories with native composer audit by @svandragt in https://github.com/svandragt/lamb/pull/218
* Fix styling robustness and uniformity issues in 2024 theme by @svandragt in https://github.com/svandragt/lamb/pull/212
* Improve mobile search bar layout in 2024 theme by @svandragt in https://github.com/svandragt/lamb/pull/219
* Increase unit test coverage to 70% by @svandragt in https://github.com/svandragt/lamb/pull/220
* Return 404 for tag pages with no matching posts by @svandragt in https://github.com/svandragt/lamb/pull/222
* Split CI into quality and test jobs by @svandragt in https://github.com/svandragt/lamb/pull/223
* Speed up Playwright CI by @svandragt in https://github.com/svandragt/lamb/pull/224
* Fix just-the-docs for GitHub Pages using remote_theme by @svandragt in https://github.com/svandragt/lamb/pull/225
* Fix tags in related posts by @svandragt in https://github.com/svandragt/lamb/pull/226
* Direnv support for devbox by @svandragt in https://github.com/svandragt/lamb/pull/227
* Fix related tags tests by @svandragt in https://github.com/svandragt/lamb/pull/228
* Allow q=config Micropub query without authentication by @svandragt in https://github.com/svandragt/lamb/pull/229
* 0.8.0 by @svandragt in https://github.com/svandragt/lamb/pull/230



## [0.7.0] - 2026-03-17

### What's Changed

  Features                                                                                                                                                                                  
   
  - Draft post support — posts can now be saved as drafts before publishing                                                                                                                 
  - Per-tag and home Atom feeds — dedicated Atom feeds for individual tags and the home feed (closes #31)
  - Preload entry form — the new post textarea can be pre-filled via a ?text= query string parameter                                                                                        
  - Feed ingestion as drafts — incoming feed items are now ingested as drafts by default                                                                                                    
  - Preconnect hints — <link rel="preconnect"> support for external origins to improve load times                                                                                           
  - Safari Reader View fix — compatibility with Safari's Reader View (closes #62)                                                                                                           
                                                                                                                                                                                            
  Testing & Infrastructure                                                                                                                                                                  
                                                                                                                                                                                            
  - Playwright end-to-end tests — full Playwright setup added for browser-level testing                                                                                                     
  - Significant unit test coverage expansion — tests added for config, lamb, post, theme, response, and network helpers
  - 40% code coverage threshold enforced in CI                                                                                                                                              
                                                                                                                                                                                            
  Performance & Bug Fixes                                                                                                                                                                   
                                                                                                                                                                                            
  - Fixed slow page loads — upgrade_posts() was writing on every request; now runs only when needed                                                                                         
  - Fixed periodic slow loads — session GC and redundant upgrade_posts calls optimised
  - Fixed NULL draft column hiding status posts                                                                                                                                             
  - Fixed draft post visibility and null handling in upgrade_posts                                                                                                                          
                                                                                                                                                                                            
  Developer Experience                                                                                                                                                                      
                                                                                                                                                                                            
  - CLAUDE.md added with comprehensive codebase documentation                                                                                                                               
  - Refactored network and feed response logic to be unit-testable
  - Feed updated time logic refactored with tests



## [0.6.0] - 2026-03-09

The goal for a new feature release for Lamb is 3-5 new features.

### What's Changed

1. **Web-Based Configuration Implementation** Developed a web-based settings page to replace the existing `config.ini`.
2. **Emoji Search Functionality** Enhanced the search and tag functionality to support emoji-based URLs.
3. **Fix Slug/Navigation Bug** Resolved the issue with slugs in the navigation menu.
4. **Pagination Feature**  Implemented pagination on the website to enhance content accessibility.
5. **Feed Styles Enhancement**. This feature is no longer planned, see #10.


## [0.5.4] - 2026-01-23

- The default pagination size was increased so lists show **10 posts per page** unless overridden by configuration.  
- Pagination was refactored into a single helper that can paginate either an in-memory list or a database query (including optional filtering + bound parameters), and callers were simplified to rely on it for reading the page number and per-page setting.  
- Result-building metadata was normalized (naming like `total_posts/total_pages`, offsets, prev/next page), reducing duplicated counting/offset logic in search and tag flows.  
- The UI pagination rendering was enhanced to show a condensed page range with **ellipses** and to style the current page consistently with links.  
- There were also routine dependency/version updates plus small formatting/lint cleanups.

### What's Changed
* Maintenance release by @svandragt in https://github.com/svandragt/lamb/pull/110
* Bump squizlabs/php_codesniffer from 3.12.0 to 3.12.1 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/124
* Bump squizlabs/php_codesniffer from 3.12.1 to 3.12.2 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/125
* Bump symfony/yaml from 7.2.5 to 7.2.6 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/130
* Bump symfony/yaml from 7.2.6 to 7.3.2 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/141
* Bump squizlabs/php_codesniffer from 3.12.2 to 3.13.2 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/139
* Bump gabordemooij/redbean from 5.7.4 to 5.7.5 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/137
* Bump codeception/codeception from 5.2.1 to 5.3.2 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/135
* Bump codeception/module-asserts from 3.1.0 to 3.2.0 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/133
* Bump squizlabs/php_codesniffer from 3.13.2 to 3.13.4 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/145
* Bump simplepie/simplepie from 1.8.1 to 1.9.0 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/146
* Bump codeception/module-phpbrowser from 3.0.1 to 3.0.2 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/144
* Bump codeception/module-asserts from 3.2.0 to 3.2.1 by @dependabot[bot] in https://github.com/svandragt/lamb/pull/148



## [0.5.3] - 2025-04-25

### Features
- **Support for Meta Description**: Added functionality to include meta descriptions for better SEO and content representation.
- **Enhanced Metadata Semantics**: Improved metadata representation with semantic elements like `itemprop` and `<time>`, enhancing accessibility and readability.
- **Dynamic Title Rendering**: Introduced `site_or_page_title` function to manage site and page titles dynamically, improving title handling across the platform.
- **Improved Feed Styles**: Added new styles for feeds in the 2024 theme, including design updates and SVG integration for background images.
- **Updated Author Meta Tag**: Replaced `site_author` with `author_name` for better handling of optional config fields in meta tags.

### Design and Layout Enhancements
- **Refined Page Title Logic**: Updated logic to use a global site title as a fallback, ensuring consistent page title handling.
- **Updated H1 Styles**: Adjusted margins and padding for `h1` elements in the 2024 theme to enhance visual consistency.
- **Layout Improvements**: Refactored post rendering with list wrapping for multiple posts, improved header structure, and refined meta information in article layouts.
- **Accessibility Improvements**: Removed opacity for better accessibility and ensured visual consistency without layout shifts by modifying border properties.

### Security Enhancements (0.5.2)
- **Secure Session Management**: Implemented UUID-based secure cookies for login sessions and configured a custom session name to strengthen session management and security.

### Dependency Updates
- **PHP CodeSniffer**: Updated `squizlabs/php_codesniffer` from 3.12.1 to 3.12.2, ensuring adherence to coding standards.
- **Symfony YAML**: Bumped `symfony/yaml` from 7.2.3 to 7.2.5 for improved functionality and security.

### Miscellaneous
- **Refactored Initialization Logic**: Separated database and session initialization into a dedicated bootstrap file for improved modularity.
- **Improved Error Handling**: Enhanced error handling mechanisms across the codebase for better user feedback and robustness.
- **Code Formatting**: Aligned indentation and formatting across PHP, JS, and CSS files for consistent coding standards.



## [0.5.2] - 2025-03-31

Tidy up release, minor functional changes:

- [Menu items are no longer showing in timeline](https://github.com/svandragt/lamb/commit/2ee136e94df12bc5ebadfe1d677390196e5baac4)
- [More secure sessions](https://github.com/svandragt/lamb/commit/12e966db9a1f01d2acd33376a3d2a5c976541473) 
- [Deferring scripts](https://github.com/svandragt/lamb/commit/064938123780f3e3f0c32cd2eae104cd261b7224) for improved performance
- [Lowercase tag pages](https://github.com/svandragt/lamb/commit/6738c7d0287906a298cbe79c21921a81d4e14897) for canonical SEO
- [Add 'published' date to Atom feed entries](https://github.com/svandragt/lamb/commit/4b8251c1848a510f57fa55648355d569037d87a2) for better aggregation
- Security updates.

Apologies for the different format, the generated release notes were comparing against 0.5.0


## [0.5.1] - 2025-02-21

### What's Changed
* Create FUNDING.yml by @svandragt in https://github.com/svandragt/lamb/pull/97
* Bump symfony/yaml from 7.1.5 to 7.1.6 by @dependabot in https://github.com/svandragt/lamb/pull/99
* Bump squizlabs/php_codesniffer from 3.10.3 to 3.11.1 by @dependabot in https://github.com/svandragt/lamb/pull/100
* Bump simplepie/simplepie from 1.8.0 to 1.8.1 by @dependabot in https://github.com/svandragt/lamb/pull/101
* Bump symfony/yaml from 7.1.6 to 7.2.0 by @dependabot in https://github.com/svandragt/lamb/pull/102
* Bump squizlabs/php_codesniffer from 3.11.1 to 3.11.2 by @dependabot in https://github.com/svandragt/lamb/pull/103
* Bump symfony/yaml from 7.2.0 to 7.2.3 by @dependabot in https://github.com/svandragt/lamb/pull/105
* Bump squizlabs/php_codesniffer from 3.11.2 to 3.11.3 by @dependabot in https://github.com/svandragt/lamb/pull/104
* Bump codeception/codeception from 5.1.2 to 5.2.1 by @dependabot in https://github.com/svandragt/lamb/pull/109
* Maintenance release by @svandragt in https://github.com/svandragt/lamb/pull/110

Known issues:
* Growing textarea isn't growing with user input, fixed for next release.


## [0.5.0] - 2024-10-18

New in this release:

1.  Crossposting from feeds.
2. Support for user themes.
3. A new retro 2024 theme.
4. BREAKING: Lamb now uses PHP 8.2.
5. BREAKING: config.ini's [menu_items] items are now in `label=slug` format.
6. BREAKING: the `make_password_hash.php` file is now `make-password.php`. If you run lamb with DDEV, running this script saves the login password on your behalf, making it zero conf.

### What's Changed
* Various by @svandragt in https://github.com/svandragt/lamb/pull/45
* Code analysis by @svandragt in https://github.com/svandragt/lamb/pull/49
* Update qodana config by @svandragt in https://github.com/svandragt/lamb/pull/50
* Bump symfony/yaml from 7.0.3 to 7.1.0 by @dependabot in https://github.com/svandragt/lamb/pull/53
* Bump symfony/yaml from 7.1.0 to 7.1.1 by @dependabot in https://github.com/svandragt/lamb/pull/56
* Remove CodeQL maintenance burden by @svandragt in https://github.com/svandragt/lamb/pull/58
* PHP CodeSniffer by @svandragt in https://github.com/svandragt/lamb/pull/63
* Bump symfony/yaml from 7.1.1 to 7.1.4 by @dependabot in https://github.com/svandragt/lamb/pull/67
* Codeception by @svandragt in https://github.com/svandragt/lamb/pull/70
* DDEV local development by @svandragt in https://github.com/svandragt/lamb/pull/66
* Autoload helpers by @svandragt in https://github.com/svandragt/lamb/pull/72
* CI Upgrade node by @svandragt in https://github.com/svandragt/lamb/pull/74
* GPL3 by @svandragt in https://github.com/svandragt/lamb/pull/75
* Bump symfony/yaml from 7.1.4 to 7.1.5 by @dependabot in https://github.com/svandragt/lamb/pull/77
* #13 2024 theme & User Themes by @svandragt in https://github.com/svandragt/lamb/pull/57
* #55 PHP 8.2 upgrade by @svandragt in https://github.com/svandragt/lamb/pull/81
* Exclude user themes from git by @svandragt in https://github.com/svandragt/lamb/pull/83
* Reapplying the flock branch by @svandragt in https://github.com/svandragt/lamb/pull/86
* Generate the lamb admin password files and codeception files by @svandragt in https://github.com/svandragt/lamb/pull/91
* Set the created and updated date from the feed item by @svandragt in https://github.com/svandragt/lamb/pull/93
* Update readme.md by @svandragt in https://github.com/svandragt/lamb/pull/94
* Release 0.5.0 by @svandragt in https://github.com/svandragt/lamb/pull/95



## [0.4.0] - 2024-04-12

### What's Changed
* Image upload support! by @svandragt in https://github.com/svandragt/lamb/pull/34
* PHP8.2 +  devbox support by @svandragt in https://github.com/svandragt/lamb/pull/46
* Bump symfony/yaml from 6.4.3 to 7.0.3 by @dependabot in https://github.com/svandragt/lamb/pull/48

### New Contributors
* @dependabot made their first contribution in https://github.com/svandragt/lamb/pull/48


## [0.3.0] - 2023-07-28

### What's Changed
* Docker support by @svandragt in https://github.com/svandragt/lamb/pull/1
* Added yaml PHP module requirement
* Documentation added to /docs
* config.ini to customize your installation
* Styling updates for the default theme
* Reorganised code
* Menu items are no longer shown in the timeline
* Feed compatibility fixes for posts without slugs
* Feed items without title no longer show the site title
* Twitter and OpenGraph fixes for title metadata showing only if the item has a title
* Menu items are shown in the top navigation bar
* Login/logout link has moved to the right hand side on desktop


## [0.2.0] - 2023-03-24

- Posts! Posts are statuses with a title. The title can be added in the front matter (front matter is parsed as an ini-string)
- Posts have a slug based on the title when the post was created.
- Individual statuses / posts have opengraph tags for improved sharing fidelity.
- Fixed several XSS, thanks @cameronterry
- Added a Content Security Policy so that external scripts and styles cannot be loaded. 
- Accessibility improvements
- The textarea grows on typing to accommodate the full text.
- The login password must now be stored as a hash. a `make_password_hash.php` script has been provided together with full instructions.
- 404 endpoint
- Where a title is expected and a status does not provide one, the site title is used.
- Extended nginx and caddy configuration
- Style and script loaders
- Dependency updates


## [0.1.0] - 2023-03-20

Initial release!


[Unreleased]: https://github.com/svandragt/lamb/compare/0.14.0...HEAD
[0.14.0]: https://github.com/svandragt/lamb/compare/0.13.0...0.14.0
[0.13.0]: https://github.com/svandragt/lamb/compare/0.12.0...0.13.0
[0.12.0]: https://github.com/svandragt/lamb/compare/0.11.0...0.12.0
[0.11.0]: https://github.com/svandragt/lamb/compare/0.10.0...0.11.0
[0.10.0]: https://github.com/svandragt/lamb/compare/0.9.0...0.10.0
[0.9.0]: https://github.com/svandragt/lamb/compare/0.8.0...0.9.0
[0.8.0]: https://github.com/svandragt/lamb/compare/0.7.0...0.8.0
[0.7.0]: https://github.com/svandragt/lamb/compare/0.6.0...0.7.0
[0.6.0]: https://github.com/svandragt/lamb/compare/0.5.4...0.6.0
[0.5.4]: https://github.com/svandragt/lamb/compare/0.5.3...0.5.4
[0.5.3]: https://github.com/svandragt/lamb/compare/0.5.2...0.5.3
[0.5.2]: https://github.com/svandragt/lamb/compare/0.5.1...0.5.2
[0.5.1]: https://github.com/svandragt/lamb/compare/0.5.0...0.5.1
[0.5.0]: https://github.com/svandragt/lamb/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/svandragt/lamb/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/svandragt/lamb/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/svandragt/lamb/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/svandragt/lamb/releases/tag/0.1.0

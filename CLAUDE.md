# CLAUDE.md — Lamb Codebase Guide

Lamb is a self-hosted, single-author microblog. It uses PHP 8.2+, SQLite (via RedBeanPHP ORM), and a procedural-with-namespaces architecture. There is no MVC framework — routing, responses, and views are handled by small namespaced PHP files.

## Documentation (End-User)

The end-user documentation lives in `docs/` (tracked in the repository, served via GitHub Pages). It is the end-user manual. When working on user-facing features:

- Check whether a docs page exists for the feature and update it if needed.
- When adding new user-facing behaviour, consider whether a new docs page is warranted.
- Ensure docs pages that are topically related link to each other via a "Related" section.

GitHub Pages publishes the `release` branch's `docs/` folder, so the live site always matches the latest released version. Docs changes merged to `main` go live when `main` is merged into `release`. Preview the in-development docs locally with `make docs` (serves at http://localhost:4000/lamb/).

## Key Commands

```bash
# Install dependencies
composer install

# Start dev server (PHP built-in, port 8747)
composer serve

# Lint (PSR-2/PSR-12 + PHPCompatibility)
composer lint

# Run all tests
vendor/bin/codecept run

# Run only unit tests
vendor/bin/codecept run Unit

# Run acceptance tests (requires SITE_URL in .env)
vendor/bin/codecept run Acceptance

# Run client-side JavaScript unit tests (node:test + jsdom)
pnpm test

# Generate password hash and write .env (warns on STDERR if the password is weak).
# By default the cleartext password is NOT written to .env; the acceptance suite
# opts in via LAMB_WRITE_TEST_PASSWORD=1 to get LAMB_TEST_PASSWORD.
php make-password.php <your-password>
LAMB_WRITE_TEST_PASSWORD=1 php make-password.php <your-password>   # for acceptance tests

# Static analysis
composer analyse

# Auto-fix coding standard violations
composer fix

# Install pre-commit hook (one-time, after cloning)
printf '#!/bin/sh\nset -e\ncomposer lint\ncomposer analyse\n' > .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit

# Take screenshots at mobile/tablet/desktop (requires composer serve to be running)
PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=$HOME/.cache/ms-playwright/chromium-1208/chrome-linux64/chrome \
  pnpm run screenshot [/path] [outdir]
# Before/after: git stash → screenshot → git stash pop → screenshot
```

### Screenshot notes
- JS deps: `pnpm install` (not npm); Playwright is `@playwright/test`
- The PHP dev server must be started with `php -S 0.0.0.0:8747 -t src` (no router script argument)
- Chromium executable: `~/.cache/ms-playwright/chromium-1208/chrome-linux64/chrome` (note `chrome-linux64`; version dir may differ per machine — check `ls ~/.cache/ms-playwright/`)
- Script: `scripts/screenshot.mjs [path] [outdir]`

## Project Structure

```
lamb/
├── src/                  # Application source (web root)
│   ├── index.php         # Entry point: bootstrap, routing, view dispatch
│   ├── bootstrap.php     # DB init (SQLite via RedBean) + session setup
│   ├── config.php        # INI-based config stored in DB; load/save/validate
│   ├── constants.php     # Static app-wide constants (POST_VERSION, SESSION_LOGIN, …)
│   ├── routes.php        # register_route() / call_route() helpers
│   ├── lamb.php          # Core helpers: parse_bean, parse_tags, permalink, visibility clauses, redirects
│   ├── post.php          # Post helpers: populate_bean, parse_matter, slugify, finalize_slug
│   ├── response.php      # Response helpers: pagination, conditional GET/304, 404, upgrade_posts
│   ├── response/         # Route handlers (respond_*, redirect_*), split by area
│   │   ├── auth.php      # Login/logout/settings
│   │   ├── feeds.php     # Home, drafts/trash/scheduled, search, tag, Atom + JSON feeds
│   │   ├── posts.php     # Status/slug pages, create/edit/delete/restore
│   │   └── upload.php    # Image upload + WebP conversion
│   ├── security.php      # require_login(), require_csrf()
│   ├── theme.php         # Template helpers, part(); more helpers in theme/
│   ├── theme/            # Theme helper split: assets.php (styles/scripts), formatting.php (escape, human_time), meta.php (opengraph, titles)
│   ├── network.php       # Feed ingestion via SimplePie (_cron route)
│   ├── http.php          # get_request_uri(); shared HTTP client: fetch(), parse_status_line()
│   ├── micropub.php      # Micropub endpoint (taproot/micropub-adapter subclass) + media endpoint
│   ├── webmention.php    # Webmention receiving + outbound sending queue (via /_cron)
│   ├── websub.php        # WebSub hub pings on publish
│   ├── highlight.php     # Syntax highlighting of fenced code blocks (Phiki)
│   ├── LambDown.php      # Parsedown subclass (restricts heading levels)
│   ├── assets/           # Runtime upload storage (created under YYYY/MM)
│   ├── images/           # Shipped static images (default social embed image)
│   ├── themes/
│   │   ├── base/         # Base theme + fallback library (HTML, parts, feeds, CSS)
│   │   ├── 2024/         # Alternative theme (overrides parts as needed)
│   │   └── 2026/         # "Notes" theme — default for new installs
│   └── scripts/          # JS: shorthand.js + logged_in/ (admin-only)
├── tests/
│   ├── Unit/             # PHPUnit tests (ConfigTest.php)
│   ├── Acceptance/       # Codeception browser tests
│   ├── Functional/       # Codeception functional tests
│   └── Support/          # Codeception support files
├── composer.json
├── phpcs.xml             # Coding standard config
├── codeception.yml       # Test runner config
└── make-password.php     # CLI utility: hash password → .env
```

## Architecture Overview

### Entry Point & Routing (`src/index.php`)

`index.php` is the single entry point (PHP built-in server or web server rewrite). It:
1. Calls `bootstrap_db('../data')` to set up SQLite at `../data/lamb.db`
2. Calls `bootstrap_session()` to configure a hardened session
3. Loads config via `Config\load()`
4. Defines constants: `ROOT_DIR`, `ROOT_URL`, `THEME`, `THEME_DIR`, etc.
5. Registers routes using `Route\register_route($action, $callback, ...$args)`
6. Dispatches via `Route\call_route($action)` where `$action` is the first path segment
7. Falls through to `Theme\part('html', '')` to render the outer shell

Routes are plain strings (`'home'`, `'feed'`, `'tag'`, etc.). Slugged posts get a dynamic route registered when their slug matches the current action.

### Namespaces

Each file declares a namespace; functions are called with the namespace prefix:

| File | Namespace |
|------|-----------|
| `bootstrap.php` | `Lamb\Bootstrap` |
| `config.php` | `Lamb\Config` |
| `highlight.php` | `Lamb\Highlight` |
| `http.php` | `Lamb\Http` |
| `lamb.php` | `Lamb` |
| `micropub.php` | `Lamb\Micropub` |
| `network.php` | `Lamb\Network` |
| `post.php` | `Lamb\Post` |
| `response.php` + `response/*.php` | `Lamb\Response` |
| `routes.php` | `Lamb\Route` |
| `security.php` | `Lamb\Security` |
| `theme.php` + `theme/*.php` | `Lamb\Theme` |
| `webmention.php` | `Lamb\Webmention` |
| `websub.php` | `Lamb\Websub` |

`response/` and `theme/` are namespace splits, not new namespaces: each file inside declares the parent's namespace, so callers don't care which file a function lives in.

### Database

RedBeanPHP (fluid mode) on SQLite. Beans are dispensed/loaded with `R::dispense`, `R::load`, `R::findOne`, `R::find`, `R::findAll`. Schema evolves automatically.

**Tables used:**
- `post` — blog posts; columns include `body`, `slug`, `title`, `description`, `transformed`, `created`, `updated`, `version`, `feed_name`, `feeditem_uuid`, `source_url`
- `option` — key/value store (e.g. `site_config_ini`, `last_processed_date`)
- `redirect` — automatic 301 redirects created when a post slug changes; columns: `from_slug`, `to_url`
- `webmention` — received (inbound) webmentions; columns: `source`, `target`, `post_id`, `type`, `author`, `content`, `status`, `created`, `verified_at`
- `webmentionoutbox` — outbound webmention queue processed by `/_cron`; columns: `post_id`, `source`, `target`, `endpoint`, `status`, `attempts`, `created`, `processed_at`

**Post versioning:** startup bootstrapping in `bootstrap_db()` stamps legacy rows with `version = 1` via SQL (`UPDATE post SET version = 1 WHERE version IS NULL`). `upgrade_posts()` in `response.php` is still called on read paths, but it now mainly acts as a safety net for unexpected old rows loaded into memory rather than the primary migration mechanism.

### Configuration

Config is stored as raw INI text in the `option` table under key `site_config_ini`. On first run it bootstraps from `config.ini` (if present) or uses built-in defaults. Edit it at `/settings` (login required).

Config keys: `author_email`, `author_name`, `site_title`, `theme`, `404_fallback`, `posts_per_page`, `[menu_items]`, `[feeds]`, `feeds_draft`, `[preconnect]`, `[redirections]`.

### Post Content Format

Post bodies are Markdown with optional YAML front matter separated by `---`:

```
---
title: My Post Title
---

Post content here. Use #hashtags inline.
```

`parse_matter()` extracts YAML and normalises keys before matching (`normalize_matter_keys()`: lower-cased, underscores → dashes), so `Title`/`title` and `in_reply_to`/`in-reply-to` collapse onto canonical keys — this smooths over mobile auto-capitalisation and the underscore/dash ambiguity. If `title` is present and `slug` is absent, it derives `slug` from `title` via `slugify()`. If `slug` is explicitly present in front matter, that value is used.
`parse_bean()` runs Markdown → HTML, extracts tags, stores `transformed`, `description`, and front-matter-derived fields on the bean.
`LambDown` extends Parsedown with safe mode on and restricts `#` headings (must be `# ` with a space).

On `main`/`release`, slugs are effectively immutable after creation: editing a post will not overwrite an existing slug from changed front matter or title. New page-like posts get their slug at creation time; status posts keep the numeric `/status/<id>` permalink.

### Theming

`Theme\part($name, $dir)` includes `THEME_DIR/$dir/$name.php`, falling back to `src/themes/base/`. Custom themes only need to override specific parts.

**Theme parts (base):**
- `html.php` — outer HTML shell (includes `parts/home.php`, etc.)
- `feed.php` — Atom feed output
- `feed_json.php` — JSON Feed output
- `parts/home.php`, `status.php`, `edit.php`, `search.php`, `tag.php`, `login.php`, `settings.php`, `404.php`, `drafts.php`, `scheduled.php`, `trash.php`
- `parts/_items.php` — post list partial
- `parts/_pagination.php` — pagination partial
- `parts/_related.php` — related posts partial
- `parts/_webmentions.php` — received-webmentions partial (status pages)

---

## Theme System — Complete Reference

### How themes are selected

`index.php` reads `$config['theme']` (set via the INI config at `/settings`) and resolves it through `Config\resolve_theme()`, which falls back to `'base'` when unset and aliases the legacy name `'default'` to `'base'`:

```php
define("THEME",     Config\resolve_theme($config['theme'] ?? null));
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');   // absolute FS path
define("THEME_URL", 'themes/' . THEME . '/');               // URL prefix (relative)
```

`ROOT_DIR` is `src/`. So `THEME_DIR` for a theme named `news` resolves to `src/themes/news/`.

To activate a theme add `theme = news` to the INI config in the DB (edit at `/settings`).

New installs are seeded with `theme = 2026` (the "Notes" theme) via `Config\get_default_ini_text()`. Existing installs without an explicit theme are migrated to `theme = base` on first read (`Config\ensure_explicit_theme()` in `get_ini_text()`), so the `'base'` fallback and the `default`→`base` alias can be removed once all installs carry an explicit theme.

### Part resolution (`Theme\part`)

```php
Theme\part($name, $dir = 'parts')
```

1. Looks for `THEME_DIR . $dir . '/' . $name . '.php'`
2. Falls back to `src/themes/base/$dir/$name.php`
3. Throws `RuntimeException` if neither exists

`$name` and `$dir` are sanitised (only `[a-zA-Z0-9-_]` allowed — dots and slashes are stripped).

`html.php` is the only file called with an empty `$dir`:

```php
Theme\part('html', '');   // → src/themes/<theme>/html.php
```

Everything else is called as `part($template)` from inside `html.php`, which uses the default `$dir = 'parts'`.

### Render flow

```
index.php
 └─ Theme\part('html', '')          → html.php
     ├─ Theme\part($template)        → parts/<template>.php  (home / status / tag / …)
     │   └─ Theme\part('_items')     → parts/_items.php
     ├─ Theme\part('_related')       → parts/_related.php    (status only)
     └─ Theme\part('_pagination')    → parts/_pagination.php
```

`$template` equals the first URL segment (`home`, `status`, `tag`, `search`, `edit`, `login`, `settings`, `404`, `drafts`). For a slugged post URL it is set to `'status'`.

### Global variables available in every part

| Variable | Type | Contents |
|----------|------|----------|
| `$config` | `array` | Full config: `site_title`, `author_name`, `author_email`, `menu_items`, `feeds`, `theme`, … |
| `$data` | `array` | Route-specific data (see table below) |
| `$template` | `string` | Current template name (`home`, `status`, `tag`, …) |

### `$data` keys by template

| Template | Keys always present | Keys sometimes present |
|----------|--------------------|-----------------------|
| `home` | `posts`, `pagination`, `title` | — |
| `status` / slugged post | `posts` (single-item array), `title` | — |
| `tag` | `posts`, `pagination`, `title`, `intro`, `feed_url` | — |
| `search` | `posts`, `pagination`, `title`, `intro` | — |
| `drafts` | `posts`, `pagination`, `title` | — |
| `settings` | — | `ini_text` (on failed save) |
| `404` | `action` | — |

**`$data['posts']`** is an array of RedBeanPHP `OODBBean` objects. Each bean exposes:

| Property | Description |
|----------|-------------|
| `$bean->id` | Integer primary key |
| `$bean->title` | Post title (may be empty for status posts) |
| `$bean->slug` | URL slug for page-style posts; created from front matter/title on first save and preserved on later edits on `main`/`release` |
| `$bean->body` | Raw Markdown source |
| `$bean->transformed` | Pre-rendered HTML (use this in templates — never re-render `body`) |
| `$bean->description` | Plain-text excerpt (auto-generated) |
| `$bean->created` | Datetime string (e.g. `2024-03-01 12:00:00`) |
| `$bean->updated` | Datetime string |
| `$bean->feed_name` | Source feed name (only present for ingested feed items) |
| `$bean->feeditem_uuid` | MD5 dedup key (only for feed items) |
| `$bean->source_url` | Permalink of the original feed item (only for feed items; used by `link_source()`) |
| `$bean->is_menu_item` | Truthy if the post is pinned as a menu item |

**`$data['pagination']`** shape (when present):

```php
[
  'current'     => int,    // current page number
  'per_page'    => int,    // posts per page
  'total_posts' => int,
  'total_pages' => int,
  'prev_page'   => int|null,
  'next_page'   => int|null,
  'offset'      => int,
]
```

### Theme helper functions (`Lamb\Theme` namespace)

All helpers must be imported with `use function Lamb\Theme\<name>` before use.

| Function | Returns | Description |
|----------|---------|-------------|
| `escape($str)` | `string` | `htmlspecialchars` for HTML5 output — use on every user-supplied value |
| `site_title($type='html')` | `string` | `<h1>` wrapping `$config['site_title']`, or plain text if `$type !== 'html'` |
| `page_title($type='html')` | `string` | `<h1>` wrapping `$data['title']` (falls back to `site_title`) |
| `site_or_page_title($type)` | `string` | Page title if set, otherwise site title |
| `page_intro()` | `string` | `<p>` wrapping `$data['intro']`, or `''` |
| `li_menu_items()` | `string` | `<li><a>` tags from `$config['menu_items']` |
| `date_created($bean)` | `string` | `<a><time>` linking to the post permalink with human-readable timestamp |
| `link_source($bean)` | `string` | "Via <a>" attribution link for feed-ingested posts, or `''`. Uses `$bean->source_url` when set, falling back to the feed URL from config |
| `action_edit($bean)` | `string` | Edit button (logged-in only), or `''` |
| `action_delete($bean)` | `string` | Delete form (logged-in only), or `''` |
| `the_entry_form()` | `void` | Renders the quick-post `<form>` (logged-in only) |
| `the_styles()` | `void` | Emits `<link rel="stylesheet">` for `styles/styles.css` in the active theme |
| `the_scripts()` | `void` | Emits `<script defer>` tags for application scripts in `src/scripts/`; logged-in users also get `src/scripts/logged_in/*.js` |
| `the_opengraph()` | `void` | Emits `<meta>` OG/Twitter tags (status template only) |
| `the_preconnect()` | `void` | Emits `<link rel="preconnect">` for `$config['preconnect']` origins |
| `part($name, $dir='parts')` | `void` | Includes a theme part (see resolution rules above) |
| `csrf_token()` | `string` | Returns (and creates if needed) the current session CSRF token |
| `related_posts($body)` | `array` | Posts sharing hashtags with `$body`; returns `['posts' => OODBBean[]]` |
| `human_time($timestamp)` | `string` | Human-readable relative time ("3 hours ago", "Monday at 2:15 pm", …) |
| `redirect_to()` | `string` | Sanitised `?redirect_to=` query param value |

Additional helper from `Lamb\Config`:

| Function | Returns | Description |
|----------|---------|-------------|
| `is_menu_item($slugOrId)` | `bool` | True if the value matches a configured menu-item slug/id — use to hide menu posts from feed lists |

### Constants available in parts

| Constant | Value / Description |
|----------|---------------------|
| `ROOT_URL` | Full base URL, e.g. `https://example.com` |
| `ROOT_DIR` | Absolute path to `src/` |
| `THEME` | Active theme name string |
| `THEME_DIR` | Absolute path to active theme directory (trailing `/`) |
| `THEME_URL` | Relative URL to active theme directory, e.g. `themes/news/` |
| `SESSION_LOGIN` | Session key `'logged_in'` — check `isset($_SESSION[SESSION_LOGIN])` for auth |
| `HIDDEN_CSRF_NAME` | CSRF field name `'csrf'` |
| `SUBMIT_CREATE` | Submit button label `'Create post'` |
| `SUBMIT_EDIT` | Submit button label `'Update post'` |

### CSS asset loading

`the_styles()` takes no arguments and always loads `styles/styles.css` from the active theme. There is no multi-file or concatenation mechanism — put everything in one CSS file per theme.

`Theme\styles_markup()` decides how it is served. When the minified stylesheet is ≤ 20 KB it is **inlined** as a `<style>` tag (minified via `Theme\minify_css()`, with relative `url()` font references rewritten to absolute via `Theme\rewrite_css_urls()`) so first paint isn't blocked on a separate CSS request — the main mobile-PageSpeed win for a single-file theme. Larger or unreadable stylesheets fall back to an external `<link rel="stylesheet">` whose URL is `THEME_URL . 'styles/styles.css'` with a content-hash cache-buster (`?ver=<md5>`). The two preloaded fonts in `html.php` keep their absolute URLs, which match the rewritten `url()` targets, so inlining does not double-fetch them.

### JavaScript asset loading

`the_scripts()` takes no arguments. It does not load scripts from the active theme directory.

It always loads:

- `src/scripts/shorthand.js`

When the user is logged in, it also loads:

- `src/scripts/logged_in/growing-input.js`
- `src/scripts/logged_in/confirm-delete.js`
- `src/scripts/logged_in/link-edit-buttons.js`
- `src/scripts/logged_in/upload-image.js`
- `src/scripts/logged_in/paste-link.js`

### Upload asset storage

User-uploaded files live under `src/assets/`, not under theme directories.

`respond_upload()` stores files in `src/assets/YYYY/MM/` and returns Markdown image links pointing at those uploaded files. JPEG/PNG uploads are re-encoded to WebP via GD (`Response\convert_to_webp()`, gated by `Response\should_convert_to_webp()`) and downscaled so their longest edge is at most 1600px (`Response\scaled_dimensions()`) — large screenshots are not served at full resolution to phones; GIF/WebP/AVIF are stored unchanged, and any conversion failure falls back to storing the original bytes. The same conversion is applied to Micropub uploads (inline `photo` files and the media endpoint) in `micropub.php`. Deployment setups that support uploads must ensure `src/assets/` is writable by the web server or PHP-FPM user.

### `.gitignore` exemption

Unknown subdirectories under `src/themes/` are ignored so users can install a custom theme alongside their clone of the repo without it appearing in `git status`. The shipped themes (`base`, `2024`, `2026`) are explicitly un-ignored at the directory level, so `git add` on their files works normally without `-f`.

To ship a new theme as part of the repo, add one line to `.gitignore`:

```
# in .gitignore
!src/themes/news/
```

After that, `git add` works normally for all files inside the theme.

### Minimal new theme checklist

A theme only needs to override the files that differ from `base`. The absolute minimum is a stylesheet:

```
src/themes/<name>/
└── styles/
    └── styles.css     ← required (the_styles() always loads this path)
```

Add `html.php` only if the HTML shell (nav, header, footer) changes. Add `feed.php` only if the Atom output differs from the base feed template. Add individual `parts/*.php` files only for the page templates that differ visually. All other parts fall back to `base` automatically.

### Typical file set (for a full redesign)

```
src/themes/<name>/
├── html.php                  # header, nav, outer shell, footer
├── styles/
│   └── styles.css
└── parts/
    ├── home.php              # homepage (calls the_entry_form, site_title, part('_items'))
    ├── _items.php            # post list / card grid
    ├── status.php            # single post (usually just delegates to _items)
    ├── tag.php               # tag archive
    └── search.php            # search results
```

Parts you rarely need to override: `edit.php`, `login.php`, `settings.php`, `404.php`, `drafts.php`, `feed.php`, `_related.php`, `_pagination.php`.

### Security

- CSRF: token stored in session, verified in `Security\require_csrf()`, consumed after use
- Session hardening: `httponly`, `secure` (HTTPS), `SameSite=Strict`, user-agent validation
- Auth: password stored as bcrypt hash in env var `LAMB_LOGIN_PASSWORD` (base64-encoded)
- Login sets `$_SESSION[SESSION_LOGIN]` and a `lamb_logged_in` cookie; logout destroys the session and expires both cookies
- Sessions are only started for (previously) logged-in users: `Bootstrap\should_start_session()` checks for the `lamb_logged_in` or `LAMBSESSID` cookie, so anonymous visitors get no `Set-Cookie` and no no-cache headers and their pages stay cacheable. Routes that need a session for an otherwise-anonymous request (the login page, CSRF POSTs, flash-before-redirect) call `Bootstrap\start_session()` explicitly. `Bootstrap\cache_headers()` emits `max-age=300` + `Vary: Cookie` for anonymous responses and private/no-store for logged-in ones (session cache limiter is disabled so it never fights these)
- Conditional GET: anonymous content pages and feeds carry `ETag`/`Last-Modified` validators derived from the most recently updated post **and the last config edit** (`Response\latest_content_timestamp()` takes the `max()` of the latest post `updated` and `Config\config_modified_timestamp()`), and `Response\send_304_if_current()` short-circuits with `304 Not Modified`. `Last-Modified` is the second-resolution `max()` of the two timestamps, but the `ETag` (`Bootstrap\content_etag()`) keeps them as **distinct components** (`"<content>-<config>"`) so a settings edit that lands in the same whole second as the latest post still changes the validator and invalidates caches. The login page and 404 responses are excluded. Feeds use a longer `max-age` (`Response\feed_cache()`). `Config\save_ini_text()` stamps `updated` on the config row so settings changes invalidate cached pages immediately
- Admin-only JS files are conditionally loaded via `asset_loader()`. Asset URLs are cache-busted with a content hash (`Theme\asset_version()` → `md5_file`), so editing a CSS/JS file invalidates the `?ver=` query

### Feed Ingestion (Cron)

`GET /_cron` triggers `Network\process_feeds()`. It reads `[feeds]` from config, fetches each RSS/Atom feed via SimplePie, and creates/updates posts. Rate-limited: minimum 1 minute between full runs, 30 minutes per feed. Feed items get a `feeditem_uuid` (md5 of feed name + item ID) for deduplication. The same run also drains the outbound webmention queue via `Webmention\process_outbound()`.

### IndieWeb Endpoints

- `/micropub` + `/micropub-media` (`micropub.php`) — Micropub create/update/delete/undelete and media uploads, built on `taproot/micropub-adapter` (`LambMicropubAdapter`); bearer tokens are introspected against the configured `token_endpoint`
- `/webmention` (`webmention.php`) — receives and verifies inbound webmentions (stored in the `webmention` table, rendered by `parts/_webmentions.php`); publishing/editing a post enqueues outbound webmentions in `webmentionoutbox`, sent by `/_cron`
- WebSub (`websub.php`) — pings the `websub_hubs` configured hubs when a post publishes
- `index.php` advertises `micropub`, `webmention`, `authorization_endpoint` and `token_endpoint` via `Link` headers

### Pagination

`paginate_posts($source, ...)` in `response.php` handles both array-based and DB-based pagination. It reads `posts_per_page` from config (default 10) and `?page=` from query string.

## Coding Standards

- **PSR-12** with PHPCompatibility checks for PHP 8.2+
- Line length limit disabled (`Generic.Files.LineLength.TooLong` excluded)
- Side effects with symbols allowed (`PSR1.Files.SideEffects.FoundWithSymbols` excluded)
- Underscore method names allowed in tests
- Run `composer lint` before committing

## Testing

Tests use **Codeception 5** with PHPUnit underneath.

- **Unit** (`tests/Unit/`): pure PHP, no HTTP.
- **Acceptance** (`tests/Acceptance/`): browser-level via PhpBrowser. Requires `SITE_URL` and `LAMB_TEST_PASSWORD` in `.env`. Generate it with `LAMB_WRITE_TEST_PASSWORD=1 php make-password.php <pw>` — `make-password.php` omits the cleartext `LAMB_TEST_PASSWORD` unless that flag is set.
- **Functional** (`tests/Functional/`): Codeception functional tests.

Config in `codeception.yml` reads env from `.env`.

### JavaScript unit tests

Client-side scripts in `src/scripts/` are unit-tested with Node's built-in test runner (`node:test`) plus `jsdom` — no extra framework. Run them with `pnpm test` (`node --test "tests/js/**/*.test.mjs"`); CI runs the same via the `js-test` job.

The scripts ship as plain `<script>`-tag globals (no module exports), so tests don't import them. Instead `tests/js/helpers.mjs` (`loadScripts()`) concatenates the source(s), evaluates them inside a `jsdom` window (`runScripts: 'outside-only'`), and returns the requested globals — keeping the shipped files untouched. Handlers registered via `onLoaded` (DOMContentLoaded) attach only after you dispatch a `DOMContentLoaded` event, since jsdom finishes parsing before the eval. See `tests/js/paste-link.test.mjs` for the pattern (faking a `paste` event with `clipboardData.getData`).

### Red-Green TDD

Always follow red-green TDD when adding or changing behaviour:

1. **Write the test first** — it must fail (`vendor/bin/codecept run Unit`) before any implementation is written.
2. **Write the minimum implementation** to make the test pass.
3. **Run again** to confirm green.

Never write implementation code before a failing test exists for it.

### After a bugfix

After fixing any bug, always — without being asked:

1. **Sweep related functionality for the same class of bug.** Identify the other call sites, sibling functions, and code paths that share the faulty pattern (the same helper, the same assumption, the same parsing/encoding step) and check each for the same defect. A bug rarely lives alone — e.g. a fix in one save path usually has twins in the other save paths. Add red-green tests for any further bugs found and fix them.
2. **Identify refactoring opportunities.** Note duplication the fix exposed (e.g. the same logic copy-pasted across sites) and consolidate it behind a single shared helper where doing so removes the duplication without adding complexity. Prefer fixes that collapse the duplication that allowed the bug to diverge in the first place; leave well enough alone when sharing would complicate more than it simplifies.

## Environment Setup

Authentication password is stored hashed in the `LAMB_LOGIN_PASSWORD` environment variable:

```bash
php make-password.php mysecretpassword
# writes LAMB_LOGIN_PASSWORD, SITE_URL to .env
```

The app reads `LAMB_LOGIN_PASSWORD` via `getenv()` at runtime. Production deployments
(FrankenPHP, nginx+php-fpm, Docker) set it as a real environment variable. The PHP
built-in dev server (`composer serve`) does not load `.env`, so `Bootstrap\load_dotenv()`
reads it in — but **only under the `cli-server` SAPI** and **non-overriding** (a real
env var always wins), so production is unaffected. `phpdotenv` is a dev dependency, so
this no-ops on a `--no-dev` install.

## Branching

- `release` — stable branch for end users running a blog; check out this branch if you want the latest released version
- `main` — active development branch for contributors; branch from this for new feature or fix work
- `next` — next major version targeting
- `*-pinned` — pinned reference branches

For contributors: always branch from `main` for new features. Open an issue first; get agreement from maintainers before building features.

## Pull Requests

When a task's work is complete and pushed, open a pull request for it by default — you do not need to be asked first. This overrides the default "do not open a pull request unless explicitly asked" behaviour.

After opening a pull request, watch its activity and automatically fix failing CI checks — diagnose the failure, push a fix, and repeat until the checks pass — without waiting to be asked. Address clear-cut review feedback the same way; check in before acting only when a fix is ambiguous or architecturally significant.

### Closing issues for non-default-branch merges

GitHub only auto-closes an issue from a PR's `Closes #NNN` / `Fixes #NNN` keyword when that PR merges into the **default branch** (`main`). PRs merged into `next` (or any other non-default branch) leave their referenced issues open even though the keyword is present.

So when a merged PR targeting a non-default branch references an issue with a closing keyword, close that issue manually: add a short comment noting it was completed in the PR (and which branch it merged to), then close it as completed. This mirrors what GitHub would have done on a default-branch merge.

## Philosophy (from README)

- Simple over complex
- Opinionated defaults over settings
- Assume success, communicate failure

When adding features, prefer the minimal implementation. Avoid adding configuration options where a sensible default is sufficient.

<!-- headroom:learn:start -->
## Headroom Learned Patterns
*Auto-generated by `headroom learn` on 2026-06-04 — do not edit manually*

### Shell Command Gotchas
*~2,500 tokens/session saved*
- A foreground `sleep N` followed by another command is BLOCKED by the harness (e.g. `sleep 30 && gh pr checks`). To wait on a condition use an until-loop `until <check>; do sleep 2; done`, run it in the background, or use Monitor. This was hit repeatedly across sessions.
- `grep` is proxied to ripgrep (rtk): BRE alternation `\|` and unescaped parens `(` cause `regex parse error: unclosed group`. Use ERE: `grep -E 'a|b'` and escape literal parens as `\(`. `rtk find` also rejects `-exec`/`-not` — call `find` directly for those.

### Running Acceptance Tests
*~1,500 tokens/session saved*
- `vendor/bin/codecept run Acceptance` needs `LAMB_LOGIN_PASSWORD` and `SITE_URL` in the environment. Source `.env` first: `set -a; . ./.env; set +a` then run. CI passes it via the acceptance suite server env (tests/Acceptance.suite.yml).
- Kill stale dev servers on port 8747 before re-running (`pkill -f 'php -S 0.0.0.0:8747'`) or the run hangs/fails.

### Editing Files
*~1,200 tokens/session saved*
- Edit/Write fail with "File has not been read yet" unless the file was Read in this session — this fired 15+ times, especially on MEMORY.md, docs/*.md, .gitignore, composer.json, and src files. Read the exact target path before the first Edit/Write to it; don't assume a `git mv`/`sed`-touched file is "read".

### Theme Directory Names
*~600 tokens/session saved*
- The base theme directory is `src/themes/base/` (the old `default` was renamed in PR #289). Greps for `src/themes/default` return nothing — search `base`, `2024`, or `2026`.

<!-- headroom:learn:end -->

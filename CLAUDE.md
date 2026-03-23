# CLAUDE.md — Lamb Codebase Guide

Lamb is a self-hosted, single-author microblog. It uses PHP 8.2+, SQLite (via RedBeanPHP ORM), and a procedural-with-namespaces architecture. There is no MVC framework — routing, responses, and views are handled by small namespaced PHP files.

## Documentation (End-User)

The end-user documentation lives in `docs/` (tracked in the repository, served via GitHub Pages). It is the end-user manual. When working on user-facing features:

- Check whether a wiki page exists for the feature and update it if needed.
- When adding new user-facing behaviour, consider whether a new wiki page is warranted.
- Ensure wiki pages that are topically related link to each other via a "Related" section.

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

# Generate password hash and write .env
php make-password.php <your-password>

# Static analysis
composer analyse

# Auto-fix coding standard violations
composer fix

# Install pre-commit hook (one-time, after cloning)
printf '#!/bin/sh\nset -e\ncomposer lint\ncomposer analyse\n' > .git/hooks/pre-commit && chmod +x .git/hooks/pre-commit
```

## Project Structure

```
lamb/
├── src/                  # Application source (web root)
│   ├── index.php         # Entry point: bootstrap, routing, view dispatch
│   ├── bootstrap.php     # DB init (SQLite via RedBean) + session setup
│   ├── config.php        # INI-based config stored in DB; load/save/validate
│   ├── routes.php        # register_route() / call_route() helpers
│   ├── lamb.php          # Core helpers: parse_bean, parse_tags, permalink, find_redirect, delete_redirect_for_slug
│   ├── post.php          # Post helpers: populate_bean, parse_matter, slugify
│   ├── response.php      # All route handlers (respond_*, redirect_*)
│   ├── security.php      # require_login(), require_csrf()
│   ├── theme.php         # Template helpers, asset loader, part()
│   ├── network.php       # Feed ingestion via SimplePie (_cron route)
│   ├── http.php          # get_request_uri() — normalises / → /home
│   ├── LambDown.php      # Parsedown subclass (restricts heading levels)
│   ├── assets/           # Runtime upload storage (created under YYYY/MM)
│   ├── themes/
│   │   ├── default/      # Default theme (HTML, parts, feed, CSS)
│   │   └── 2024/         # Alternative theme (overrides parts as needed)
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
| `http.php` | `Lamb\Http` |
| `lamb.php` | `Lamb` |
| `network.php` | `Lamb\Network` |
| `post.php` | `Lamb\Post` |
| `response.php` | `Lamb\Response` |
| `routes.php` | `Lamb\Route` |
| `security.php` | `Lamb\Security` |
| `theme.php` | `Lamb\Theme` |

### Database

RedBeanPHP (fluid mode) on SQLite. Beans are dispensed/loaded with `R::dispense`, `R::load`, `R::findOne`, `R::find`, `R::findAll`. Schema evolves automatically.

**Tables used:**
- `post` — blog posts; columns include `body`, `slug`, `title`, `description`, `transformed`, `created`, `updated`, `version`, `feed_name`, `feeditem_uuid`, `source_url`
- `option` — key/value store (e.g. `site_config_ini`, `last_processed_date`)
- `redirect` — automatic 301 redirects created when a post slug changes; columns: `from_slug`, `to_url`

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

`parse_matter()` extracts YAML. If `title` is present and `slug` is absent, it derives `slug` from `title` via `slugify()`. If `slug` is explicitly present in front matter, that value is used.
`parse_bean()` runs Markdown → HTML, extracts tags, stores `transformed`, `description`, and front-matter-derived fields on the bean.
`LambDown` extends Parsedown with safe mode on and restricts `#` headings (must be `# ` with a space).

On `main`/`release`, slugs are effectively immutable after creation: editing a post will not overwrite an existing slug from changed front matter or title. New page-like posts get their slug at creation time; status posts keep the numeric `/status/<id>` permalink.

### Theming

`Theme\part($name, $dir)` includes `THEME_DIR/$dir/$name.php`, falling back to `src/themes/default/`. Custom themes only need to override specific parts.

**Theme parts (default):**
- `html.php` — outer HTML shell (includes `parts/home.php`, etc.)
- `feed.php` — Atom feed output
- `parts/home.php`, `status.php`, `edit.php`, `search.php`, `tag.php`, `login.php`, `settings.php`, `404.php`
- `parts/_items.php` — post list partial
- `parts/_pagination.php` — pagination partial
- `parts/_related.php` — related posts partial

---

## Theme System — Complete Reference

### How themes are selected

`index.php` reads `$config['theme']` (set via the INI config at `/settings`) and falls back to `'default'`:

```php
define("THEME",     $config['theme'] ?? 'default');
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');   // absolute FS path
define("THEME_URL", 'themes/' . THEME . '/');               // URL prefix (relative)
```

`ROOT_DIR` is `src/`. So `THEME_DIR` for a theme named `news` resolves to `src/themes/news/`.

To activate a theme add `theme = news` to the INI config in the DB (edit at `/settings`).

### Part resolution (`Theme\part`)

```php
Theme\part($name, $dir = 'parts')
```

1. Looks for `THEME_DIR . $dir . '/' . $name . '.php'`
2. Falls back to `src/themes/default/$dir/$name.php`
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

The URL served is `THEME_URL . 'styles/styles.css'` with a cache-buster query string (`?<md5-of-url>`).

### JavaScript asset loading

`the_scripts()` takes no arguments. It does not load scripts from the active theme directory.

It always loads:

- `src/scripts/shorthand.js`

When the user is logged in, it also loads:

- `src/scripts/logged_in/growing-input.js`
- `src/scripts/logged_in/confirm-delete.js`
- `src/scripts/logged_in/link-edit-buttons.js`
- `src/scripts/logged_in/upload-image.js`

### Upload asset storage

User-uploaded files live under `src/assets/`, not under theme directories.

`respond_upload()` stores files in `src/assets/YYYY/MM/` and returns Markdown image links pointing at those uploaded files. Deployment setups that support uploads must ensure `src/assets/` is writable by the web server or PHP-FPM user.

### `.gitignore` exemption

`src/themes/` is ignored by default. Every new theme directory must be explicitly exempted with two entries — one for the directory and one for its contents:

```
# in .gitignore
!/src/themes/news
!/src/themes/news/**
```

Once both entries are added, `git add` works normally for all files inside the theme without needing `--force`. Use `git add --force src/themes/<name>/` only if you add a theme before updating `.gitignore`.

### Minimal new theme checklist

A theme only needs to override the files that differ from `default`. The absolute minimum is a stylesheet:

```
src/themes/<name>/
└── styles/
    └── styles.css     ← required (the_styles() always loads this path)
```

Add `html.php` only if the HTML shell (nav, header, footer) changes. Add `feed.php` only if the Atom output differs from the default feed template. Add individual `parts/*.php` files only for the page templates that differ visually. All other parts fall back to `default` automatically.

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
- Login sets `$_SESSION[SESSION_LOGIN]` and a `lamb_logged_in` cookie
- Admin-only JS files are conditionally loaded via `asset_loader()`

### Feed Ingestion (Cron)

`GET /_cron` triggers `Network\process_feeds()`. It reads `[feeds]` from config, fetches each RSS/Atom feed via SimplePie, and creates/updates posts. Rate-limited: minimum 1 minute between full runs, 30 minutes per feed. Feed items get a `feeditem_uuid` (md5 of feed name + item ID) for deduplication.

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
- **Acceptance** (`tests/Acceptance/`): browser-level via PhpBrowser. Requires `SITE_URL` set in `.env` (written by `make-password.php`).
- **Functional** (`tests/Functional/`): Codeception functional tests.

Config in `codeception.yml` reads env from `.env`.

### Red-Green TDD

Always follow red-green TDD when adding or changing behaviour:

1. **Write the test first** — it must fail (`vendor/bin/codecept run Unit`) before any implementation is written.
2. **Write the minimum implementation** to make the test pass.
3. **Run again** to confirm green.

Never write implementation code before a failing test exists for it.

## Environment Setup

Authentication password is stored hashed in the `LAMB_LOGIN_PASSWORD` environment variable:

```bash
php make-password.php mysecretpassword
# writes LAMB_LOGIN_PASSWORD, SITE_URL to .env
```

The app reads `LAMB_LOGIN_PASSWORD` via `getenv()` at runtime.

## Branching

- `release` — stable branch for end users running a blog; check out this branch if you want the latest released version
- `main` — active development branch for contributors; branch from this for new feature or fix work
- `next` — next major version targeting
- `*-pinned` — pinned reference branches

For contributors: always branch from `main` for new features. Open an issue first; get agreement from maintainers before building features.

## Philosophy (from README)

- Simple over complex
- Opinionated defaults over settings
- Assume success, communicate failure

When adding features, prefer the minimal implementation. Avoid adding configuration options where a sensible default is sufficient.

# CLAUDE.md — Lamb Codebase Guide

Lamb is a self-hosted, single-author microblog. It uses PHP 8.2+, SQLite (via RedBeanPHP ORM), and a procedural-with-namespaces architecture. There is no MVC framework — routing, responses, and views are handled by small namespaced PHP files.

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

# Generate password hash and write .ddev/.env + .env
php make-password.php <your-password>
```

## Project Structure

```
lamb/
├── src/                  # Application source (web root)
│   ├── index.php         # Entry point: bootstrap, routing, view dispatch
│   ├── bootstrap.php     # DB init (SQLite via RedBean) + session setup
│   ├── config.php        # INI-based config stored in DB; load/save/validate
│   ├── routes.php        # register_route() / call_route() helpers
│   ├── lamb.php          # Core helpers: parse_bean, parse_tags, permalink
│   ├── post.php          # Post helpers: populate_bean, parse_matter, slugify
│   ├── response.php      # All route handlers (respond_*, redirect_*)
│   ├── security.php      # require_login(), require_csrf()
│   ├── theme.php         # Template helpers, asset loader, part()
│   ├── network.php       # Feed ingestion via SimplePie (_cron route)
│   ├── http.php          # get_request_uri() — normalises / → /home
│   ├── LambDown.php      # Parsedown subclass (restricts heading levels)
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
└── make-password.php     # CLI utility: hash password → .ddev/.env
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
- `post` — blog posts; columns include `body`, `slug`, `title`, `description`, `transformed`, `created`, `updated`, `version`, `feed_name`, `feeditem_uuid`
- `option` — key/value store (e.g. `site_config_ini`, `last_processed_date`)

**Post versioning:** `upgrade_posts()` in `response.php` migrates posts without a `version` field to version 1 by re-running `parse_bean()`.

### Configuration

Config is stored as raw INI text in the `option` table under key `site_config_ini`. On first run it bootstraps from `config.ini` (if present) or uses built-in defaults. Edit it at `/settings` (login required).

Config keys: `author_email`, `author_name`, `site_title`, `404_fallback`, `posts_per_page`, `[menu_items]`, `[feeds]`.

### Post Content Format

Post bodies are Markdown with optional YAML front matter separated by `---`:

```
---
title: My Post Title
---

Post content here. Use #hashtags inline.
```

`parse_matter()` extracts YAML → sets `slug` from `title` via `slugify()`.
`parse_bean()` runs Markdown → HTML, extracts tags, stores `transformed`, `description`, `slug` on the bean.
`LambDown` extends Parsedown with safe mode on and restricts `#` headings (must be `# ` with a space).

Slugs are immutable once set (changing a slug would break URLs).

### Theming

`Theme\part($name, $dir)` includes `THEME_DIR/$dir/$name.php`, falling back to `src/themes/default/`. Custom themes only need to override specific parts.

**Theme parts (default):**
- `html.php` — outer HTML shell (includes `parts/home.php`, etc.)
- `feed.php` — Atom feed output
- `parts/home.php`, `status.php`, `edit.php`, `search.php`, `tag.php`, `login.php`, `settings.php`, `404.php`
- `parts/_items.php` — post list partial
- `parts/_pagination.php` — pagination partial
- `parts/_related.php` — related posts partial

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

Config in `codeception.yml` reads env from `.ddev/.env` and `.env`.

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
# writes LAMB_LOGIN_PASSWORD to .ddev/.env
# writes SITE_URL to .env
```

The app reads `LAMB_LOGIN_PASSWORD` via `getenv()` at runtime.

## Branching

- `main` — active development
- `release` — stable releases cut from main
- `next` — next major version targeting
- `*-pinned` — pinned reference branches

Always branch from `main` for new features. Open an issue first; get agreement from maintainers before building features.

## Philosophy (from README)

- Simple over complex
- Opinionated defaults over settings
- Assume success, communicate failure

When adding features, prefer the minimal implementation. Avoid adding configuration options where a sensible default is sufficient.

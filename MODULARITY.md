# Duplicate mechanisms in Lamb

Where the codebase does one job two or three different ways, with file:line evidence
for each. This is the findings document behind [PLAN.md](PLAN.md), which sequences the
work; it records what is duplicated, not what to do about it.

The through-line: **the bugs and the sprawl are separate problems.** The bugs come
from the ten hotspots in §2, where the same job has more than one implementation and
the copies have drifted. The sprawl is §1 — 44% of `src/` being optional features in a
flat namespace. Fixing the duplication does not require touching the sprawl, and the
reverse is not true: consolidating behind a boundary before the implementations agree
just hides the divergence somewhere harder to see.

## 1. What "sprawled" measures

`src/` is 16,179 lines of PHP. Grouped by whether an install actually needs it:

| Area | LOC | Reachable via | Runtime dep it alone pulls in |
|---|---:|---|---|
| Core (routing, config, post, theme, http, security, response) | 8,079 | everything | redbean, parsedown, symfony/yaml |
| IndieWeb (`micropub`, `webmention`, `websub`) | 2,558 | `/micropub`, `/micropub-media`, `/webmention`, `Link:` headers, `/_cron` | `taproot/micropub-adapter` |
| Importers (`import`, `wordpress`, `known`, `restore` + 3 CLIs) | 2,391 | CLI only | `league/html-to-markdown` |
| Feed reader (`network/`) | 1,042 | `/_cron` | `simplepie/simplepie` |
| Uploads + WebP | 663 | `/upload`, micropub media | `ext-gd` |
| Export | 439 | `/export` | `ext-zip` |
| Syntax highlighting | 91 | post render | `phiki/phiki` |

Optional features are **7,184 lines — 44% of the PHP in `src/`** — and **4 of the 8
runtime composer dependencies exist only to serve them.**

That ratio is worth knowing but is not, on its own, a defect: every one of those areas
is a headline feature in `README.md`, so for most installs this is the product rather
than dead weight. It matters here for a narrower reason — those 7,184 lines share a
flat namespace with the core and with each other, which is the condition under which
§2's duplication keeps appearing. Two features that never reference each other can
still grow two copies of the same helper.

The only existing seams in the codebase are `Theme\part()`'s base-theme fallback and
the `?callable $fetcher` / `$sender` / `$downloader` / `$resolver` injection parameters
used for testing. The latter idiom is worth reusing where §2's fixes need a seam.

---

## 2. Where there are multiple ways to do one thing

Ranked by how likely each is to be the source of the bugs being found. Every one of
these is evidenced by the code's own comments, which document the divergence rather
than prevent it.

### D1. Saving a post — 9 entry points, 3 tiers of completeness ⚠️ **highest risk**

| Call site | Renders | Finalizes slug | Notifies subscribers |
|---|---|---|---|
| `Response\redirect_created()` `response/posts.php:45` | ✅ | ✅ | ✅ |
| `Response\redirect_edited()` `response/posts.php:296` | ✅ | — | ✅ |
| `Micropub::createCallback()` `micropub.php:360` | ✅ | ✅ | ✅ |
| `Micropub::updateCallback()` `micropub.php:545` | ✅ | — | ✅ |
| `Network\create_item()` `network/ingest.php:139` | ✅ | ✅ | ❌ |
| `WordPress\import_item()` `wordpress.php:236` | ✅ | ✅ | ❌ |
| `Known\import_item()` `known.php:358` | ✅ | ✅ | ❌ |
| `Restore\import_post()` `restore.php:355` | ✅ | ✅ | ❌ |
| `Response\apply_checkbox_toggle()` `response/posts.php:428` | ✅ | — | ❌ |
| `Response\upgrade_posts()` `response.php:274` (read path!) | ✅ | — | ❌ |

Each site independently decides whether to re-render, finalize the slug, notify
subscribers, write a slug-change redirect, and feed-lock. `wordpress.php:203` and
`known.php:307` both carry the comment *"finalize_and_store_post(), which never
invokes notify_post_subscribers()"* — the asymmetry is a documented convention held
by hand across eight files.

The deeper consequence: **"published" is not an event.** Nothing emits it, so each
interested subsystem re-derives it. `Webmention\process_outbound()` checks
`is_publicly_visible()` at send time; `Websub\ping_scheduled_publishes()`
(`websub.php:142`) infers it from a `created > updated` heuristic over a watermark
window. Two different definitions of the same moment, in two files, for the same
posts. Both are currently correct — but nothing structural keeps them agreeing, and
a third subscriber would need a third derivation.

### D2. Making an outbound HTTP request — 5 transports, 1 of them guarded, 1 dead

- `Http\fetch()` `http.php:578` — streams, unguarded
- `Http\fetch_pinned()` `http.php:482` — curl, pinned to a validated IP
- `Http\fetch_guarded()` `http.php:683` — the SSRF-safe path, re-validates every hop
- `Http\post_form()` `http.php:443` — **zero callers.** The only two places that
  would use it (`websub.php:105`, `webmention.php:778`) carry comments explaining
  they deliberately don't. Dead code that still reads as an available option.
- `Network\SafeFile` `network/sources.php:34` — a SimplePie `File` subclass with its
  own hardening, because SimplePie can't be handed `fetch_guarded()`

Which one you get depends on which file you are editing, not on what the call needs.

### D3. Sanitizing and escaping HTML — 4 sanitizers, 5 escapers

Sanitizers, each with its own tag allowlist:
`LambDown` (Parsedown safe mode, post bodies) · `Import\sanitize_html_in_dom()`
`import.php:95` · `Micropub::sanitizeHtml()` + `sanitizeAttributes()`
`micropub.php:1102` · `Webmention\extract_meta()` `webmention.php:224` (regex +
`strip_tags`).

Escapers, each with different flags:

| Location | Flags |
|---|---|
| `Theme\escape()` `theme/formatting.php:15` | `ENT_HTML5\|ENT_QUOTES\|ENT_SUBSTITUTE` |
| `Theme\og_escape()` `theme/formatting.php:94` | decode-then-`ENT_COMPAT\|ENT_HTML5` |
| `Theme\preload_text()` `theme/formatting.php:104` | `ENT_QUOTES` only |
| `themes/base/feed.php:9` | `ENT_XML1\|ENT_QUOTES\|ENT_SUBSTITUTE` |
| `response/discovery.php:106` | `ENT_XML1\|ENT_QUOTES\|ENT_SUBSTITUTE` |

The feed one is the worst of these: it declares a **global** function named `escape()`
from inside a template, behind a `function_exists()` guard, shadowing the namespaced
helper every other template uses.

### D4. Writing YAML front matter — 2 engines, 4 assemblers

Two engines with different semantics: `Post\set_matter()` `post.php:459` rewrites a
value in place with a regex; `Post\set_frontmatter_key()` `post.php:526` splits,
rebuilds through the YAML writer, and can also remove a key. Four assemblers:
`Post\build_matter()` `post.php:416`, `Import\build_post_body()` `import.php:344`,
`Micropub::assembleFrontMatter()` `micropub.php:886`, and
`Micropub::rebuildBody()` `micropub.php:861` doing its own fence handling. Then
`persist_slug`, `set_reply_to`, `inject_title_matter`, `persist_resolved_created`
are thin wrappers, split across *both* engines.

Front matter is the post format. Six ways to write it is the single most
bug-productive duplication in the codebase after D1.

### D5. Storing an uploaded image — 3 pipelines, 3 move semantics

`respond_upload()` (`move_uploaded_file()` then `store_webp_copy()`) ·
`respond_micropub_media()` `micropub.php:1502` (same two, opposite order) ·
`persist_image_bytes()` `response/upload.php:300` (`tempnam` + `rename` + explicit
`chmod`, used by micropub inline photos and the import downloader). Two WebP
encoders for one decision (`convert_to_webp` / `convert_to_webp_from_bytes`).

`response/upload.php:328-333` documents the consequence in full: the `tempnam`
path landed assets at `0600` while every other path landed at `0666 & ~umask`, so
a static file server or backup user couldn't read images the site was serving.
Fixed — in one of the three paths.

### D6. Deciding a post is visible — 5 expressions

`Lamb\visible_clause()` `lamb.php:715` (documented as *"the single allow-list
definition"*) · `Response\public_posts_clause()` `response.php:118` (adds menu
exclusion) · `is_publicly_visible()` / `is_viewable()` / `is_scheduled()`
`lamb.php:773-817` (in-memory) · `websub.php:157` re-assembles `SQL_PUBLISHED` with
its own conditions inline · plus an `is_menu_item()` backstop **inside each theme's
`_items.php`**.

The docblocks record two bugs already shipped from this: *"which is how scheduled
posts leaked into related posts"* and *"both used to assemble the two clauses
inline, in opposite orders."*

### D7. Serializing a post for output — 5 writers, 2 owned by the theme layer

Atom (`themes/base/feed.php`), JSON Feed (`themes/base/feed_json.php`), sitemap
(`Response\render_sitemap()` `response/discovery.php:94`), export manifest
(`Export\manifest_post_entry()` `export.php:212`), mf2
(`Micropub::beanToMf2Properties()` `micropub.php:104`).

Two of the five are **theme parts**, which means a custom theme owns the syndication
contract. `themes/2026/html.php:85-89` shows exactly this class of failure already
happening for a different part: *"A theme that omits the call hides them from the
author entirely — this one did, and it is the default theme."*

### D8. Rendering the post list — 3 copies

`themes/2024/parts/_items.php` and `themes/2026/parts/_items.php` differ by **two
lines, one of which is a comment**, and both have diverged from
`themes/base/parts/_items.php` in the same direction (schema.org attributes, `<ul>`
wrapping, meta block, logged-in footer). `themes/base/parts/_related.php:31`
carries the giveaway: *"mb_strimwidth(), as the 2026 theme's own _related.php
uses"* — a truncation fix hand-ported between copies after it was found in one.

### D9. Running an importer — 3 near-identical CLI scripts

`import-wordpress.php`, `import-known.php`, `import-lamb.php` are ~60-70 lines each
of the same sequence: SAPI check, `define('ROOT_DIR')`, `parse_import_args()`,
readability check, `getenv('LAMB_DATA_DIR')`, `bootstrap_db()`, `Config\load()`,
`apply_timezone()`, experimental gate, parse, extract, `run_import()`. The
*conversion* logic already converged on `Lamb\Import`; only the wiring is triplicated.

### D10. Configuring the install — 6 sources

`.env` via phpdotenv/`getenv()` · a `config.ini` file (`config.php:511`) · the
`option.site_config_ini` DB row · `Config\get_default_ini_text()` built-in defaults ·
`constants.php` · and `define()` at runtime in `index.php` and `response.php:22`.
`LAMB_DATA_DIR` is read independently in `bootstrap.php:58` and all three CLI
scripts. `constants.php` also mixes core constants with feature constants
(`FEED_FETCH_*`, `IMAGE_UPLOAD_EXTENSIONS`, `VIDEO_UPLOAD_EXTENSIONS`).

---

# Modularity investigation — duplicate mechanisms and plugin seams

**Status:** proposal, nothing implemented. Written in response to "the project is
sprawled, we keep finding bugs — find where there are multiple ways to do
something and how we can split them off into plugins."

The finding in one line: **the sprawl and the bugs are two different problems, and
only one of them is fixed by plugins.** The bugs come from ~10 places where the
same job is done two or three different ways inside the core. The sprawl comes from
~44% of `src/` being optional features fused into that core. Extracting a plugin
does not fix a duplicate mechanism — it copies it into a module boundary and makes
it harder to see. So the order matters: **converge first, extract second.**

---

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
runtime composer dependencies exist only to serve them.** Given how much of
`AGENTS.md` is dedicated to dependency and advisory management, a minimal install
carrying CVE surface for a Micropub endpoint it never exposes is a real cost, not a
theoretical one.

There is currently **no extension mechanism at all**: no hooks, no filters, no
module registry. The only existing seams are `Theme\part()`'s base-theme fallback
and the `?callable $fetcher` / `$sender` / `$downloader` / `$resolver` injection
parameters used for testing. That injection idiom is the right seed — the proposal
below builds on it rather than importing a hook system.

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

## 3. What is plugin-shaped, and what must stay one way

This is the distinction that decides whether this work reduces bugs or multiplies
them.

**Extract to a module** — optional, feature-complete, owns its own routes/tables/deps:

| Module | Absorbs | Provides | Drops dep |
|---|---|---|---|
| `indieweb` | `micropub.php`, `webmention.php`, `websub.php`, `parts/_webmentions.php`, `index.php`'s 4 `Link:` headers | 3 routes, 1 cron job, 1 theme part, `webmention`/`webmentionoutbox` tables | `taproot/micropub-adapter` |
| `feeds` | `network.php`, `network/` | `/_cron` ingest job, `feedstatus` table, `FEED_FETCH_*` | `simplepie/simplepie` |
| `import` | `import.php`, `wordpress.php`, `known.php`, `restore.php`, 3 CLIs | 3 registered import sources, 1 CLI driver | `league/html-to-markdown` |
| `export` | `export.php`, `response/export.php` | `/export`, needs `ext-zip` | — |
| `highlight` | `highlight.php` | a render filter, `POST_VERSION` participant | `phiki/phiki` |

**Consolidate, never make pluggable** — these need *one* implementation, and offering
a choice is precisely what caused D1-D6:

routing · config · `Lamb\Post` (front matter, slug, render) · visibility · HTTP
egress guarding · HTML escaping and sanitizing · the upload security checks
(`safe_upload_extension`, `upload_content_allowed`, `max_upload_pixels`) · session
and CSRF.

The project's own philosophy — *"opinionated defaults over settings"*, *"prefer the
minimal implementation"* — argues for exactly this split: modules are an **internal
seam for optional features**, not a third-party plugin market. No hook priorities,
no filter chains, no plugin API to support.

---

## 4. The minimal mechanism

Four seams. Three of them already half-exist.

**1. Route registration — already done.** `Route\register_route()` /
`register_private_route()` is a registry, and `register_private_route()` already
derives `robots.txt` and the `noindex` header from one source of truth. A module
just calls it. Zero new machinery; this is why routes are the cheapest thing to move.

**2. A post lifecycle funnel — the prerequisite for everything else.** One
`Post\save(OODBBean $bean, array $context): void` that all nine D1 sites call,
emitting `post.created`, `post.updated`, `post.published`, `post.deleted`,
`post.restored`. Subscribers: outbound webmentions, WebSub pings, slug-change
redirects, feed-lock. `$context` carries the flags each site currently expresses by
*omitting a call* (`notify: false` for imports, `finalize_slug: false` for edits) —
so the convention becomes a parameter that can be tested instead of a comment in
eight files. This is the fix for D1 **and** the seam that makes `indieweb`
extractable, because webmention/websub stop being called by name from the write paths.

**3. A cron registry.** `Network\process_feeds()` `network.php:142-146` hardcodes
`Websub\ping_scheduled_publishes()` and `Webmention\process_outbound()` inside the
feed-ingestion loop. That single coupling means **outbound webmentions stop being
delivered if you remove the feed reader.** Replace with `Cron\register(name,
callable)` and a `/_cron` driver that runs whatever registered.

**4. An importer registry.** `Import\register_source(['name' =>, 'parse' =>,
'extract' =>, 'skip_reason' =>, 'uuid' =>, 'import' =>])` plus one
`bin/lamb import <source> <path> [--dry-run] [--replace]` driver. `run_import()` is
already the shared loop and already takes exactly these callables — this is
mechanical, and it collapses D9 to zero while making a fourth importer a drop-in.

A module is then a directory with a manifest, loaded from config:

```php
// src/modules/indieweb/module.php
return [
    'name'     => 'indieweb',
    'requires' => [],                        // ext-*/composer checks, cf. Export\zip_available()
    'register' => function (): void {
        Route\register_route('micropub', 'Lamb\Micropub\respond_micropub');
        Route\register_route('webmention', 'Lamb\Webmention\respond_webmention');
        Post\on('post.published', 'Lamb\Webmention\enqueue_for_post');
        Post\on('post.published', 'Lamb\Websub\ping_for_post');
        Cron\register('webmention-outbox', 'Lamb\Webmention\process_outbound');
        Http\link_header('micropub', ROOT_URL . '/micropub');
    },
];
```

Modules ship **enabled by default** — this is a code-organisation seam, not a new
settings page. `experimental_features` + `EXPERIMENTAL_GATE_VERSION`
(`config.php:436-479`) already establishes the precedent for gating a feature set
and forcing re-opt-in when that set changes.

---

## 5. Sequencing

**Phase 0 — pure convergence, no architecture change.** Each item is independently
shippable and removes a class of divergence. Do this first; it is where the bug
reduction actually is.

1. Delete `Http\post_form()` (D2) — dead, and its existence invites use of the
   unguarded transport.
2. One escaper (D3): namespaced, XML variant as an explicit argument; delete the
   global `escape()` in `themes/base/feed.php`.
3. One front-matter engine (D4): keep `set_frontmatter_key()`'s split/rebuild
   semantics, re-express `set_matter()` and the four assemblers on top of it.
4. One upload store (D5): one `store_upload()` taking either a path or bytes.
5. Dedupe the theme parts (D8): `2024` and `2026` differ by two lines — promote the
   shared version, keep only genuine overrides.
6. One `Bootstrap\data_dir()` for the CLIs (D10).

**Phase 1 — the post lifecycle funnel.** Convert all nine D1 write sites. This is
the highest-value single change in the document and the gate for everything after it.

**Phase 2 — cron registry.** Unhook webmention/websub from `process_feeds()`.

**Phase 3 — first module: `import`.** Lowest risk by a wide margin: CLI-only, no
web routes, no theme surface, and *already gated behind `experimental_features`* —
a kill switch that exists today. Proves the manifest end to end.

**Phase 4 — `indieweb`, `export`, `highlight`.** Move Atom and JSON Feed out of
`themes/` into a serializer registry (D7) so themes stop owning syndication.

**Phase 5 — optional dependencies.** Move the four feature-only composer packages to
`suggest` with runtime capability checks, following `Export\zip_available()`.

---

## 6. Risks worth stating up front

- **Philosophy.** A plugin *framework* would contradict "simple over complex". The
  answer is that this adds no user-facing configuration and no third-party API: one
  manifest array, four registries, modules on by default.
- **Global state is the real coupling.** `global $routes, $config, $data, $template`
  is what any module system inherits. Not worth fixing now, but it caps how isolated
  a module can ever be, and it should be named rather than discovered later.
- **RedBean fluid mode and module-owned tables.** `webmention`, `webmentionoutbox`,
  `feedstatus` are created lazily on first write. Disabling a module leaves orphan
  tables. Acceptable, but it means "disabled" is not "uninstalled".
- **Test suite pinning.** 100 unit tests and 22 acceptance Cests, several of which
  (`MicropubDiscoveryCest`, `MicropubMediaCest`, `WebmentionCest`, `UploadCest`,
  `TagFeedCest`) exercise routes that would become module-provided. CI must pin the
  enabled module set or coverage silently drops when a default changes.
- **Do not extract before converging.** Extracting `indieweb` while Micropub still
  carries its own front-matter assembler, its own HTML sanitizer, and its own upload
  pipeline just relocates D3, D4, and D5 behind a module boundary where they are
  harder to see and easier to justify.

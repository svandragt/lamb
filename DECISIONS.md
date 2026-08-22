# Decision Log

Architectural and product decisions for the Lamb project.

Entries marked **[deduced]** were reconstructed from code and history rather than recorded at the time.

---

## 2026-07-26 — Export format is front-matter Markdown plus a JSON manifest

**Status:** Accepted
**Context:** Issue #440 asked for an own-your-data export and left the format open: WXR, JSON, or a Markdown bundle. The original objection to an export at all — "SQLite is already browsable, and Lamb has no lock-in" — had weakened: Lamb had gained two importers (WordPress, Known) and still no way out, and the only backup path was copying the binary `data/lamb.db` and hoping the RedBean schema still matched on restore.

A "convert everything to a Lamb-specific JSON first, then into the system" intermediate representation was considered. The code turned out to already have an answer: neither importer builds a JSON IR. Both converge on `Import\build_post_body()`, which emits Markdown with YAML front matter — precisely what `Post\parse_matter()` reads back, and precisely what the `post.body` column stores.

**Decision:** The export is a zip of `posts/YYYY/MM/<slug>.md` (each post's stored body, byte-for-byte), `assets/YYYY/MM/…` (only files the posts reference), and a `manifest.json`. Post bodies are written verbatim rather than re-serialised, so the export cannot drift from what an import produces. The Markdown files carry only what `parse_matter()` understands; everything Lamb-internal (id, timestamps, draft/deleted state, feed provenance) lives in the manifest, and the manifest's field list is an explicit allowlist so preview tokens — credentials for unpublished posts — can never travel with an archive. Drafts and trashed posts are exported and flagged.

WXR was rejected: it is WordPress's schema, has no clean home for Lamb's draft/deleted/feed state, and the Known importer had already shown that real-world "WXR" is a partial veneer, so emitting it means choosing which dialect to be wrong in. Conversion to WXR/Hugo/Jekyll belongs in a separate converter that consumes this format. A second flat-JSON export variant was also rejected for now: a programmatic consumer reads `manifest.json` for metadata and the `.md` files for content, and two formats would mean two things to keep in sync.

`ext-zip` is checked at runtime rather than added to `composer.json` require, so an existing install upgrading into this feature cannot fail `composer install` over an extension it never needed.

**Consequences:** The format both importers already emit is now documented (`docs/export.md`) and versioned (`lamb-export/1`) rather than being an internal detail. A future lamb→lamb importer — the actually-missing piece for backup/restore — has a specified input to read. Additive manifest fields stay within version 1; consumers must ignore unknown fields.

---

## 2026-07-29 — Imported posts are your own content, not feed items

**Status:** Accepted
**Context:** The WordPress and Known importers stamped every migrated post with `feed_name = 'wordpress'`/`'known'` and a `feeditem_uuid`, borrowing the feed-ingest columns to get re-run dedup for free. Neither importer sets `source_url`, so `Theme\link_source()` took its plain-text branch and every migrated post publicly read "Via wordpress" / "Via known". Worse, `Webmention\enqueue_for_post()` and `WebSub\ping_for_post()` both bail on a non-empty `feed_name`, and the WebSub ping query excludes those rows — so a migrated post could never send a webmention or a WebSub ping, not even when edited years later. `lock_if_feed_sourced()` would also feed-lock them on first edit: exactly the trap the lamb-export importer had already refused to walk into.

**Decision:** Both importers now write their dedup key to `import_uuid` — the column the lamb-export importer added — and set no feed identity at all. The `'wordpress-'`/`'known-'` prefixes stay, so the three importers keep separate dedup namespaces. Suppressing outbound notifications during an import run is still correct, and still happens the same way: `import_item()` stops at `finalize_and_store_post()`, which never calls `notify_post_subscribers()`. What is no longer true is that the suppression is permanent.

With all three importers supplying their own lookup, `run_import()`'s `$find_existing` parameter became required and its `feeditem_uuid` fallback was deleted — one dedup concept instead of two.

Installs that already ran an import are migrated on boot by `Bootstrap\backfill_imported_post_identity()`. It only touches rows with `source_url IS NULL`, because a user may legitimately subscribe to a feed literally named `wordpress` or `known`: feed ingestion always records the item permalink in `source_url` (`src/post.php`), the importers never did. `bootstrap_db()` runs before `Config\load()`, so the configured feed names cannot be consulted there — the `source_url` guard is the only discriminator available, and mangling someone's real feed posts would be far worse than leaving a cosmetic attribution line in place.

**Consequences:** Migrated posts render without attribution and participate in webmentions and WebSub like any other post. The backfill is idempotent and skips a row whose uuid another post already claims, so it is safe to run on every boot, matching the existing `UPDATE post SET version = 1 WHERE version IS NULL` precedent.

---

## 2026-07-29 — Lamb export import identity via a separate `import_uuid` column

**Status:** Accepted

**Context:** Issue #554 asked for a Lamb-to-Lamb importer that restores a `/export` archive back into the database, idempotently. The two existing importers, for WordPress and Known, dedupe re-runs through `feeditem_uuid`, and reusing that column for restored posts was the obvious first idea — one less column.

We rejected it. `lock_if_feed_sourced()` (`src/response/posts.php:329`) sets `feed_locked` on any post with a non-empty `feeditem_uuid` when you edit it, to stop you hand-editing content a feed will overwrite on the next cron run. A restored local post isn't feed-sourced, and nothing will overwrite it, so giving it a `feeditem_uuid` for free dedupe would also silently feed-lock it. That changes edit behaviour for a post that never subscribed to anything.

**Decision:** Add a dedicated `import_uuid` column through `Bootstrap\ensure_post_columns()` in `src/bootstrap.php`, computed as `md5('lamb-' . $origin . '#' . $source_id)`. `$origin` is the exporting site's URL, taken from the manifest's `site.url` or from `--site-url` when given, rather than `ROOT_URL`. `ROOT_URL` is host-derived, so the same install reached on two hostnames would otherwise mint two origins for one site's posts.

**Consequences:** Restored posts carry an empty `feeditem_uuid` and a populated `import_uuid`, so you can edit them without tripping feed-lock. `run_import()` in `src/import.php` gained an optional `$find_existing` callable, so its shared counter and summary machinery can dedupe on either column without duplicating the loop. (Superseded above: the parameter is now required and the `feeditem_uuid` fallback is gone.)

---

## 2026-08-03 — Private pages and preview links carry their own noindex hint

**Status:** Accepted

**Context:** `robots.txt` was the only thing keeping unlisted pages out of search indexes, and it has two gaps. It disallows by *path*, so it cannot describe a preview link — an ordinary permalink plus `?preview=<token>` (`src/lamb.php: preview_token_valid()`). And it is only consulted by crawlers that fetch it first: a preview link is meant to be handed to someone who is not logged in, which is exactly how an unpublished post gets pasted into a page a crawler already follows.

**Decision:** Keep `robots.txt` as the polite-crawler hint (it gains a `Disallow: /*?preview=` wildcard) and add a per-response one that travels with the page. `Response\should_noindex($action, $_GET)` is true for a route registered via `register_private_route()` or for any request carrying a `preview` parameter; `index.php` calls it before dispatching and `Response\mark_noindex()` sends `X-Robots-Tag: noindex, nofollow`. `Theme\the_robots()` emits the matching `<meta name="robots">` in each theme's `<head>`, because a page saved to disk or re-served by a proxy keeps its markup but loses its headers.

The decision runs on the request's action rather than inside the preview-token check, so a handler that dies with its own body (feeds, the export download) is still covered — by the time `preview_token_valid()` runs, the header may already be too late.

The `preview` parameter counts even when empty or wrong. A bad token never grants access, but the URL is still a duplicate of the canonical permalink and has no business being indexed on its own.

**Consequences:** Private routes are noindexed by registration, not by a second hand-kept list — the same registry `robots.txt` is derived from. A new theme that omits `the_robots()` still gets the header, which is the layer that matters for a live crawl. Public pages emit no robots meta at all, so the default stays "index this".

---

## 2026-08-21 — Module-level developer docs live in `src/<module>/README.md`

**Status:** Accepted

**Context:** Between the whole-project guide (`AGENTS.md`) and per-function docblocks there was no place for a *subsystem's* story. Core files run near 50% comment lines, and the same rationale — the feed-ingestion watermark model is the clearest case — was restated across several docblocks, so the copies drift. The `2026-05-29` decision put contributor docs in root-level files but enumerated a fixed set and did not anticipate a per-module tier. The house authoring rule ("a paragraph-long comment is documentation — put it in a README and leave a one-line pointer") already pointed the way; this records where that README goes.

**Decision:** A subsystem's narrative — how its parts fit, the invariants that span more than one function, the non-obvious design — lives in `src/<module>/README.md`, next to the code it explains. Docblocks keep the **contract** (`@param`/`@return`/`@throws`, still required) plus the *local* why (an invariant or coupling read at the point of change); a paragraph that explains the module rather than the function moves to the README with a one-line pointer left on the symbol. Root-level files (`README.md`, `CONTRIBUTING`, `DECISIONS.md`, `DESIGN.md`, `PRODUCT.md`) keep project-wide and cross-cutting material; `docs/` stays end-user only. First applied to `src/network/` (feed ingestion).

**Consequences:** `AGENTS.md` §Comments is updated to match, so the next contributor does not read "docblocks are deliberately expansive" and re-inflate what was moved out. A design essay lives in one place instead of N drifting copies. The trade-off is deliberate: a module README sits one directory from the code — farther than a docblock — so it is for subsystem-spanning rationale, not per-line why, which stays on the symbol. New modules adopt the pattern as they are touched, not in a big-bang sweep.

---

## 2026-08-22 — A tag scan pages on the rowid; the sitemap validates before it builds

**Status:** Accepted

**Context:** With the post table indexed, `/tag/<tag>` and `/sitemap.xml` were the two endpoints still measured in tens of milliseconds at 30,000 posts. Both spent that time on work the response did not need. The tag scan read its matches with `ORDER BY created DESC LIMIT ? OFFSET ?`: nothing indexes `created`, so every page re-scanned and re-sorted the whole table, and a tag covering 4,000 posts took eight of them — 57 ms of a 63 ms response just to produce a list of ids. `/sitemap.xml` built all 25,000 URLs and a 2.6 MB document and only then called `feed_cache()`, so a crawler revalidating a sitemap it already held paid the full build to be answered 304.

**Decision:** `post_ids_by_tag()` keeps its ordered, early-exiting scan for the limited case (the tag feeds take 20) and delegates the exhaustive case to `all_post_ids_by_tag()`, which pages on the rowid (`id > ?`, which SQLite seeks straight to), walks the table once, and orders the survivors in PHP with a stable `arsort()`. The split is deliberate rather than a unification: ordering in SQL is what lets a limited scan stop after one page — since `updated` is indexed, a tag feed answers in a single query — while an exhaustive scan sees every match regardless and only pays for the ordering. Collapsing the two into one keyset scan was measured and rejected: it made the tag feeds nine times slower for no gain.

`respond_sitemap()` now takes its validator from `newest_visible_update()` — one indexed row — instead of reading it off the finished URL list. The value is the same by construction (the list is ordered by `updated` descending and the home entry inherits the newest post's date), and a unit test pins the two together so they cannot drift.

**Consequences:** Measured end-to-end at 30,000 posts, output byte-identical: `/tag/<tag>` 63 ms → 16 ms, a conditional `/sitemap.xml` 69 ms → 1.8 ms; tag feeds and the full sitemap unchanged, and a 200-post install unchanged throughout. A tie on `created` now resolves to ascending id instead of whatever order SQLite emitted, so which page of a tag a reader finds a post on is no longer arbitrary. The unconditional sitemap is still ~90 ms, three quarters of it `sitemap_date()` calling `strtotime()` and `date('c')` 25,000 times; that is the price of DST-correct formatting and was left alone. The remaining structural cost — the `body LIKE` superset scan every tag lookup depends on — needs a tag index or FTS, not another tweak here.

---

## 2026-08-22 — The post table carries explicit indexes, but not on `created`

**Status:** Accepted

**Context:** RedBeanPHP's fluid mode creates columns but never indexes, so `post` had none beyond its primary key and every lookup was a full table scan. That is invisible on a small blog and quadratic-feeling on a large one: on a 30,000-post install, resolving the request path against `slug` cost ~3 ms, the conditional-GET validator's newest-`updated` row ~5 ms, and the two boot-time migration probes (`version IS NULL`, `feed_name IN ('wordpress','known')`) ~3 ms each — every request, including ones that then answered 304. The probes are the sharpest case: they exist so a finished migration does not take a write lock, and once finished they scan the whole table to prove there is nothing left to do.

**Decision:** `ensure_post_columns()` also ensures a single-column index on `slug`, `updated`, `version`, `feed_name`, `draft` and `deleted` (`Bootstrap\POST_INDEXES`). Creation is gated on a read of `sqlite_master` rather than an unconditional `CREATE INDEX IF NOT EXISTS`, because DDL takes a write lock even when it changes nothing — the same reasoning that turned the backfills into probes. A column the install does not have yet is skipped and indexed on the boot after fluid mode adds it.

`created` is deliberately excluded, though it is the column the listings order by. Adding it does make `ORDER BY created DESC` a seek — measured 7.5 ms → 0.03 ms for a home page at 30,000 posts — but SQLite then also prefers it for the search page's `body LIKE` queries, where scanning the index and fetching each row is slower than the table scan it replaces: 5 ms → 11 ms for a rare term, and the search count 5 ms → 15 ms. A covering `(created, draft, deleted, slug)` index fixes the counts but not that, and over-fits to today's exact `public_posts_clause()`. The listings stay on a scan until there is a cheaper answer for search (a proper full-text index being the obvious one).

**Consequences:** Measured end-to-end at 30,000 posts: home 29 ms → 17 ms, a post permalink 21 ms → 9 ms, `/feed` 16 ms → 2 ms, a 404 11 ms → 2 ms. Writes now maintain six indexes — about +0.2 ms per insert and +0.55 ms per update at 30,000 posts — but the create and edit paths both got faster overall, because `finalize_slug()`'s uniqueness probe on `slug` was itself a scan: measured 4.5 ms → 1.6 ms per post created and 6.1 ms → 4.0 ms per post edited, so imports and feed crawls gain rather than pay. The one-time build costs ~14 ms on the first request after upgrade at 30,000 posts and grows the database ~27% (8.4 MB → 10.7 MB). Existing installs pick the indexes up on their next request; the DDL runs once. Any new hot lookup should be added to `POST_INDEXES` rather than left to a scan — and any candidate on `created` needs the search page measured, not assumed.

---

## 2026-05-29 — `docs/` is end-user documentation only

**Status:** Accepted
**Context:** `docs/` is published to GitHub Pages as Lamb's end-user manual. A `contributing.md` page (titled "Milestones") had crept in describing a `gh issue list` + ChatGPT workflow for generating milestone goals — a maintainer task, not something a blog operator needs.
**Decision:** Keep `docs/` scoped to end-user documentation (installation, configuration, features). Contributor- and maintainer-facing material lives in root-level files (`README.md`, `CONTRIBUTING`, `BRANCHES`, `CLAUDE.md`, `DECISIONS.md`, `DESIGN.md`, `PRODUCT.md`). Removed `docs/contributing.md`.
**Consequences:** Clearer audience for the docs site; contributor docs are discoverable in the repo root where they're conventionally expected. New pages added to `docs/` should be evaluated against the end-user scope.

---

## 2026-03-17 — Feed items ingested as drafts by default

**Status:** Accepted
**Context:** Lamb is a single-author writer's blog. Previous behaviour published feed-ingested posts immediately, which prioritised syndication use over authorship. This was the wrong default for a tool aimed at individual writers.
**Decision:** Feed items are now saved as drafts by default. Authors must review and publish them explicitly. Users who want syndication behaviour (publish immediately) can opt out by setting `feeds_draft = false` in the `[feeds]` config section.
**Consequences:** Existing installs with no `feeds_draft` config will silently change behaviour on upgrade — new feed items will land as drafts. Documented in config comments and the docs.

---

## [deduced] SQLite over other databases

**Status:** Accepted
**Context:** Lamb targets self-hosted, single-author use. Ease of setup and portability matter more than scalability.
**Decision:** Use SQLite via RedBeanPHP ORM. No database server required — the database is a single file at `../data/lamb.db`.
**Consequences:** Simple deployment; not suitable for multi-user or high-concurrency scenarios.

---

## [deduced] No MVC framework

**Status:** Accepted
**Context:** Lamb is intentionally minimal. A full MVC framework adds complexity without benefit at this scale.
**Decision:** Use procedural PHP with namespaces. Routing, responses, and views are handled by small namespaced files (`routes.php`, `response.php`, `theme.php`).
**Consequences:** Low overhead; contributors need to understand the custom routing pattern rather than a framework.

---

## [deduced] RedBeanPHP in fluid mode

**Status:** Accepted
**Context:** Schema needs to evolve as the project grows without manual migrations.
**Decision:** Use RedBeanPHP's fluid mode so the SQLite schema evolves automatically as new properties are assigned to beans.
**Consequences:** Convenient during development; schema changes are implicit in code rather than explicit migrations.

---

## [deduced] INI-based configuration stored in the database

**Status:** Accepted
**Context:** Config needs to be editable via the web UI without file system access on the host.
**Decision:** Store raw INI text as a value in the `option` table (key: `site_config_ini`). Parse it on each request.
**Consequences:** No config file management required after initial setup; INI format is human-readable and editable via `/settings`.

---

## [deduced] Feed deduplication via MD5 hash

**Status:** Accepted
**Context:** Feed items must not be re-ingested on subsequent cron runs.
**Decision:** Assign each ingested post a `feeditem_uuid` computed as `md5($feed_name . $item->get_id())`.
**Consequences:** Stable identity across runs; collisions are theoretically possible but negligible in practice for this use case.

---

## [deduced] Slug immutability

**Status:** Accepted
**Context:** Changing a post's slug would silently break existing URLs and incoming links.
**Decision:** Once a slug is set, it is not recalculated even if the post title changes.
**Consequences:** URLs are stable; authors must be deliberate about titles at creation time.

---

## [deduced] PHP 8.4+ with PSR-12

**Status:** Accepted
**Context:** Lamb targets modern PHP for type safety and performance. PSR-12 provides a widely understood coding standard.
**Decision:** Require PHP 8.4+; enforce PSR-12 via PHP_CodeSniffer with PHPCompatibility checks.
**Consequences:** Cannot run on older PHP versions; contributors must run `composer lint` before committing.

---

## [deduced] Draft posts system

**Status:** Accepted (0.7.0)
**Context:** Authors need a way to work on posts without publishing them, and to review feed-ingested content before it appears publicly.
**Decision:** Add a `draft` column to the `post` table. Posts with `draft = 1` are excluded from the homepage, tag pages, atom feed, and search. Drafts are accessible to logged-in authors at `/drafts`. Draft status can be set via YAML frontmatter (`draft: true`) or automatically for feed-ingested items.
**Consequences:** Adds editorial workflow; requires the draft/publish toggle to be surfaced clearly in the edit UI.

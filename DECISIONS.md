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

## 2026-07-29 — Lamb export import identity via a separate `import_uuid` column

**Status:** Accepted

**Context:** Issue #554 asked for a Lamb-to-Lamb importer that restores a `/export` archive back into the database, idempotently. The two existing importers, for WordPress and Known, dedupe re-runs through `feeditem_uuid`, and reusing that column for restored posts was the obvious first idea — one less column.

We rejected it. `lock_if_feed_sourced()` (`src/response/posts.php:329`) sets `feed_locked` on any post with a non-empty `feeditem_uuid` when you edit it, to stop you hand-editing content a feed will overwrite on the next cron run. A restored local post isn't feed-sourced, and nothing will overwrite it, so giving it a `feeditem_uuid` for free dedupe would also silently feed-lock it. That changes edit behaviour for a post that never subscribed to anything.

**Decision:** Add a dedicated `import_uuid` column through `Bootstrap\ensure_post_columns()` in `src/bootstrap.php`, computed as `md5('lamb-' . $origin . '#' . $source_id)`. `$origin` is the exporting site's URL, taken from the manifest's `site.url` or from `--site-url` when given, rather than `ROOT_URL`. `ROOT_URL` is host-derived, so the same install reached on two hostnames would otherwise mint two origins for one site's posts.

**Consequences:** Restored posts carry an empty `feeditem_uuid` and a populated `import_uuid`, so you can edit them without tripping feed-lock. `run_import()` in `src/import.php` gained an optional `$find_existing` callable, so its shared counter and summary machinery can dedupe on either column without duplicating the loop.

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

## [deduced] PHP 8.2+ with PSR-12

**Status:** Accepted
**Context:** Lamb targets modern PHP for type safety and performance. PSR-12 provides a widely understood coding standard.
**Decision:** Require PHP 8.2+; enforce PSR-12 via PHP_CodeSniffer with PHPCompatibility checks.
**Consequences:** Cannot run on older PHP versions; contributors must run `composer lint` before committing.

---

## [deduced] Draft posts system

**Status:** Accepted (0.7.0)
**Context:** Authors need a way to work on posts without publishing them, and to review feed-ingested content before it appears publicly.
**Decision:** Add a `draft` column to the `post` table. Posts with `draft = 1` are excluded from the homepage, tag pages, atom feed, and search. Drafts are accessible to logged-in authors at `/drafts`. Draft status can be set via YAML frontmatter (`draft: true`) or automatically for feed-ingested items.
**Consequences:** Adds editorial workflow; requires the draft/publish toggle to be surfaced clearly in the edit UI.

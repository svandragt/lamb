# Decision log

Architectural and product decisions for the Lamb project.

Entries marked **[deduced]** were reconstructed from code and history rather than recorded at the time.

---

## 2026-07-26 — Export format is front-matter Markdown plus a JSON manifest

**Status:** Accepted

**Context:** Issue #440 asked for an own-your-data export and left the format open: WXR, JSON, or a Markdown bundle. The original objection to an export at all — "SQLite is already browsable, and Lamb has no lock-in" — had weakened. Lamb had gained two importers, for WordPress and Known, and still had no way out, and the only backup path was copying the binary `data/lamb.db` and hoping the RedBean schema still matched on restore.

We considered an intermediate representation: convert everything to a Lamb-specific JSON first, then into the system. The code turned out to already have an answer. Neither importer builds a JSON IR; both converge on `Import\build_post_body()`, which emits Markdown with YAML front matter. That's precisely what `Post\parse_matter()` reads back, and precisely what the `post.body` column stores.

**Decision:** The export is a zip of `posts/YYYY/MM/<slug>.md` (each post's stored body, byte for byte), `assets/YYYY/MM/…` (only files the posts reference), and a `manifest.json`. Post bodies are written verbatim rather than re-serialised, so the export can't drift from what an import produces. The Markdown files carry only what `parse_matter()` understands. Everything Lamb-internal — id, timestamps, draft and deleted state, feed provenance — lives in the manifest, and the manifest's field list is an explicit allowlist, so preview tokens (credentials for unpublished posts) can never travel with an archive. Drafts and trashed posts are exported and flagged.

We rejected WXR. It's WordPress's schema, it has no clean home for Lamb's draft, deleted, and feed state, and the Known importer had already shown that real-world "WXR" is a partial veneer, so emitting it means choosing which dialect to be wrong in. Conversion to WXR, Hugo, or Jekyll belongs in a separate converter that consumes this format. We also rejected a second flat-JSON export variant for now: a programmatic consumer reads `manifest.json` for metadata and the `.md` files for content, and two formats would mean two things to keep in sync.

`ext-zip` is checked at runtime rather than added to the `composer.json` require block, so an existing install upgrading into this feature can't fail `composer install` over an extension it never needed.

**Consequences:** The format both importers already emit is now documented in `docs/export.md` and versioned as `lamb-export/1`, rather than being an internal detail. A future Lamb-to-Lamb importer — the actually-missing piece for backup and restore — has a specified input to read. Additive manifest fields stay within version 1, and consumers must ignore unknown fields.

---

## 2026-05-29 — `docs/` is end-user documentation only

**Status:** Accepted

**Context:** `docs/` is published to GitHub Pages as Lamb's end-user manual. A `contributing.md` page titled "Milestones" had crept in, describing a `gh issue list` and ChatGPT workflow for generating milestone goals. That's a maintainer task, not something a blog operator needs.

**Decision:** Keep `docs/` scoped to end-user documentation: installation, configuration, and features. Contributor-facing and maintainer-facing material lives in root-level files (`README.md`, `CONTRIBUTING`, `BRANCHES`, `CLAUDE.md`, `DECISIONS.md`, `DESIGN.md`, `PRODUCT.md`). Removed `docs/contributing.md`.

**Consequences:** The docs site has a clearer audience, and contributor docs are discoverable in the repo root where people conventionally expect them. Evaluate new pages added to `docs/` against the end-user scope.

---

## 2026-03-17 — Feed items ingested as drafts by default

**Status:** Accepted

**Context:** Lamb is a single-author writer's blog. Previous behaviour published feed-ingested posts immediately, which prioritised syndication over authorship. That was the wrong default for a tool aimed at individual writers.

**Decision:** Save feed items as drafts by default. Authors review and publish them explicitly. Users who want syndication behaviour, publishing immediately, can opt out by setting `feeds_draft = false` in the `[feeds]` config section.

**Consequences:** Existing installs with no `feeds_draft` config silently change behaviour on upgrade, because new feed items land as drafts. Documented in the config comments and the docs.

---

## [deduced] SQLite over other databases

**Status:** Accepted

**Context:** Lamb targets self-hosted, single-author use. Ease of setup and portability matter more than scalability.

**Decision:** Use SQLite through the RedBeanPHP ORM. No database server is required, and the database is a single file at `../data/lamb.db`.

**Consequences:** Deployment is simple. Lamb isn't suitable for multi-user or high-concurrency scenarios.

---

## [deduced] No MVC framework

**Status:** Accepted

**Context:** Lamb is intentionally minimal. A full MVC framework adds complexity without benefit at this scale.

**Decision:** Use procedural PHP with namespaces. Small namespaced files handle routing, responses, and views: `routes.php`, `response.php`, `theme.php`.

**Consequences:** Overhead is low. Contributors need to understand the custom routing pattern rather than a framework.

---

## [deduced] RedBeanPHP in fluid mode

**Status:** Accepted

**Context:** The schema needs to evolve as the project grows, without manual migrations.

**Decision:** Use RedBeanPHP's fluid mode, so the SQLite schema evolves automatically as new properties are assigned to beans.

**Consequences:** Convenient during development. Schema changes are implicit in code rather than explicit migrations.

---

## [deduced] INI-based configuration stored in the database

**Status:** Accepted

**Context:** Config needs to be editable through the web UI, without file system access on the host.

**Decision:** Store raw INI text as a value in the `option` table, under the key `site_config_ini`. Parse it on each request.

**Consequences:** No config file management is required after initial setup, and the INI format is human-readable and editable at `/settings`.

---

## [deduced] Feed deduplication through MD5 hash

**Status:** Accepted

**Context:** Feed items must not be re-ingested on subsequent cron runs.

**Decision:** Assign each ingested post a `feeditem_uuid`, computed as `md5($feed_name . $item->get_id())`.

**Consequences:** Identity is stable across runs. Collisions are theoretically possible but negligible in practice for this use case.

---

## [deduced] Slug immutability

**Status:** Accepted

**Context:** Changing a post's slug would silently break existing URLs and incoming links.

**Decision:** Once a slug is set, don't recalculate it, even if the post title changes.

**Consequences:** URLs are stable. Authors must be deliberate about titles at creation time.

---

## [deduced] PHP 8.2+ with PSR-12

**Status:** Accepted

**Context:** Lamb targets modern PHP for type safety and performance. PSR-12 provides a widely understood coding standard.

**Decision:** Require PHP 8.2+, and enforce PSR-12 through PHP_CodeSniffer with PHPCompatibility checks.

**Consequences:** Lamb can't run on older PHP versions. Contributors must run `composer lint` before committing.

---

## [deduced] Draft posts system

**Status:** Accepted (0.7.0)

**Context:** Authors need a way to work on posts without publishing them, and to review feed-ingested content before it appears publicly.

**Decision:** Add a `draft` column to the `post` table. Exclude posts with `draft = 1` from the homepage, tag pages, Atom feed, and search. Make drafts available to logged-in authors at `/drafts`. Set draft status through YAML front matter (`draft: true`), or automatically for feed-ingested items.

**Consequences:** Adds an editorial workflow, and requires the edit UI to surface the draft and publish toggle clearly.

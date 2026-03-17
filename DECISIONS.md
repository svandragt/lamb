# Decision Log

Architectural and product decisions for the Lamb project.

Entries marked **[deduced]** were reconstructed from code and history rather than recorded at the time.

---

## 2026-03-17 — Feed items ingested as drafts by default

**Status:** Accepted
**Context:** Lamb is a single-author writer's blog. Previous behaviour published feed-ingested posts immediately, which prioritised syndication use over authorship. This was the wrong default for a tool aimed at individual writers.
**Decision:** Feed items are now saved as drafts by default. Authors must review and publish them explicitly. Users who want syndication behaviour (publish immediately) can opt out by setting `feeds_draft = false` in the `[feeds]` config section.
**Consequences:** Existing installs with no `feeds_draft` config will silently change behaviour on upgrade — new feed items will land as drafts. Documented in config comments and wiki.

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

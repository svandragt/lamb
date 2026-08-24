# Bug-scan log

Tracks what the scheduled bug-scan routine (see the scheduled task prompt) has
covered, so each run can pick categories that haven't been looked at recently
instead of re-treading the same ground. This codebase is mature and
well-tested — most categories return little after their first pass.

## Categories

1. Docblock absolute claims (`must`/`never`/`always`/…) lacking tests.
2. Code that recomputes state another component already knows.
3. Guard-clause diffs across name-similar function families.
4. Unchecked return values on side-effecting calls.
5. `R::find`/`R::getAll` without `LIMIT` on anonymous routes.
6. Executable HTTP harness for upload/auth/header findings.
7. Property fuzzing (involution, idempotence, round-trip, superset) once
   file-level passes dry up.
8. Skip files listed under "Issue-dense" below.
9. `phpstan.neon` ignore-path review.

## Run log

### 2026-08-24

**Covered:** 3 (guard-clause diffing), 4 (unchecked return values), 1
(docblock claims sweep). Also spot-checked 5 and 9 directly (not full passes —
see "Ruled out" below).

**PRs opened:**
- [#735](https://github.com/svandragt/lamb/pull/735) — category 3.
  `Lamb\Micropub\LambMicropubAdapter::updateCallback()` checked post
  existence/deleted-state *before* the `update` scope, unlike
  `deleteCallback()`/`undeleteCallback()`. An insufficient-scope token got a
  different response (`insufficient_scope` 403) for a URL naming an existing
  draft/scheduled/published post than for one naming no post
  (`invalid_request`) — letting any token, regardless of its own scope,
  enumerate hidden post ids. Fixed by checking scope first, matching the
  siblings.
- [#736](https://github.com/svandragt/lamb/pull/736) — category 4.
  `Lamb\Export\build_export_archive()` (`src/export.php`) discarded
  `ZipArchive::addFromString()`'s return value for a post's body file and for
  `manifest.json`, unlike the already-checked `addFile()` (assets) and
  `close()` calls in the same function. A failed buffer write would still get
  a manifest entry, so an export could report success while silently
  dropping a post. Fixed by checking both calls and throwing
  `RuntimeException`, matching the existing pattern.

**Ruled out (do not re-flag without new evidence):**
- Category 5 — every `R::find`/`R::getAll`/`R::findAll` call without an
  explicit `LIMIT` in `src/` was checked (`src/lamb.php` `get_all_redirects()`
  line 986, `flatten_redirects()` line 1079; `src/response/discovery.php`
  `sitemap_urls()` line 60). None run on an anonymous route with unbounded
  growth: `get_all_redirects()` is only called from the login-required
  settings page; `flatten_redirects()` only from `/_cron`;
  `sitemap_urls()`'s docblock already documents and bounds the memory cost
  (reads 3 columns, not full beans — ~130ms at 30k posts) and is not a bug.
  Everything else already carries a `LIMIT` or a bounding `WHERE`.
- Category 9 — `phpstan.neon`'s one `ignoreErrors` block (the
  `literal-string` suppression for `R::find()` in `response.php`,
  `response/feeds.php`, `theme.php`) is scoped to exactly the files it
  justifies and the comment above it is accurate — SQL is assembled from
  constant fragments with every value bound as a parameter. Nothing to
  remove. `phpstan-baseline.neon` is empty. No inline `@phpstan-ignore`
  suppressions exist anywhere in `src/`. Re-check only if the ignore block
  grows or a baseline entry appears.
- Category 3 — `redirect_created()`/`redirect_edited()` vs.
  `redirect_deleted()`/`redirect_restored()` (`src/response/posts.php`) look
  inconsistent (the first two call `require_csrf()` unconditionally; the
  latter two check `empty($_POST)` first) but are not a bug: create/edit are
  only invoked by their callers (`respond_home()`/`respond_edit()`) after
  those callers already confirmed `!empty($_POST)`, while delete/restore are
  registered directly as routes and need the internal check to avoid a hard
  405 on an accidental GET. Do not re-flag this family without new evidence.
- Category 3 — `response/upload.php`, `response/export.php`,
  `respond_checkbox()` (`response/posts.php`) all call
  `Security\require_login()` and deliberately skip `require_csrf()`, per the
  documented `SameSite=Strict` rationale in `AGENTS.md`. Confirmed all three
  actually call `require_login()`; none is missing it.
- Category 4 — `src/response/upload.php`, `src/restore.php`,
  `src/network/*.php`, `src/micropub.php` (except the export.php finding
  above) all check side-effecting return values already, or the failure is a
  documented, intentional fallback (e.g. `respond_upload()`'s
  original-bytes-on-conversion-failure path in `AGENTS.md`).
- Category 1 — scanned all ~323 must/never/always/only/guarantee/cannot hits
  across 32 files in `src/`; deep-verified ~20 of the most concrete
  security/correctness claims (SSRF guards in `http.php`, open-redirect guard
  in `response/auth.php`, login CSRF double-submit, slug uniqueness, export
  never leaking preview tokens, restore never overwriting assets,
  content-timestamp monotonicity, UTF-8 repair, checkbox index mapping). No
  mismatch between a docblock's claim and the implementing code found.

**Issue-dense files:** none identified yet — this is the first run.

**Suggested refinement:** the "no LIMIT on anonymous routes" category (5) and
the phpstan-ignore-review category (9) are both now fully triaged with no
outstanding findings and nothing left to periodically re-check unless the
code around them changes (a new `ignoreErrors` entry, a new unbounded query).
Future runs could treat 5 and 9 as "check only on a diff touching the
relevant files" rather than a full sweep every time, freeing more of the
hour for categories 2, 6, 7, and 8, which have not had a first pass yet.
Category 6 (executable HTTP harness) is worth prioritizing next since this
run's two fixes (Micropub scope ordering, export write-path) both touch
security-relevant surfaces that a real end-to-end harness would exercise
more thoroughly than the unit-level regression tests added here.

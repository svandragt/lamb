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

### 2026-08-25

**Covered:** 2 (recomputed state), 6 (executable HTTP harness for
upload/auth/header findings), 7 (property fuzzing) — the three categories the
2026-08-24 run flagged as not yet having a first pass, per its suggested
refinement below.

**PRs opened:**
- [#740](https://github.com/svandragt/lamb/pull/740) — category 2.
  `Lamb\Response\redirect_edited()` (`src/response/posts.php`) independently
  rejected the whole edit — discarding the author's title/body changes — when
  the parsed slug matched a registered route name, while
  `Lamb\Post\finalize_slug()` (already run via `save()`'s `finalize_slug`
  step, and already relied on by `redirect_created()` for the same case) just
  suffixes such a slug with the post's id. Renaming a post's title to a
  reserved route name (`Search`, `Login`, `Settings`, …) silently dropped the
  edit instead of saving it, unlike creating a new post with the same title.
  Fixed by deleting the redundant check and letting the edit path delegate to
  `finalize_slug()` like create already does.
- [#741](https://github.com/svandragt/lamb/pull/741) — category 7.
  `Lamb\parse_tags()` (`src/lamb.php`) hashtag-links a `#word` at the start of
  any text segment, including the visible text of a link Markdown itself just
  built (`[#42](https://.../issues/42)` → `<a href="...">#42</a>`), nesting a
  second `<a>` inside the author's own `<a>`. HTML5 parsers de-nest that,
  silently breaking the intended link — a common shape on a developer's blog
  (referencing a GitHub issue/PR by number as link text). Also an idempotence
  violation (`parse_tags(parse_tags(x)) != parse_tags(x)` for an already-linked
  hashtag). Fixed by tracking anchor depth while walking the tag-split
  segments and skipping hashtag-linking inside an `<a>...</a>` pair.
- [#742](https://github.com/svandragt/lamb/pull/742) — category 6.
  `Lamb\Response\record_login_failure()` (`src/response/auth.php`) is a plain
  read-increment-write on the per-IP brute-force counter with no locking —
  a lost-update race. Verified live: driving the real `/login` route with
  `curl_multi` against a `php -S` server, 30 concurrent wrong-password POSTs
  from one IP let 29 through as genuine `password_verify()` attempts against
  the documented 10-per-window cap. `R::begin()`/`R::commit()` are no-ops in
  this app's fluid RedBeanPHP mode, so fixed by taking SQLite's write lock
  directly with `BEGIN IMMEDIATE` before the read, skipping (not crashing on)
  an attempt that finds the lock already held.

**Ruled out (do not re-flag without new evidence):**
- Category 2 — `count_drafts()`/`count_trash()`/`count_scheduled()` vs. their
  listing counterparts (`response/feeds.php`) share the same SQL constants, no
  independent logic to drift. Webmention's per-row visibility re-check vs.
  WebSub's scheduled-publish sweep (`webmention.php`, `websub.php`) answer
  different questions by design. SimplePie vs. JSON Feed ingestion funnel
  through the same `ingest_item()`/`prepare_item()` spine. OpenGraph image
  dimensions vs. post-body image dimensions serve different fields, not the
  same derived value. The sitemap validator's duplicate-looking derivation is
  explicitly pinned together by a unit test. Checkbox toggle client/server
  split already trusts the server's index — correct pattern, not drift.
- Category 6 — upload extension/content-type allowlist (re-encodes JPEG/PNG
  through GD, blocks SVG, `sha1`-seeded filenames defeat path traversal via
  client filename); every dynamic `Location:` header goes through
  `Http\sanitize_location()` or a regex-scrubbed `permalink()` slug, so no CR/LF
  injection path found; the open-redirect guard in
  `response/auth.php`'s `local_redirect_target()` re-read and still intact
  (already verified in the 2026-08-24 run, not re-flagged as new); every
  security-sensitive comparison (CSRF, preview token, Micropub `me`) uses
  `hash_equals()`; SSRF surfaces (`fetch_guarded()`, webmention, websub) still
  re-validate IPs per redirect hop.
- Category 7 — `slugify()` idempotent across ASCII/Unicode/emoji/punctuation
  inputs; `og_escape()` idempotent by design (decode-then-encode); front-matter
  `build_matter()`/`parse_matter()` round-trips correctly including multi-value
  fields and Unicode; export→restore round-trip verified end-to-end through
  two separate SQLite databases (slug/body/created/draft/title/transformed all
  match); checkbox toggle's "permissive superset + re-render to verify" design
  is robust by construction against decoys. `restore_code_blocks()`'s
  placeholder-collision risk is theoretical only — no reachable path found to
  get an unescaped literal placeholder string past Parsedown's/Phiki's escaping.

**Issue-dense files:** none newly identified — the three fixes above landed in
three different files (`response/posts.php`, `lamb.php`, `response/auth.php`)
with no other findings nearby in any of them.

**Environment note:** this sandbox cannot run `composer install` — dev
dependencies (codeception, phpunit, phpcs, phpstan) fail to download
("Could not authenticate against github.com") through the environment's
proxy, even though a partial/stale `vendor/` with empty package directories is
present. `composer install --no-dev --prefer-source` (used by the category 6
and 7 investigations, per their reports) works around it for read-only
investigation, but none of this run's fixes could be validated by actually
running `vendor/bin/codecept run Unit`, `composer lint`, or `composer
analyse` — only `php -l` and hand-rolled standalone scripts reimplementing
the changed logic. **Future runs should try `composer install --no-dev
--prefer-source` (or `--prefer-source` alone) up front**, before falling back
to static reasoning, and should still flag in each PR description whether the
real test suite ran.

**Suggested refinement:** categories 2, 6, and 7 are no longer "first pass"
categories — each found and fixed one real, non-trivial bug (a UX/data-loss
bug, a broken-markup/idempotence bug, and a verified security race,
respectively), a notably higher hit rate than the file-level categories (1, 3,
4) had on their second pass. Category 6 in particular paid for its
higher setup cost (installing deps, standing up a live server, driving it
with `curl_multi`) by catching a bug neither static reading nor the unit-test
suite would have surfaced — a plain code read of `record_login_failure()`
looks correct; the race is only visible under real concurrency. Next run:
prioritize re-checking 2, 6, and 7 again with fresh eyes (the codebase changes
between runs, and category 6's harness approach generalizes to other
concurrent-write paths — e.g. any other read-modify-write on an `option` row
or similar shared counter) over re-treading 1/3/4/5/9, which are at low
marginal yield until the code they cover changes materially.

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

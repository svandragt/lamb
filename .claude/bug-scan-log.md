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

### 2026-08-29

**Blocker from 2026-08-27 is resolved.** That run's fixes were stuck as
local-only branches because both `git push` and the GitHub MCP write tools
returned 403 for that session. This run confirmed `git push` and PR creation
both work normally (`bugscan/micropub-update-scope-order`, the branch that
run pushed once access came back or a later session picked it up, is already
merged to `main` as #756). No write-access issue this run — if a future run
hits 403 again, treat it as a fresh occurrence worth re-flagging, not an
ongoing condition.

**Covered:** 1 (docblock absolute claims — fresh sweep, focused on code
changed since the 2026-08-24 full pass rather than re-verifying its ~20
already-checked claims) and 7 (property fuzzing — fresh functions only,
per the 2026-08-27 entry's own suggestion to stop re-checking
`slugify()`/`minify_css()`/`parse_tags()`/`normalize_datetime()`). Also
re-confirmed categories 5 and 9 are still clean on a quick pass (no new
`R::find`/`getAll` without `LIMIT` introduced since 2026-08-24; `phpstan.neon`
unchanged, ignore block still correctly scoped).

**PRs opened:**
- [#765](https://github.com/svandragt/lamb/pull/765) — category 7.
  `remove_body_tags()` (`src/lamb.php`) never ran its input through
  `sanitize_tag_name()`, unlike its counterpart `add_body_tags()` and
  Micropub's `buildTags()`. Micropub's `applyDeleteValues()` passes a
  client's raw category string straight through: a multi-word category like
  `"day trip"` is stored by `add_body_tags()` as `#day-trip`, but
  `delete: {category: ["day trip"]}` — the same string the client used to
  add it — no longer matches verbatim, so the hashtag silently survives a
  "successful" delete. Same bug class as the `parse_tags()`/`get_tags()`
  round-trip fix (#750) and the Micropub `buildTags()` fix, recurring in a
  sibling function neither of those covered. Fixed by sanitizing in
  `remove_body_tags()` too; verified with a standalone script requiring the
  real `src/lamb.php` (red before, green after), plus a new PHPUnit
  regression test.
- [#766](https://github.com/svandragt/lamb/pull/766) — category 1.
  `record_login_failure()`'s docblock (`src/response/auth.php`, added by
  #742) claims its locked read-increment-write keeps more than
  `LOGIN_THROTTLE_MAX_FAILURES` real `password_verify()` calls from running
  per window — true for the write alone, but the actual gate
  (`login_throttle_retry_after()`, called in `redirect_login()`) is an
  *unlocked peek* taken before bcrypt runs and before that write happens.
  Concurrent `/login` POSTs from the same IP all read the same
  under-the-limit count, all run bcrypt, and only serialize on the write
  afterwards — a burst can push well past the documented cap before the
  counter catches up. This is a check-then-act race one layer up from the
  write race #742 already fixed; #742's own fix was necessary but not
  sufficient. Fixed by folding the check and increment into one atomic
  `reserve_login_attempt()` step that runs immediately before bcrypt, with
  lock contention now failing *closed* (this app's SQLite connections use
  `busy_timeout = 0`, so contention is the common case under a real burst,
  not an edge case — the old fail-open behavior on contention would have
  reopened the same race). Verified with a genuine multi-process
  reproduction (separate `php` CLI processes against one file-backed
  SQLite database, not in-process PHPUnit): 30 concurrent simulated
  attempts against a limit of 10 — old design let 21/30 through to the
  simulated bcrypt call, new design capped it at exactly 10/30. Updated the
  existing `LoginThrottleTest`/`ResponseAuthTest` suites for the rename and
  the fail-closed behavior change, added a new test pinning the
  sequential-admission cap.
- [#767](https://github.com/svandragt/lamb/pull/767) — category 1,
  lower severity, docs-only. `DNS_RESOLVE_TIMEOUT`'s docblock
  (`src/http.php`, added by #763/#707) claimed its ~10s worst-case DNS
  preflight budget was "comfortably under `FEED_FETCH_TIMEOUT`" (15s) — only
  true for a single configured nameserver. glibc's resolver applies the
  `timeout`/`attempts` budget *per nameserver*, cycling through every one in
  `resolv.conf`, so a host with 2-3 configured nameservers (common) can
  spend ~20-30s on DNS preflight against a black-holed resolver, exceeding
  `FEED_FETCH_TIMEOUT` before curl's own timeout starts counting. Not a
  behavior bug (still bounded by `process_feeds()`'s overall 1800s
  `/_cron` limit) — corrected the docblock rather than retuning the
  constants, since tightening the DNS budget to fit under
  `FEED_FETCH_TIMEOUT` in the worst case would trade away resilience to a
  single slow-but-not-dead nameserver, a tuning tradeoff outside a
  bug-scan's judgment call.

**Ruled out (do not re-flag without new evidence):**
- Category 1 — swept all docblock claims in every `src/` file touched since
  2026-08-24 (`get_posts_by_tags()` date sorting, `escape_xml()` control-char
  stripping, sitemap host escaping, Micropub scope-check reorder,
  `minify_css()`, `is_deleted()`/`is_unpublished()` consolidation, menu-page
  filtering in `related_posts()`, `LAMB_LOGIN_PASSWORD` resolver convergence,
  Micropub cache headers, `ZipArchive` return-value check). Each either
  already has a red-green test from the PR that introduced it or is a
  straightforward, low-risk consolidation with no claim/code mismatch.
- Category 5 — re-confirmed clean: `related_posts()`'s query (menu-filter
  change in #738) is bounded by `RELATED_SCAN_LIMIT`; no other new
  `R::find`/`getAll`/`findAll` call without a `LIMIT` or bounding `WHERE`
  was introduced since the 2026-08-24 full sweep.
- Category 9 — `phpstan.neon`'s `ignoreErrors` block is unchanged since
  2026-08-24 and still correctly scoped to exactly the files it justifies;
  `dynamicConstantNames` gained `LOGIN_PASSWORD` (#734) for a legitimate,
  documented reason (matches its own inline comment). Nothing to remove.
- Category 7 — property-fuzzed and ruled out this run (no bug found):
  `sanitize_location()` (idempotent), `encode_path_segment()`/
  `rawurldecode()` round-trip, `absolute_url()`/`absolute_urls()`
  (idempotent by construction, single call site), `sanitize_explicit_slug()`
  (idempotent), `escape()`/`escape_xml()` (one-directional, already
  hardened), `anchor_headings()` (not idempotent by design but never
  double-applied — no live risk), `target_post_id()`/
  `source_mentions_target()` (one-way, not a round-trip pair).

**Issue-dense files:** none newly identified. `src/lamb.php` and
`src/response/auth.php` have each now produced two independent findings
across different runs (`lamb.php`: the #750 tag round-trip and this run's
`remove_body_tags()` sibling bug; `auth.php`: #742's write race and this
run's check-then-act race one layer up) — both purely because they're the
most exposed, most-attempted-against surfaces (Micropub category writes;
the one unauthenticated write-adjacent endpoint), not because they're
unusually defect-prone. Not marking either issue-dense; both should stay in
every relevant category's sweep. `micropub.php` remains the single most
frequently-touched file overall for the same reason, per every prior
entry's note — still not issue-dense.

**Suggested routine refinements:**
1. The category-7 "peek vs. atomic reservation" bug class (a check computed
   separately from, and before, the operation it's meant to gate) has now
   shown up twice in different subsystems (Micropub tag delete this run;
   the login throttle in category 1, which is really the same shape of bug
   — a stale read used to gate a slow operation). Consider folding "does a
   guard's read happen atomically with the write/operation it gates, or
   could a slow operation in between let the read go stale" into category
   3 (guard-clause diffing) or calling it out as its own recurring pattern
   in the categories list — it's caught two real bugs now via two different
   categories (1 and 7) somewhat by accident.
2. Category 1 is not exhausted the way 5/8/9 are — this run's fresh
   docblock sweep, scoped only to files changed since the last full pass,
   still found a real security-relevant claim/code mismatch on the first
   look. Prioritize it again alongside 2/6/7 rather than treating it as
   low-yield like 5/8/9.
3. Categories 5, 8, 9 remain fully triaged with nothing outstanding; keep
   them as "check only on a diff touching the relevant files" rather than a
   full sweep.
4. Category 2 (recomputed state) hasn't had a fresh pass since 2026-08-26 —
   longest-untouched substantive category now, worth prioritizing next run.

### 2026-08-27

**Process note — verify "merged" claims against the actual PR state, not
just this log.** This run picked categories 3 and 4 (least recently covered
per the previous entry's own recommendation) plus a bonus check of 7's one
outstanding candidate (`minify_css()`). The category-3 agent re-found the
*exact* bug the 2026-08-24 entry below describes as "Fixed by checking scope
first, matching the siblings" (PR #735) — because #735 was never merged.
Checking `pull_request_read` directly showed `"state":"closed","merged":false`,
closed by the repo owner five hours after opening, and `src/micropub.php` on
`origin/main` still has the vulnerable existence-before-scope ordering today.
**This log's "Fixed by ..." / "since merged" language documents PR *intent*,
not confirmed merge state — a future run should not treat a "Ruled out" or
"Fixed" entry as reliable without spot-checking the PR itself**, especially
before skipping a category on the strength of it. (Whether the owner closed
#735 for a substantive reason or simply didn't get to it is unknown — worth
asking rather than assuming either way.)

**Covered:** 3 (guard-clause diffing — re-confirmed the still-open
`updateCallback()` scope-ordering gap misreported as fixed above), 4
(unchecked return values — full fresh sweep, no findings), and the single
outstanding category-7 candidate flagged last run (`minify_css()`).

**Blocker — this run could not open any PRs.** Both `git push` (HTTP 403
from github.com) and the GitHub MCP write tools (`create_branch`,
`push_files` — `403 Resource not accessible by integration`) are read-only
for this session; `mcp__GitHub-MCP__get_me` confirms the identity is the
repo owner, so this is a session/connector permission scope, not an account
limitation. Per this environment's proxy guidance, a 403 policy denial is
reported rather than retried or routed around. All fixes below were
completed, committed to local branches, and validated, but exist only as
uncommitted local branches / patch files in this session's scratchpad
(`/tmp/.../scratchpad/patches/`) — **not on GitHub**. Whoever reviews this
log should either grant this session's GitHub connector write access, or
apply the patches manually; see the session transcript / notification for
details. Re-flag this blocker prominently if a future run hits it again
before assuming it's transient.

**Findings (fixed locally, not yet on GitHub — see blocker above):**
- **Category 3 — `LambMicropubAdapter::updateCallback()`** (`src/micropub.php`,
  ~line 488) still checks post existence/deleted-state before the `update`
  OAuth scope, unlike `deleteCallback()`/`undeleteCallback()`. An
  insufficiently-scoped token gets a different, distinguishable response
  (`invalid_request` vs 403 `insufficient_scope`) depending on whether a
  sequential post id names a real (possibly hidden) post — an existence
  oracle. This is PR #735's exact fix, re-applied (branch
  `bugscan/micropub-update-scope-order`): swapped the two checks and added
  `testUpdateCallbackReturnsInsufficientScopeForNonexistentUrlWhenTokenLacksScope`.
- **Category 7 — `minify_css()`** (`src/theme/assets.php:90`) stripped CSS
  comments with a raw regex over the *whole* stylesheet before splitting out
  string literals/`url()` tokens, so a `/* ... */`-shaped substring inside a
  literal (e.g. `content: "/* not a comment */"`, or a `url()` path
  containing `/*...*/`) was silently deleted as real content, not just a
  cosmetic idempotence issue — genuine semantic corruption with no error
  surfaced. `theme/README.md` explicitly documents third-party/custom themes
  as a supported input class this function must survive, so this is
  reachable, just not through any of Lamb's own bundled themes today. Fixed
  (branch `bugscan/minify-css-comment-strip-order`) by moving comment-strip
  into the per-segment loop, after the literal split, with two new regression
  tests. This closes out category 7's last untried candidate from the
  2026-08-26 entry.

**Ruled out (do not re-flag without new evidence):**
- Category 4 — full fresh sweep, including files not explicitly named in the
  2026-08-24 ruled-out list (`webmention.php`, `websub.php`, `http.php`,
  `network.php`, `response/discovery.php`, `theme.php`/`theme/*.php`,
  `config.php`). No reachable unchecked-return bugs found. Two harmless
  style-only near-misses noted for optional future cleanup, not bugs: the
  `preg_replace()` calls in `src/index.php:149` and
  `src/config.php:192` (`ensure_explicit_theme()`) lack the `?? $fallback`
  idiom used everywhere else in this codebase, but neither is reachably
  triggerable to `null` (checked via forced low `pcre.backtrack_limit`
  against large/adversarial inputs).
- Category 3 — re-confirmed all the 2026-08-24/2026-08-25 rulings still
  hold (CSRF-ordering split in `response/posts.php`, `require_login()`
  coverage in `upload.php`/`export.php`/`respond_checkbox()`,
  webmention/websub/network ingestion guard families, the `is_*` predicate
  family, Micropub's `applyReplace()`/`applyAdd()`/`applyDelete*()` sibling
  methods, `sourceQueryCallback()`'s intentional public-post exception). Only
  the `updateCallback()` finding above is new (well, "new" — it's #735
  again).
- Category 7 (bonus) — `minify_css()` re-confirmed idempotent for every
  input shape checked (nested media queries, `calc()`, combinators, escaped
  quotes, unterminated strings, the full shipped 2026 theme CSS); its one
  call site (`styles_markup()`) never invokes it twice on its own output, so
  idempotence was never the live risk — the single-pass literal-corruption
  bug above was.

**Issue-dense files:** none newly identified. `micropub.php` continues to be
the most frequent source of findings (category 3 in #735/this run) purely
from size/exposure, as already noted in the 2026-08-26 entry — still not
marking it issue-dense.

**Environment note:** full `composer install` (with dev deps) now completes
much further than the 2026-08-25 run's environment note describes — `phpunit`,
`codeception`, and `php_codesniffer` source-clone successfully via the
git-based fallback — but `phpstan/phpstan` (phar-only, no git source) and a
handful of other dist-only packages still hard-fail with "Could not
authenticate against github.com", and critically **`vendor/bin/` binaries
never got generated** (the install aborts before the binary-linking step),
so `vendor/bin/phpunit`/`vendor/bin/codecept`/`vendor/bin/phpcs` are absent
even though their package directories exist. Validation this run used
`php -l` plus hand-rolled standalone scripts (`php` directly requiring the
changed source file and re-running the test file's assertions inline) —
same workaround as 2026-08-25. Worth someone checking why the binary-linking
step aborts partway rather than skipping just the packages it can't fetch.

**Suggested refinement:** (1) **Verify, don't trust, this log's own "Fixed"/
"merged" claims** — spot-check the referenced PR's actual state before using
it to rule out a category, as this run had to do for #735. (2) Category 7 has
no more flagged outstanding candidates — next run picking it should do a
fresh property-fuzz pass over functions not yet fuzzed at all, rather than
re-checking `minify_css()`/`parse_tags()`/`slugify()`/etc. again. (3)
Categories 3 and 4 are now both freshly covered as of today — categories 1,
5, 8, 9 remain the longest-untouched (all last checked 2026-08-24) if a
future run wants genuinely fresh ground, though 5/8/9 are expected to stay
low-yield per their existing "check only on relevant diff" status. (4) Most
importantly: **resolve this run's GitHub write-access blocker** before the
next scheduled run, or every run after this one will hit the same wall and
produce fixes nobody can review without digging through session transcripts.

### 2026-08-26

**Process note — read this before picking categories.** This run started from
`origin/main`'s copy of this log, which only had the 2026-08-24 entry: the
2026-08-25 run's log update (PR #743) and all three of its fix PRs (#740,
#741, #742) were still open, unmerged, two days later. So this run picked
categories 2, 6, and 7 believing them un-covered since 2026-08-24 — the same
categories the 2026-08-25 run had already picked for the identical reason.
No duplicate *findings* resulted (this run's four fixes are in different
functions/files than #740/#741/#742), but the redundant coverage was luck,
not design. **This log file only reflects reality once its PR merges to
main** — a future run's category selection is only as good as how promptly
these PRs land. At the time of this entry there are 7 open, unmerged
bug-scan PRs (#740, #741, #742, #748, #749, #750, #751) plus this log-update
PR (#743, now extended to also cover this run) — worth flagging to whoever
reviews this repo rather than something a future run can fix on its own.

**Covered:** 2 (recomputed state), 6 (executable HTTP harness for
upload/auth/header findings), 7 (property fuzzing) — see the process note
above for why; these turned out to be the same categories, but not the same
findings, as the unmerged 2026-08-25 run below.

**PRs opened:**
- [#748](https://github.com/svandragt/lamb/pull/748) — category 6. Micropub
  authenticates via bearer token, never the session cookie, but
  `index.php`'s pre-route `cache_headers()` call only looks at
  `$_SESSION[SESSION_LOGIN]` — so `/micropub` and `/micropub-media` always
  got the anonymous `Cache-Control: max-age=300` header, letting a shared
  cache in front of the install store and replay draft content, write
  results, or error bodies. This was only observable over a real HTTP
  request/response cycle (`header()` is a no-op under PHPUnit's CLI SAPI),
  so it needed the acceptance suite's real `php -S` dev server, not a unit
  test — confirmed red (reverted the fix, saw `max-age=300` over curl) before
  reapplying it. Fixed by overriding to the private/no-store headers at the
  top of both route handlers.
- [#749](https://github.com/svandragt/lamb/pull/749) — category 2.
  `preview_token_valid()` (`lamb.php`) and
  `LambMicropubAdapter::updateCallback()` (`micropub.php`) each re-derived
  "is this post deleted" inline (`$post->deleted == 1` /
  `(int) $bean->deleted === 1`) instead of calling the canonical
  `is_deleted()` helper already used by `is_viewable()`/
  `is_publicly_visible()`, and already imported into `micropub.php`
  alongside its siblings. No behavior change today (both were equivalent to
  `is_deleted()`); the fix removes the drift risk if "deleted" is ever
  redefined.
- [#750](https://github.com/svandragt/lamb/pull/750) — category 7. Property
  fuzz on `add_body_tags()` against its own docblock claim of being the
  "counterpart of `get_tags()`" (a round-trip: append then extract should
  return what was asked for). It wasn't: a tag containing a
  `TAG_TERMINATORS` character (most concretely, a space — a normal Micropub
  `category` like `"day trip"`) got appended as `#day trip`, and
  `get_tags()` could only ever recover `"day"` — the ` trip` became bare,
  unlinked body text and was silently dropped from every later source query
  or tag listing. Micropub's `buildTags()` (post creation) had the identical
  flaw independently. Fixed with a shared `sanitize_tag_name()` used by both.
  This is a genuine, client-triggerable data-loss bug, not just a stylistic
  finding — worth treating category 7 as high-value, not just a fallback for
  when file-level passes dry up (the unmerged #741 below is further evidence
  of this).
- [#751](https://github.com/svandragt/lamb/pull/751) — category 2 (second
  finding, lower severity). `theme.php`'s `action_preview()`, `lamb.php`'s
  `ensure_preview_token()`, and Micropub's `createCallback()` each spelled
  out "draft or scheduled" inline (one as `draft != 1 && !is_scheduled()`,
  one as its De Morgan inverse) instead of sharing a helper. No behavior
  change; consolidated into a new `is_unpublished()`.

**Ruled out (do not re-flag without new evidence):**
- Category 2 — pagination (`pagination_window()`/`build_pagination_meta()`),
  visibility (`is_draft`/`is_deleted`/`is_scheduled`/`is_viewable`/
  `is_publicly_visible`), `deleted_at` writes, and front-matter parsing are
  already consolidated behind single helpers everywhere checked, including
  cross-file imports in `webmention.php`/`websub.php`/`micropub.php`.
  `upgrade_posts()`'s apparent front-matter re-derivation is deliberate and
  documented, not accidental duplication. Only the two findings above (now
  fixed) reached for a raw property instead of the helper sitting next to
  it.
- Category 6 — traced `redirect_login()`'s `Cache-Control` override sequence
  (correctly clobbers the pre-route header before every exit path), the
  ETag/If-None-Match precedence in `bootstrap.php`'s
  `client_has_current_version()`, session-fixation/regeneration ordering in
  `redirect_login()`, the SSRF per-hop pinning in `http.php`, and the
  `WWW-Authenticate`/`Location`/`Content-Type` header assembly in Micropub's
  `nyholm/psr7`-backed response emission. None are bugs. Only the Micropub
  cache-header finding above (now fixed) was new. (Did not know about the
  unmerged #742's login-throttle race at scan time; worth a fresh look at
  other read-modify-write counters once #742 merges and that pattern is
  fully landed.)
- Category 7 — `get_tags(parse_tags(x))` is a true round trip for the
  single-pass usage the codebase actually has (`highlight_and_link()`);
  `parse_tags()` is not idempotent under a second call on its own output
  (double-wraps a leading tag), but nothing calls it twice, so unreachable —
  do not "fix" this without a reason something now calls it twice. (The
  unmerged #741 below found a real, different `parse_tags()` bug — anchor
  nesting, not idempotence — from the same property-fuzz pass; re-verify
  #741's fix doesn't change this idempotence conclusion once it merges.)
  `strip_trailing_body_tags()` is idempotent as expected. `slugify()` and
  `normalize_datetime()` were checked by the 2026-08-25 run below (idempotent,
  no bug); `minify_css()` was not checked by either run — still a candidate.

**Issue-dense files:** none yet. `micropub.php` has now produced findings in
both runs to date (category 3 in #735, categories 2/7 in #749/#750/#751)
simply because it is the largest, most-imported-into file and the primary
target of untrusted external input (Micropub client requests) — this is
proportionate to its size/exposure, not evidence of unusual density. Do not
mark it issue-dense; keep including it in every category's sweep.

**Suggested refinement:** the process note above is the main one — get the
PR backlog reviewed so this log reflects true coverage. Beyond that:
category 8 still has nothing to skip (no issue-dense files exist), so drop
it from the category list until one is identified. Once the backlog clears,
next run should finish category 7's last untried candidate
(`minify_css()`) and give categories 3/4 a fresh pass (unchecked since
2026-08-24, and the codebase has moved since).

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

# Plan: converge the duplicate mechanisms. No module system.

Deliverable plan for the sprawl/duplicate-mechanism work. The evidence behind every
claim here — file:line for all ten duplication findings, D1–D10 — is in
[MODULARITY.md](MODULARITY.md); this document does not restate it, it sequences the
work.

## Decision

**The module system is rejected. The convergence work proceeds.**

The investigation was asked where the project does one job several ways and how those
could be split into plugins. It found the first (ten hotspots) and proposed the second
(five modules behind a manifest). The second half is now declined, for a product
reason rather than a technical one: Lamb's promise is a **plug-and-play install**, and
a module set is a step toward a plug-many-things-and-configure install. The README
sells "no plugin needed" as a feature and `PRODUCT.md` says "opinionated defaults over
settings". A `src/modules/` tree makes a disable switch a ten-line change away, and
that switch is what erodes the promise. Not building the tree is the cheapest way to
never have that argument.

What is *not* declined is everything the module proposal was carrying. Three of its
parts turned out to be de-duplication fixes wearing a module costume, and they stand
on their own with no module boundary anywhere:

| Was justified as | Is actually | Kept as |
|---|---|---|
| "unhook webmentions so `feeds` can be removed" | a live availability bug in `/_cron` | Stage 3 |
| "an importer registry so a module can register sources" | the D9 fix — 3 CLIs → 1 | Stage 4 |
| "move feeds out of themes so a module owns formats" | the D7 fix — themes silently drop syndication | Stage 5 |

**Shape:** 11 PRs across 5 stages. Stages 0–1 (7 PRs) are independent of each other
and of everything after — any order, in parallel, each worth landing alone. Stage 2 is
the keystone. Stages 3–5 are three unrelated bug-class fixes that happen to be the
salvage from the rejected half.

## Objectives

1. Remove the divergence that is producing the bugs (Stages 0–2, 4, 5).
2. Make "published" a single event instead of a convention re-derived per subsystem (Stage 2).
3. Stop `/_cron` from being able to lose webmention delivery (Stage 3).

## Non-goals

- **No module system, no plugin framework, no extension API.** No `src/modules/`, no
  manifest, no registries-for-registries' sake, no hook priorities, no filter chains.
  `src/` stays flat and greppable. A PR that creates a module directory has left this
  plan.
- **Nothing operator-visible.** No new configuration, no feature toggles, no docs page
  explaining which features are on. An install upgrading through all 11 PRs sees the
  same routes, the same features and the same `composer install`. The only intended
  operator-visible change in the whole plan is a *bug stopping happening*.
- **No dependency reduction.** All 8 runtime deps stay in `require`. The earlier draft
  targeted 8 → 4; collecting that requires asking operators which features they run.
- **No pluggability for the core.** Routing, config, `Lamb\Post`, visibility, HTTP
  egress guarding, escaping, upload security, session/CSRF each get *one*
  implementation. Offering a choice there is what caused D1–D6 in the first place.
- **Not a global-state cleanup.** `global $routes, $config, $data, $template` stays.

## What this gives up, stated plainly

7,184 lines — 44% of the PHP in `src/` — are optional features, and they all still
ship to every install, in a flat namespace, with no enforced ownership. That is the
cost of this decision and it is accepted, not solved. Two mitigations already exist
and should be used rather than replaced: the `src/<module>/README.md` convention
(2026-08-21 decision) gives a subsystem a place to describe itself without a code
boundary, and `experimental_features` already gates the importers.

Revisit only if a concrete need appears — a real operator asking to run Lamb without
the IndieWeb stack, or a feature whose dependency becomes a recurring advisory
burden. "The codebase would be tidier" is not that need.

## Ground rules (house process, per AGENTS.md)

- Branch from `main`. **Open an issue per PR first** and get maintainer agreement.
- **Red-green TDD is mandatory.** Every step below names its failing test. No
  implementation before the test fails.
- Gates that must stay green, per PR: `composer lint`, `composer analyse`
  (**PHPStan level 8, empty baseline — keep it empty**), `vendor/bin/codecept run`,
  `composer coverage` (**≥70%**), `pnpm test`, `composer audit`.
- A refactor PR that touches no behaviour still needs its characterisation test
  written first, so "no behaviour change" is enforced rather than asserted.
- **Every change is invisible to an operator** except where it stops a bug. Other
  installs are upgrading through this work. Anything addressed *by name* — a route, a
  config key, a CLI entry point, a theme part — keeps a deprecated shim for one
  release, warning rather than breaking.
- `DECISIONS.md` entries at two points: the funnel (Stage 2), and this rejection —
  "Lamb stays a flat codebase; optional features are not modules", recording the
  plug-and-play reasoning so the question does not get re-opened from scratch.

---

## Stage 0 — The bug that proves the thesis

**PR 1 · Fix the notify-after-failed-write twin on the create path**

`Response\redirect_edited()` (`src/response/posts.php:317-329`) carries this comment
on its catch block:

> *"Falling through on a failed write … announced the unsaved content to webmention
> receivers and the WebSub hub."*

That bug was found and fixed — **on one of the two save paths.**
`Response\redirect_created()` (`src/response/posts.php:48-58`) still calls
`notify_post_subscribers($bean)` *outside* its try/catch. It is narrower than the
edit-path version but live: `finalize_and_store_post()` stores twice
(`src/post.php:658`), and if the *second* store throws — SQLite locked by a
concurrent `/_cron` run, which is the realistic case the edit-path comment names —
the bean already has an id, so neither `enqueue_for_post()` nor `ping_for_post()`
early-returns. Outbound webmentions are queued against a permalink built from an
unstored slug, and the hub is pinged for content that was not persisted.

- **Test first:** `tests/Unit/` — create path, second `R::store()` throws, assert no
  `webmentionoutbox` row and no hub ping (both already take injectable senders).
- **Fix:** move the notify inside the success path, matching the edit path.
- **Sweep** (AGENTS.md "After a bugfix"): the same class across all nine D1 write
  sites. That sweep is what produces the Stage 2 design, and its output is the PR
  description for Stage 2's issue.
- **Size:** small. **Risk:** none. **Rollback:** revert.

This PR is the argument for the rest of the plan: a fix applied to one of two twins,
because nothing structural made them one path.

---

## Stage 1 — Convergence

Six independent PRs. No ordering between them, no dependency on anything later.
Each removes one class of divergence outright.

**PR 2 · Delete `Http\post_form()`** (D2)

Zero callers. The only two call sites that would use it (`src/websub.php:105`,
`src/webmention.php:778`) carry comments explaining why they deliberately don't. Its
existence advertises the unguarded transport as an option.
- Test: none needed (deletion); confirm `grep -rn post_form src/ tests/` is empty.
- Size: trivial. Risk: none.

**PR 3 · One escaper** (D3)

Keep `Theme\escape()` (91 call sites, unchanged). Add `Theme\escape_xml()` for the
`ENT_XML1` variant. Delete the **global** `escape()` declared inside
`src/themes/base/feed.php:6-11` behind a `function_exists()` guard — it shadows the
namespaced helper every other template uses — and point `feed.php` and
`src/response/discovery.php:106` at `escape_xml()`. Leave `og_escape()` and
`preload_text()` alone or fold them in only if the flag difference is provably
incidental; do not "unify" a deliberate flag choice.
- Test first: `tests/Unit/` for `escape_xml()` over `&`, `<`, a stray `'`, and an
  invalid UTF-8 byte; acceptance already covers feed well-formedness (`TagFeedCest`).
- Size: small (≈5 sites). Risk: low — a wrong flag here breaks feed XML, which
  `TagFeedCest` catches.

**PR 4 · One front-matter engine** (D4)

Two engines with different semantics (`Post\set_matter()` regex-in-place,
`Post\set_frontmatter_key()` split/rebuild-via-YAML) behind four assemblers
(`Post\build_matter()`, `Import\build_post_body()`,
`Micropub::assembleFrontMatter()`, `Micropub::rebuildBody()`) and four thin wrappers.
14 call sites across 8 files.

Keep `set_frontmatter_key()`'s split/rebuild semantics as the single engine — it
round-trips through the YAML writer, so it cannot leave a stale list under a key or
inject a newline. Re-express `set_matter()`, `persist_slug()`, `set_reply_to()`,
`inject_title_matter()`, `persist_resolved_created()` and the four assemblers on top
of it.
- Test first: characterisation tests pinning current output for each of the 14 call
  sites' inputs — CRLF bodies from the edit form, no-front-matter bodies, a body
  whose block holds an unrecognised key, both hyphen and underscore key spellings.
  `set_matter()`'s no-churn contract (`src/post.php:467-471`) must be pinned before
  it moves: it exists because CRLF made every save rewrite the line.
- Size: **large — the biggest PR in Stage 1.** Consider splitting: (4a) tests +
  engine, (4b) migrate `Lamb\Post` wrappers, (4c) migrate Micropub and the importers.
- Risk: medium-high. Front matter *is* the post format; a regression corrupts stored
  bodies. Mitigation: characterisation tests first, and `--dry-run` on a real import
  archive before and after (`import-lamb.php` supports it).

**PR 5 · One upload store** (D5)

Three pipelines, three move semantics, two WebP encoders, 5 call sites
(`src/response/upload.php:70-74`, `src/micropub.php:932`, `:1579-1582`,
`src/import.php:699`). `src/response/upload.php:320-333` documents the fallout: the
`tempnam` path landed assets `0600` while the others landed `0666 & ~umask`, so a
static-file server or backup user couldn't read images the site was serving. Fixed
in one of three paths.

Single `Response\store_upload()` accepting either a temp path (uses
`move_uploaded_file()` for PHP's managed check) or bytes, owning the WebP decision
and the final mode for both.
- Test first: mode assertion (`0666 & ~umask`) and WebP-vs-fallback for every input
  shape; the existing `UploadCest` and `MicropubMediaCest` cover the routes.
- Size: medium. Risk: medium — the `move_uploaded_file()` safety check must not be
  lost on the path that has it. Keep the two entry shapes; unify only what follows.

**PR 6 · Dedupe the theme parts** (D8)

`themes/2024/parts/_items.php` and `themes/2026/parts/_items.php` differ by **two
lines, one a comment**, and both diverged from base in the same direction.
`themes/base/parts/_related.php:31` carries the giveaway — *"mb_strimwidth(), as the
2026 theme's own _related.php uses"* — a truncation fix hand-ported between copies.

Promote the shared markup into `base`, keep only genuine per-theme overrides. Where
base and the two themes genuinely disagree (base's `<footer>` vs the themes'
logged-in-only `<small>`), decide once and record it — do not preserve all three.
- Test first: `tests/Unit/Theme*PartTest.php` is an established pattern (9 such
  files exist); add render assertions per theme before moving markup.
- Verify: `pnpm run screenshot` before/after per theme at all three widths, plus the
  `playwright` CI job.
- Size: medium. Risk: low functionally, **visible** cosmetically — screenshot diffs
  are the gate, and `PRODUCT.md` is the arbiter for the 2026 theme.

**PR 7 · One `data_dir()`** (D10)

`getenv('LAMB_DATA_DIR')` is read independently in `src/bootstrap.php:58` and all
three CLI scripts. Route the CLIs through `Bootstrap\data_dir()`. Optionally move the
feature-only constants out of `src/constants.php` (`FEED_FETCH_*`,
`IMAGE_UPLOAD_EXTENSIONS`, `VIDEO_UPLOAD_EXTENSIONS`) next to their owners — the
file's own docblock says it holds "application-wide" constants, and these are not.
Skip it if it reads worse; with no module extraction coming, nothing forces it.
- Test first: `UpgradeScriptTest`/`LambRestoreTest` already spawn CLI subprocesses
  with a seeded data dir — extend to assert the resolved path.
- Size: small. Risk: low. Note the default differs by design (`../data` for web,
  `__DIR__/data` for CLI) — preserve that, don't "fix" it.

**Stage 1 exit criteria:** `grep` finds one escaper for XML, one front-matter engine,
one upload store, one `data_dir()`, no `post_form()`, and no duplicated `_items.php`.
Coverage ≥70% and the PHPStan baseline still empty.

---

## Stage 2 — The post lifecycle funnel (keystone)

**PR 8 · `Post\save()` and the lifecycle events**

The D1 fix, and the largest single change in the plan. Nine write sites each
independently decide whether to re-render, finalize the slug, notify subscribers,
write a slug-change redirect and feed-lock (table in MODULARITY.md §D1). The
asymmetry is currently held by a comment repeated across `src/wordpress.php:203` and
`src/known.php:307`.

```php
Post\save(OODBBean $bean, array $context = []): void
// $context: ['finalize_slug' => bool, 'notify' => bool, 'lock_if_feed_sourced' => bool,
//            'redirect_on_slug_change' => bool]
// emits: post.created | post.updated | post.published | post.deleted | post.restored
Post\on(string $event, callable $subscriber): void
```

The flags each site currently expresses **by omitting a call** become parameters
that can be asserted in a test. Notification moves inside the funnel's success path,
so PR 1's fix becomes structural rather than a convention.

Subscribers registered at the seam: `Webmention\enqueue_for_post`,
`Websub\ping_for_post`, `Response\store_slug_change_redirect`,
`Response\lock_if_feed_sourced`.

The second, subtler win: **`post.published` becomes the single definition of
publication.** Today `Webmention\process_outbound()` checks `is_publicly_visible()`
at send time while `Websub\ping_scheduled_publishes()` (`src/websub.php:142`) infers
it from a `created > updated` heuristic over a watermark window. Both are currently
correct; nothing structural keeps them agreeing, and a third subscriber would need a
third derivation.

- **Test first:** one test per write site asserting which events fire with which
  context — 9 sites × the flag matrix. This is the bulk of the PR and it is the
  point: the convention becomes executable.
- **Conversion order** (each independently revertable): create → edit → checkbox
  toggle → `upgrade_posts()` → micropub create → micropub update → feed ingest →
  the three importers.
- **Do not** make the scheduled-publish sweep an event subscriber in this PR. It
  needs the cron registry (Stage 3) and conflating them makes both hard to review.
- **Size:** large. **Risk:** high — every write path in the application. Mitigation:
  land the funnel with a single call site converted, then convert the rest one PR-sized
  commit at a time behind the same green suite.
- **DECISIONS.md entry:** "Post writes go through one funnel; publication is an event."

**Stage 2 exit criteria:** `grep -rn 'notify_post_subscribers\|finalize_and_store_post' src/`
returns hits only inside `src/post.php`. No write path outside the funnel.

---

## Stage 3 — Make `/_cron` unable to lose webmention delivery

**PR 9 · Isolate and order the cron jobs**

Kept from the rejected half, but not for its original reason. `Network\process_feeds()`
(`src/network.php:102-149`) is one straight-line function running five unrelated jobs,
with the two notification drains **last**:

```
lock → rate-limit check → purge → prune → flatten
     → feed crawl loop (N feeds × up to FEED_FETCH_TIMEOUT each)
     → Websub\ping_scheduled_publishes()
     → Webmention\process_outbound()
     → write the run watermark
```

Anything that ends the request inside the crawl loop skips both drains *and* the
watermark write. No exception is required: nothing in the repo calls
`set_time_limit()`, so `/_cron` inherits the host's PHP limit — typically 30s under
the FPM/Caddy/nginx setups the repo ships — while a single feed fetch may take up to
`FEED_FETCH_TIMEOUT` (15s, `src/constants.php`). Two slow feeds exhaust the budget.
And because the watermark is written only at the end, the next run is immediately due
and walks into the same wall, so **one persistently slow feed can stop outbound
webmention delivery indefinitely, silently.** The only symptom is replies never
arriving at their targets.

`crawl_feed()` is also unguarded: `ingest.php` catches `SQL` around individual stores
(`network/ingest.php:116,141`), but nothing wraps the per-feed crawl, so a throw from
the JSON or SimplePie path has the same effect as the timeout.

Fix, smallest first — the ordering change alone removes the reported failure:

1. **Run the drains before the crawl loop**, or guard the loop with `try/finally`, so
   the notification work cannot be starved by feed fetching. Prefer both.
2. **Wrap each feed's crawl** so one bad feed reports and the run continues, rather
   than aborting the request. `crawl_line()` already has an error shape to render into.
3. **Advance the watermark in a `finally`**, so a partial run cannot cause an
   immediate re-run that repeats the same failure.
4. Optional, only if 1–3 leave the function unwieldy: a small `Cron\register(name,
   callable)` list so `/_cron` iterates jobs instead of naming them. This is the piece
   the module plan wanted; it is now a readability nicety, not a requirement. Skip it
   if the guarded version reads fine.

- **Test first:** a feed whose crawl throws, asserting `process_outbound()` still ran
  and the watermark advanced; then a second run asserting it is rate-limited rather
  than repeating. `count_line`/`crawl_line`/`webmention_line` are already unit-testable.
- **Also add** a `set_time_limit(0)` (or a documented explicit budget) at the top of
  `process_feeds()`, since the whole class of failure comes from a long job running
  under a web request's limit. Note it in `src/network/README.md`.
- Size: small-medium. Risk: low — the change is ordering and guards, not new logic.
  Verify against a real `/_cron` run with a deliberately slow feed.

**Exit criteria:** no path through `process_feeds()` reaches the end of the request
with the outbound queue undrained and the watermark unwritten.

---

## Stage 4 — One importer entry point (D9)

**PR 10 · Importer registry and a single CLI driver**

The D9 fix, with no module directory. `import-wordpress.php`, `import-known.php` and
`import-lamb.php` are ~65 lines each of the same sequence — SAPI check,
`define('ROOT_DIR')`, `parse_import_args()`, readability check, `data_dir()`,
`bootstrap_db()`, `Config\load()`, `apply_timezone()`, experimental gate, parse,
extract, `run_import()` — differing only in which parse/extract/uuid/import callables
they pass.

`Import\run_import()` already takes exactly those callables (`src/import.php:834`), so
this is mechanical:

```php
Import\register_source(['name','parse','extract','skip_reason','uuid','import']);
```

plus one `bin/lamb import <source> <path> [--dry-run] [--replace]` driver. Files stay
where they are — `import.php`, `wordpress.php`, `known.php`, `restore.php` in `src/`,
unmoved. A fourth importer becomes a registration instead of a fourth script.

- **Test first:** `LambRestoreTest` already spawns the CLI as a subprocess against a
  seeded data dir — extend it to the new driver and to a registered-source lookup,
  plus a `--dry-run` byte-comparison of the summary output against the old scripts
  (`run_import()` was written to keep that output identical, so this is a real check).
- Keep the three `import-*.php` shims for one release, delegating to the driver and
  warning to STDERR. They are documented entry points in `README.md` and `docs/`.
- Size: medium. Risk: low — CLI-only, no web surface, and already behind
  `experimental_features` (`src/config.php:436`).

**Exit criteria:** one driver, three registered sources, three shims warning.

---

## Stage 5 — Themes stop owning syndication (D7)

**PR 11 · Feed output moves from theme parts into code**

The D7 fix, with no serializer registry abstraction beyond what this needs. Atom and
JSON Feed live as **theme parts** (`themes/base/feed.php`, `feed_json.php`), so a
theme owns the syndication contract, and a theme that simply *omits* the part loses
the site's feed. That exact class of failure is already recorded in
`themes/2026/html.php:85-89` for a different part:

> *"A theme that omits the call hides them from the author entirely — this one did,
> and it is the default theme."*

Move both into `Lamb\Response` alongside the sitemap, which is already code
(`Response\render_sitemap()`, `src/response/discovery.php:94`). Themes keep
presentation; feeds stop being overridable by omission.

**Third-party themes are real, so this is two steps, not a cut.** The feed responder
resolves a theme-supplied `feed.php`/`feed_json.php` first and emits a deprecation
notice when it finds one; existing themes keep working. The fallback is removed a
release later. This inverts today's footgun: after the change, a theme that omits the
part inherits a correct feed, and only an override is deprecated.

- **Test first:** `tests/Unit/` feed-shape assertions, plus a fixture theme overriding
  a feed part to pin the deprecated path while it exists. `TagFeedCest` and the feed
  conditional-GET Cests already guard the routes and must pass untouched.
- This is the one developer-visible change in the plan; it needs a theme-author note
  in `docs/` and a mention in the release notes.
- Size: medium. Risk: medium — feeds are cacheable and validator-sensitive, so verify
  `ETag`/`Last-Modified` behaviour is unchanged (`Response\feed_cache()`).

**Exit criteria:** `themes/*/feed*.php` are optional overrides, not the only source.

---

## Dependency graph

```
PR 1  (create-path notify fix) ─── independent, do first (it is the argument)

PR 2  post_form deletion       ─┐
PR 3  one escaper               │
PR 4  one front-matter engine   ├─ Stage 1: parallel, no inter-dependencies,
PR 5  one upload store          │  each valuable standalone
PR 6  theme part dedupe         │
PR 7  one data_dir             ─┘

PR 8  Post\save() + events  ◄─── needs PR 4, 5 (or Micropub/importers get
                                  converted twice)

PR 9  cron ordering + guards ─── independent of everything; it is a bug fix
PR 10 importer registry      ─── independent (PR 7 first if both touch the CLIs)
PR 11 feeds out of themes    ─── independent (after PR 3, which touches feed.php)
```

Only one real edge in the whole plan (PR 8 after PR 4/5). Stages 3–5 are three
unrelated fixes and can go in any order, before or after the funnel.

**Stop-anywhere property.** Stages 0–1 pay for themselves with no architectural
commitment. Stage 2 is the D1 fix and stands alone. Stages 3–5 are each a single
bug-class fix. Nothing in this plan requires believing in anything.

---

## Definition of done

Per PR: issue agreed → failing test → minimum implementation → green → all six gates
→ PHPStan baseline still empty → coverage ≥70% → `AGENTS.md` updated where behaviour
or structure moved.

Measured against MODULARITY.md's numbers:

| Metric | Now | Target |
|---|---:|---:|
| Post write entry points | 9 | 1 |
| Definitions of "published" | 2 | 1 |
| Cron paths that can silently skip webmention delivery | ≥1 | 0 |
| HTTP transports | 5 (1 dead) | 4 |
| XML escapers | 2 + 1 global | 1 |
| Front-matter engines / assemblers | 2 / 4 | 1 / 1 |
| Upload store pipelines | 3 | 1 |
| Copies of the post-list template | 3 | 1 |
| Importer CLI scripts | 3 | 1 driver |
| Feed formats a theme can silently drop | 2 | 0 |
| Runtime deps required | 8 | 8 — unchanged, by choice |
| Optional-feature LOC in a flat `src/` | 7,184 (44%) | 7,184 — unchanged, by choice |

The last two rows are the shape of the decision: the duplication goes, the sprawl
stays.

---

## Risks

1. **PR 4 (front matter) is the most dangerous PR here.** Front matter is the post
   format; a regression corrupts stored bodies rather than breaking a page. Split it,
   characterise before touching, and diff a real `--dry-run` import both sides.
2. **PR 8 touches every write path.** Land the funnel with one site converted, then
   convert the rest incrementally. Do not convert nine sites in one commit.
3. **PR 11 is the only developer-visible break.** A theme overriding a feed part is
   someone's live blog losing its feed, hence the two-step deprecation. Do not
   collapse it into one PR because the fallback looks like dead weight.
4. **PR 9 is easy to under-fix.** Reordering the drains removes the reported symptom
   but leaves an unguarded loop and an all-or-nothing watermark. Do all of 1–3 in that
   PR, or the next slow feed produces a different version of the same outage.
5. **RedBean fluid mode.** Unchanged by this plan, but worth stating since the module
   proposal raised it: `webmention`, `webmentionoutbox` and `feedstatus` are created
   lazily on first write. With no modules there is nothing to disable, so no orphan
   tables — one fewer thing to reason about.
6. **The module idea will come back.** It is a genuinely appealing refactor and each of
   these PRs makes the seams cleaner, which makes it *more* appealing. The rejection
   is on plug-and-play grounds, not code-health grounds, so a code-health argument for
   reinstating it does not answer the objection. Put the `DECISIONS.md` entry in early
   (Stage 2 at the latest) so the reasoning outlives this conversation.

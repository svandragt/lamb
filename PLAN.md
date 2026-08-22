# Plan: converge, then modularise

Deliverable plan for the sprawl/duplicate-mechanism work. The evidence behind every
claim here — file:line for all ten duplication findings, D1–D10 — is in
[MODULARITY.md](MODULARITY.md); this document does not restate it, it sequences the
work.

**Thesis:** the bugs and the sprawl are separate problems. The bugs come from ~10
places where the core does one job two or three ways; the sprawl comes from 44% of
`src/` being optional features fused into that core. A module boundary drawn around a
duplicate mechanism relocates the bug instead of removing it. So: **converge first,
extract second.** Every stage below is ordered by that constraint, not by appeal.

**Shape:** 14 PRs across 5 stages. Stages 0–1 (7 PRs) are independent of each other
and of everything after — they can land in any order, in parallel, and each one is
worth landing even if the rest of this plan is dropped. Stage 2 is the keystone.
Stages 3–5 are only unlocked by it.

---

## Objectives

1. Remove the divergence that is producing the bugs (Stages 0–2).
2. Make "published" a single event instead of a convention re-derived per subsystem (Stage 2).
3. Give each optional feature one owner, one directory and one registered seam, so a
   change to it cannot reach the rest of the codebase by accident (Stages 3–5).

**Objective 3 is maintainability, not a shippable subset.** An earlier draft of this
plan justified modules by "let an install not ship what it doesn't use". That is
struck: Lamb's promise is a **plug-and-play install**, and the moment an operator has
to decide which modules to enable, that promise is gone — replaced by a
plug-many-things-and-configure install, which is what people choose Lamb to avoid.
The README sells "no plugin needed" as a feature and `PRODUCT.md` says "opinionated
defaults over settings"; a module set an operator picks contradicts both.

So modules are **internal organisation only. Every module always loads.** There is no
disable switch, no per-install module set, and nothing about them in the operator's
config. An operator upgrading through Stages 3–5 sees no change whatsoever: same
routes, same features, same `composer install`.

What the seam buys is bounded and worth having on its own: the cron coupling fixed
(Stage 3), a fourth importer becoming a drop-in instead of a fourth CLI (PR 11),
syndication formats stopping being a theme's responsibility (PR 12), and each
feature's blast radius being one directory instead of the whole of `src/`.

**A real installed base is what makes this split the right one.** It raises the value
of clear ownership — a regression now reaches other people's blogs — and raises the
cost of anything operator-visible, because that needs an upgrade path, a docs page
and a deprecation cycle. Convergence-before-extraction gets *stronger* for the same
reason: a duplicate mechanism relocated behind a module boundary is a bug shipped to
someone else's site.

## Non-goals

- **No plugin framework.** No hook priorities, no filter chains, no third-party
  plugin API, no plugin admin UI. Four registries and a manifest array.
- **No new user-facing configuration, and no disable switch.** Modules always load.
  There is no enabled/disabled set, in config or anywhere else. If a PR in Stages 3–5
  adds an operator-visible knob, it has left this plan.
- **No pluggability for the core.** Routing, config, `Lamb\Post`, visibility, HTTP
  egress guarding, escaping, upload security, session/CSRF each get *one*
  implementation. Offering a choice there is what caused D1–D6.
- **Not a global-state cleanup.** `global $routes, $config, $data, $template` stays.
  It caps how isolated a module can be; that is accepted and stated, not fixed here.
- **Not a third-party plugin ecosystem — even though other installs exist.** An
  installed base justifies *internal* modules (one owner per feature); it
  does not justify a public extension API, which is a support commitment and a
  compatibility surface forever. The README sells "no plugin needed" as a feature.
  Modules stay an internal seam that happens to be toggleable.

## Ground rules (house process, per AGENTS.md)

- **Every extraction is invisible to an operator.** Other installs are upgrading
  through this work and must see no change: same routes, same features, same
  `composer install`, nothing new to configure. Modules always load, so there is no
  enabled set to migrate. What does need care is anything an operator or theme author
  *addresses by name* — a moved route, config key, CLI entry point or theme part keeps
  a deprecated shim for one release, warning rather than breaking.
- Branch from `main`. **Open an issue per PR first** and get maintainer agreement —
  AGENTS.md requires it for feature work, and Stage 2 onward is feature work.
- **Red-green TDD is mandatory.** Every step below names its failing test. No
  implementation before the test fails.
- Gates that must stay green, per PR: `composer lint`, `composer analyse`
  (**PHPStan level 8, empty baseline — keep it empty**), `vendor/bin/codecept run`,
  `composer coverage` (**≥70%**), `pnpm test`, `composer audit`.
- A refactor PR that touches no behaviour still needs its characterisation test
  written first, so the "no behaviour change" claim is enforced rather than asserted.
- Per the 2026-08-21 decision, each new module directory gets a
  `src/modules/<name>/README.md`. Per the 2026-05-29 decision, `docs/` stays
  end-user only — none of this work adds pages there except where a module changes
  documented behaviour.
- `DECISIONS.md` entries at three points only: the funnel (Stage 2), the module
  manifest (Stage 4), and optional dependencies (Stage 6). The convergence PRs are
  not decisions, they are cleanups.

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
three CLI scripts. Route the CLIs through `Bootstrap\data_dir()`. Move the
feature-only constants out of `src/constants.php` (`FEED_FETCH_*`,
`IMAGE_UPLOAD_EXTENSIONS`, `VIDEO_UPLOAD_EXTENSIONS`) — parking them next to their
owners now saves moving them twice in Stages 4–5.
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

The one change that both fixes D1 and unlocks Stages 3–5. Nine write sites each
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

## Stage 3 — Cron registry

**PR 9 · `Cron\register()` and a `/_cron` driver**

`Network\process_feeds()` (`src/network.php:142-146`) hardcodes
`Websub\ping_scheduled_publishes()` and `Webmention\process_outbound()` *inside the
feed-ingestion loop*. Consequence: **removing the feed reader silently stops
outbound webmention delivery.** This single coupling is why `indieweb` and `feeds`
cannot be separated today.

Replace with `Cron\register(string $name, callable $job)` and a `/_cron` driver that
runs whatever registered, keeping the existing lock (`Network\acquire_cron_lock()`),
the per-job output lines, and `purge_deleted_posts()`. Feed ingestion becomes one
registered job among several.

- Test first: registry ordering, one job throwing does not abort the rest, output
  shape unchanged (the existing `count_line`/`crawl_line`/`webmention_line` helpers
  are already unit-testable).
- Size: medium. Risk: medium — `/_cron` is the only scheduled entry point; a
  swallowed exception must still surface in the output. Verify against a real run.

**Exit criteria:** `src/network.php` contains no reference to `Lamb\Webmention` or
`Lamb\Websub`.

---

## Stage 4 — Module loader, and the first module

**PR 10 · The manifest and loader**

```php
// src/modules/<name>/module.php
return [
    'name'     => 'import',
    // No 'enabled' key and no 'requires' probe: every module loads, always.
    // A module needing an extension states it in composer.json like any core code.
    'register' => function (): void { /* route/cron/event/import registrations */ },
];
```

`index.php` loads every module and calls `register()` before `Route\call_route()` —
a fixed list discovered from `src/modules/`, with no configuration consulted. The
manifest is a wiring convention, not a feature flag. `Route\is_reserved_route()` rebuilds the registry on demand for CLI
callers (`src/routes.php:170`) — module registration must participate in that
rebuild or an importer will stop seeing module routes as reserved, which is exactly
the `/login`-shadowing bug that function was added to fix.

- Test first: a fixture module registering a route and a cron job; assert both
  reachable, and assert a module whose `register()` throws fails loudly at boot
  rather than silently dropping its routes.
- Size: medium. Risk: medium. **DECISIONS.md entry:** "Optional features live in
  `src/modules/<name>/` behind a manifest."

**PR 11 · Extract the `import` module**

Lowest-risk extraction by a wide margin: CLI-only, no web routes, no theme surface,
and **already gated behind `experimental_features`** (`src/config.php:436`) — a kill
switch that exists today, with `EXPERIMENTAL_GATE_VERSION` machinery for forcing
re-opt-in when the gated set changes.

It is also the **highest-value** extraction, not just the safest: an importer is
run once per install, at migration time, and never again. Today every install
carries 2,391 lines and a `league/html-to-markdown` dependency in perpetuity to
serve that one-off. No other module has that profile — the rest are features people
actually keep using.

Moves `import.php`, `wordpress.php`, `known.php`, `restore.php` into
`src/modules/import/`. Adds the **importer registry** that collapses D9:

```php
Import\register_source(['name','parse','extract','skip_reason','uuid','import']);
```

`Import\run_import()` already takes exactly these callables, so this is mechanical.
Replace the three ~65-line CLI scripts — identical but for their parse/extract calls
— with one `bin/lamb import <source> <path> [--dry-run] [--replace]` driver. Keep
thin `import-*.php` shims at the root for one release, deprecation-warning to STDERR,
since they are documented entry points.

- Test first: `LambRestoreTest` already spawns the CLI as a subprocess with a seeded
  data dir — extend it to the new driver and to a registered-source lookup, plus a
  `--dry-run` byte-comparison of the summary output against the old scripts.
- Size: medium-large (mostly file moves). Risk: low-medium. Verify with a real
  archive `--dry-run` diff before/after.
- Drops `league/html-to-markdown` from the core's required set.

**Exit criteria:** one CLI driver, three registered sources, `composer.json`
`autoload.files` no longer lists the four importer files individually.

---

## Stage 5 — The remaining modules

**PR 12 · Serializer registry; move Atom and JSON Feed out of `themes/`** (D7)

Two of five post→wire serializers are **theme parts**
(`themes/base/feed.php`, `feed_json.php`), so a custom theme owns the syndication
contract. `themes/2026/html.php:85-89` records this class of failure already
happening for a different part: *"A theme that omits the call hides them from the
author entirely — this one did, and it is the default theme."*

Move Atom and JSON Feed to a serializer registry owned by code, not themes. Themes
keep presentation; formats stop being overridable by omission.

**Because third-party themes are real, this cannot be a hard break.** Ship it in two
steps: the registry resolves a theme-supplied `feed.php`/`feed_json.php` first and
emits a deprecation notice when it finds one, so existing themes keep working; the
fallback is removed a release later. That inverts the current footgun — a theme that
*omits* the part silently loses syndication today, whereas after this a theme that
omits it inherits a correct feed and only an override is deprecated.
- Test first: `tests/Unit/` feed-shape assertions, plus a fixture theme overriding a
  feed part to pin the deprecated path while it exists. `TagFeedCest` and the feed
  conditional-GET Cests already guard the routes.
- Risk: medium — the only user-visible change in the plan. The two-step lands it
  without breaking a theme mid-release; `docs/` needs a theme-author note.

**PR 13 · Extract `indieweb`** (2,558 LOC)

`micropub.php`, `webmention.php`, `websub.php`, `parts/_webmentions.php`, and
`index.php`'s four hardcoded `Link:` headers. Owns the `webmention` and
`webmentionoutbox` tables, its `post.published` subscriptions (Stage 2) and its cron
jobs (Stage 3). Drops `taproot/micropub-adapter`.
- **Only viable after Stages 2–4.** Extracting it while Micropub still carries its
  own front-matter assembler (PR 4), its own upload pipeline (PR 5), and its own HTML
  sanitizer just relocates D3/D4/D5 behind a boundary where they are harder to see.
- Micropub's `sanitizeHtml()`/`sanitizeAttributes()` (`src/micropub.php:1102`) is the
  one sanitizer that legitimately stays module-local — it filters *inbound* client
  HTML, a different job from rendering trusted author Markdown. Say so in the module
  README rather than leaving it looking like a stray fourth copy.
- Test first: acceptance coverage already exists (`MicropubDiscoveryCest`,
  `MicropubMediaCest`, `WebmentionCest`) — they must pass unchanged, which is the
  whole acceptance criterion: the extraction is invisible from outside.
- Size: large (mostly moves). Risk: medium-high — it is the largest module and owns
  security-sensitive endpoints.

**PR 14 · Extract `feeds`, `export`, `highlight`**

- `feeds` (1,042 LOC, `network.php` + `network/`): the ingest cron job. Drops
  `simplepie/simplepie`. `src/network/README.md` already exists — move it along.
- `export` (439 LOC): `/export`. Keeps its own `Export\zip_available()` runtime check
  for `ext-zip`, exactly as today — that is a capability probe, not a feature flag.
- `highlight` (91 LOC): a render filter and a `POST_VERSION` participant. Because it
  always loads, the version stamp keeps its current meaning and no post re-parses;
  note in its README that this is *why* it is not switchable.
- Size: medium each. Risk: low-medium.

---

## Stage 6 — Struck

**Cut: "move feature-only deps to `suggest`" and "document modules for operators".**

Both were the operator-visible half of the plan, and both are gone with the
disable switch. If every module always loads, every module's dependencies are always
needed, so `taproot/micropub-adapter`, `simplepie/simplepie` and `phiki/phiki` stay
in `require` where they belong — and there is nothing for an operator to read about,
because there is nothing for them to decide.

This is a deliberate trade. It gives up the dependency-surface reduction that
objective 3 originally claimed, and that reduction was real: a stripped install would
have dropped from 8 runtime deps to 4. It is given up because collecting it requires
asking the operator which features they run, and that question is the thing Lamb
exists not to ask.

**The one case worth revisiting later** is `import`: CLI-only, already gated behind
`experimental_features`, run at most once per install, and carrying
`league/html-to-markdown` in perpetuity for that one run. Moving *that* dependency to
`suggest` degrades a CLI script into a clear error message rather than degrading the
website, so it does not put the site's behaviour in the operator's hands. Even so:
not now. It is a separate decision on its own merits once PR 11 has landed and the
module seam has proven itself, and it needs `composer install --no-dev` on a real
install to behave correctly first.

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
  │                               converted twice)
  ├──► PR 9  cron registry
  │      │
  │      └──► PR 13 indieweb ◄── also needs PR 10
  │           PR 14 feeds
  └──► PR 10 module loader
           └──► PR 11 import module
                PR 12 serializer registry
                PR 14 export, highlight
                          (PR 15 optional deps, PR 16 operator docs: struck — see
                           Stage 6. They were the operator-visible half.)
```

**Stop-anywhere property.** Stages 0–1 pay for themselves with no architectural
commitment. Stage 2 is worth landing even if Stages 3–5 never happen — it is the
D1 fix. Stages 3–5 are the only part that requires believing in the module idea, and
they are last on purpose.

---

## Definition of done

Per PR: issue agreed → failing test → minimum implementation → green → all six gates
→ PHPStan baseline still empty → coverage ≥70% → `AGENTS.md` updated where structure
moved (its Project Structure and Namespaces tables both go stale from Stage 4 on).

Overall, measured against MODULARITY.md's numbers:

| Metric | Now | Target |
|---|---:|---:|
| Post write entry points | 9 | 1 |
| Definitions of "published" | 2 | 1 |
| HTTP transports | 5 (1 dead) | 4 |
| XML escapers | 2 + 1 global | 1 |
| Front-matter engines / assemblers | 2 / 4 | 1 / 1 |
| Upload store pipelines | 3 | 1 |
| Copies of the post-list template | 3 | 1 |
| Importer CLI scripts | 3 | 1 driver |
| Optional-feature LOC in a flat `src/` | 7,184 (44%) | 0 (owned by a module dir) |
| Runtime deps required | 8 | 8 — unchanged, by choice |

The dependency row is deliberately flat. An earlier draft targeted 8 → 4; that
required an operator-selectable module set, which is struck (see Stage 6). The 44%
still *ships* — it just stops being spread through a flat namespace with no owner.
That is a smaller claim than the earlier draft made, and it is the honest one.

---

## Risks, and where this plan is most likely to go wrong

1. **PR 4 (front matter) is the most dangerous PR here.** Front matter is the post
   format; a regression corrupts stored bodies rather than breaking a page. Split it,
   characterise before touching, and diff a real `--dry-run` import both sides.
2. **PR 8 touches every write path.** Land the funnel with one site converted, then
   convert the rest incrementally. Do not convert nine sites in one commit.
3. **PR 12 would break third-party themes** that override a feed part — which, with
   a real installed base, means someone's live blog loses its feed. Hence the
   two-step deprecation rather than a clean cut. Do not collapse it back into one PR
   because the fallback looks like dead weight.
4. **The dependency reduction is struck, and should stay struck.** It is the most
   tempting thing to reinstate, because 8 → 4 deps reads well against AGENTS.md's
   advisory burden. Reinstating it means asking the operator which features they run.
   See Stage 6 for the one narrow exception worth a separate decision later.
5. **The upgrade path.** Largely dissolved by always-loading modules: with no
   enabled set there is nothing to derive, and an operator upgrading sees no change.
   What remains is narrow and still needs testing — a moved route or CLI entry point
   must keep its deprecated shim for a release (PR 11's `import-*.php`, PR 12's theme
   feed parts). Test each against a config and a theme that predate the move, not
   just a fresh install.
6. **RedBean fluid mode means "disabled" is not "uninstalled."** `webmention`,
   `webmentionoutbox` and `feedstatus` are created lazily on first write; disabling a
   module leaves orphan tables. Acceptable — but say it in the module READMEs so it
   isn't discovered as a bug.
7. **CI needs no module pinning — and that is the test that the seam stayed
   internal.** Five acceptance Cests (`MicropubDiscoveryCest`, `MicropubMediaCest`,
   `WebmentionCest`, `UploadCest`, `TagFeedCest`) exercise routes that become
   module-provided. They must keep passing untouched, with no module configuration in
   the suite. The day CI needs to know which modules are on, this plan has drifted.
8. **Philosophy drift is the real long-term risk, and it has a specific shape here.**
   Not hook priorities first — a *disable switch*. Once `src/modules/` exists, adding
   one is a ten-line change and will look obviously useful. That is the change that
   turns a plug-and-play install into a plug-many-things-and-configure install, and
   it should be refused on those grounds rather than debated on its merits.
   "Simple over complex", "opinionated defaults over settings". The moment this grows
   hook priorities, a
   filter chain, or a plugin settings page, it has stopped being this plan. The
   non-goals above are the guardrail; revisit them at Stage 4, which is where the
   temptation arrives.

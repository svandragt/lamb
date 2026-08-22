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

**Shape:** 16 PRs across 6 stages. Stages 0–1 (7 PRs) are independent of each other
and of everything after — they can land in any order, in parallel, and each one is
worth landing even if the rest of this plan is dropped. Stage 2 is the keystone.
Stages 3–5 are only unlocked by it.

---

## Objectives

1. Remove the divergence that is producing the bugs (Stages 0–2).
2. Make "published" a single event instead of a convention re-derived per subsystem (Stage 2).
3. Let an install not ship — and not carry the dependency surface of — features it doesn't use (Stages 3–6).

**The premise for objective 3 is a real installed base.** Lamb has other installs,
so "don't ship what you don't use" is a benefit that lands on actual users rather
than a hypothetical one. That premise also imposes the constraint below: with real
installs, every module extraction needs an upgrade path, and shipping a regression
costs more than the sprawl does. Convergence-before-extraction gets *stronger* under
that premise, not weaker — a duplicate mechanism relocated behind a module boundary
is now a bug shipped to other people's blogs.

The clearest single win is `import`: 2,391 lines and `league/html-to-markdown` that
every install carries forever to serve a migration each install runs at most once.

## Non-goals

- **No plugin framework.** No hook priorities, no filter chains, no third-party
  plugin API, no plugin admin UI. Four registries and a manifest array.
- **No new user-facing configuration.** Modules ship enabled by default. This is an
  internal code-organisation seam.
- **No pluggability for the core.** Routing, config, `Lamb\Post`, visibility, HTTP
  egress guarding, escaping, upload security, session/CSRF each get *one*
  implementation. Offering a choice there is what caused D1–D6.
- **Not a global-state cleanup.** `global $routes, $config, $data, $template` stays.
  It caps how isolated a module can be; that is accepted and stated, not fixed here.
- **Not a third-party plugin ecosystem — even though other installs exist.** An
  installed base justifies *modules* (an install can drop what it doesn't run); it
  does not justify a public extension API, which is a support commitment and a
  compatibility surface forever. The README sells "no plugin needed" as a feature.
  Modules stay an internal seam that happens to be toggleable.

## Ground rules (house process, per AGENTS.md)

- **Every extraction ships an upgrade path.** Other installs are upgrading through
  this work, so no module may default to off for an install that is already using
  the feature: derive the enabled set from existing config (a populated `[feeds]`
  means `feeds` is on) rather than from a new default. The precedents are
  `Config\ensure_explicit_theme()` and `Bootstrap\backfill_imported_post_identity()`
  — boot-time, idempotent, safe to run on every request. A moved route, config key
  or CLI entry point keeps a deprecated shim for one release, warning rather than
  breaking.
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
    'requires' => [],          // ext-*/composer probes, cf. Export\zip_available()
    'register' => function (): void { /* route/cron/event/import registrations */ },
];
```

`index.php` loads enabled modules and calls `register()` before
`Route\call_route()`. Enabled-by-default; the enabled set is resolved in one place so
CI can pin it. `Route\is_reserved_route()` rebuilds the registry on demand for CLI
callers (`src/routes.php:170`) — module registration must participate in that
rebuild or an importer will stop seeing module routes as reserved, which is exactly
the `/login`-shadowing bug that function was added to fix.

- Test first: a fixture module registering a route and a cron job; assert both
  reachable, and assert a module whose `requires` probe fails is skipped without
  fataling.
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
  `MicropubMediaCest`, `WebmentionCest`) — add a module-disabled case asserting the
  routes 404 and the `Link:` headers are absent.
- Size: large (mostly moves). Risk: medium-high — it is the largest module and owns
  security-sensitive endpoints.

**PR 14 · Extract `feeds`, `export`, `highlight`**

- `feeds` (1,042 LOC, `network.php` + `network/`): the ingest cron job. Drops
  `simplepie/simplepie`. `src/network/README.md` already exists — move it along.
- `export` (439 LOC): `/export`, `ext-zip`. `Export\zip_available()` is already the
  `requires`-probe pattern the loader borrows.
- `highlight` (91 LOC): a render filter, and a `POST_VERSION` participant — disabling
  it changes rendered output, so the module must be part of the version stamp or
  every post re-parses on read. Call that out in its README.
- Size: medium each. Risk: low-medium.

---

## Stage 6 — Optional dependencies

**PR 15 · Move feature-only deps to `suggest`**

`taproot/micropub-adapter`, `simplepie/simplepie`, `league/html-to-markdown`,
`phiki/phiki` — **4 of 8 runtime deps** — exist only for modules. Move to `suggest`
with runtime capability probes in each module's `requires`.

This is the payoff for the whole plan given how much of AGENTS.md is dependency and
advisory management: a minimal install stops carrying CVE surface for a Micropub
endpoint it never exposes.

- Risk: **this is where the plan can bite users, and with a real installed base it
  will.** An existing install that runs `composer install --no-dev` after upgrading
  loses packages its enabled modules still need, and finds out when a route 500s.
  Requirements, not suggestions: a boot-time `requires` probe that names the missing
  package and the module needing it; the enabled-module set derived from existing
  config per the upgrade rule above; a `RELEASING.md` checklist item; an upgrade
  note in `docs/`. Land it at a **release boundary**, never mid-release, so
  `release` never carries a half-applied dependency change.
- **DECISIONS.md entry:** "Feature-only dependencies are suggested, not required."

**PR 16 · Document modules for operators**

Added because there is an installed base: from PR 10 on, "which features does this
install run" becomes something an operator has to be able to answer. `docs/` is
end-user-only (2026-05-29 decision), so this is one page — what a module is, the
enabled set, how upgrades derive it, and what disabling one does and does not remove
(orphan tables stay; see risk 5). The `src/modules/<name>/README.md` files stay
contributor-facing per the 2026-08-21 decision; this is the operator-facing half.
- Size: small. Risk: none. Do not start it before PR 10 settles the vocabulary.

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
                     └──► PR 15 optional deps  (last: needs every module extracted,
                          │                       and lands at a release boundary)
                          └──► PR 16 operator docs (needs PR 10's vocabulary settled)
```

**Stop-anywhere property.** Stages 0–1 pay for themselves with no architectural
commitment. Stage 2 is worth landing even if Stages 3–6 never happen — it is the
D1 fix. Stages 3–6 are the only part that requires believing in the module idea, and
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
| Runtime deps required by a minimal install | 8 | 4 |
| Deps required by a *typical* install (keeps IndieWeb + feeds) | 8 | 6 |
| Optional-feature LOC inside the core | 7,184 (44%) | ~0 |

The two dependency rows are the honest pair. Most installs run the IndieWeb and feed
features — that is why they chose Lamb — so their surface drops by two
(`league/html-to-markdown` once `import` is extracted, `phiki` if they forgo
highlighting), not four. The full drop to four is real but applies to the stripped
install, not the median one. `import` supplies most of the typical win, because it is
the one module nobody keeps using.

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
4. **PR 15 can break existing installs' `composer install`.** Boot-time probe with an
   actionable message, at a release boundary, or don't ship it.
5. **The upgrade path is the part most likely to be skipped, and the most expensive
   to get wrong.** Defaulting a module to off for an install already using the
   feature silently removes it — webmentions stop being delivered and nothing errors.
   Every extraction PR needs a boot-time derivation test against a config that
   predates it, not just a fresh-install test. This risk did not exist when the plan
   assumed one install; it is now the top operational risk in Stages 4–6.
6. **RedBean fluid mode means "disabled" is not "uninstalled."** `webmention`,
   `webmentionoutbox` and `feedstatus` are created lazily on first write; disabling a
   module leaves orphan tables. Acceptable — but say it in the module READMEs so it
   isn't discovered as a bug.
7. **CI must pin the enabled module set from PR 10 onward.** Five acceptance Cests
   (`MicropubDiscoveryCest`, `MicropubMediaCest`, `WebmentionCest`, `UploadCest`,
   `TagFeedCest`) exercise routes that become module-provided; coverage drops
   silently otherwise.
8. **Philosophy drift is the real long-term risk.** "Simple over complex",
   "opinionated defaults over settings". The moment this grows hook priorities, a
   filter chain, or a plugin settings page, it has stopped being this plan. The
   non-goals above are the guardrail; revisit them at Stage 4, which is where the
   temptation arrives.

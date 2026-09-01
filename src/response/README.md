# Routing and responses (`Lamb\Response`)

Developer notes for how a request becomes a response. This is the *why* behind
the code in this directory; the per-function docblocks carry the contract
(params, returns, invariants) and point here for the design. For routing,
pagination, conditional-GET/caching and the security invariants this module
must not break, see [AGENTS.md](../../AGENTS.md) — this README does not
restate that material, only extends it with the parts specific to this
directory.

## Files

All files declare the `Lamb\Response` namespace (a split, not separate
namespaces — callers don't care which file a function lives in):

| File | Responsibility |
|------|----------------|
| `../response.php` | Namespace entry: cookie options, pagination core, conditional-GET/304, 404/redirect helpers, `upgrade_posts()` |
| `auth.php` | `/login`, `/logout`, `/settings`: the sessionless login page, its CSRF model, and the brute-force throttle |
| `discovery.php` | `/sitemap.xml`, `/robots.txt`, and the noindex marker they share with the theme |
| `export.php` | `/export` download (login required) |
| `feeds.php` | Home, drafts/trash/scheduled listings, search, tag pages, the Atom and JSON feeds |
| `posts.php` | Status/slug pages, create/edit/delete/restore, the checkbox-toggle endpoint |
| `upload.php` | Image upload, content-type checks, and WebP conversion |

Routes are registered centrally in `Lamb\Route\register_app_routes()`
(`src/routes.php`); this module supplies the callbacks.

## Two responder shapes

A `respond_*` function returns a view-data array for the theme to render; a
`redirect_*` function sends a `Location` header and terminates
(`redirect_uri()`/`#[NoReturn]`) — it never returns to the router. A few
functions do both depending on the request: `respond_edit()` and
`respond_home()` check `$_POST` first and dispatch to a `redirect_*` sibling
(`redirect_edited()`, `redirect_created()`) before falling through to their
own `respond_*` behaviour. Keeping the naming consistent lets a reader tell,
from the route table alone, whether a handler renders or terminates.

## Pagination

`paginate_posts()` (`response.php`) is the one entry point both listing paths
go through: an in-memory array (tag pages, already loaded) or a bean type
string (everything else, a DB query). Both branches share
`pagination_window()`, the single place that clamps a requested page against
the available range and derives the row offset — `build_pagination_meta()`
then reads the same total back out for `prev_page`/`next_page`. Splitting the
clamp from the offset-into-a-query step is what let the DB path and the
array path stay in lock-step without either one reimplementing the other's
arithmetic.

`$per_page` and `$page` are optional parameters that fall back to config
(`posts_per_page`) and `$_GET['page']` respectively, rather than reading the
global/superglobal directly — callers that already know the values (tests,
the tag-feed 20-item cap) pass them explicitly, and `paginate_posts()` itself
never has to care which source they came from.

## Conditional GET, ETag, and 304 caching

`latest_content_timestamp()` folds the newest published post's `updated`
together with the last config edit (`Config\config_modified_timestamp()`),
so a settings change invalidates cached anonymous pages exactly like a post
edit does. `send_304_if_current()` emits the validators and short-circuits
with `304 Not Modified` when the client already holds them; `feeds.php`'s
`feed_cache()` is the feed-specific wrapper (longer `max-age`, no-op for
logged-in responses). The full validator model — why the `ETag` keeps the
content and config timestamps as distinct components while `Last-Modified`
collapses them — is recorded in [AGENTS.md](../../AGENTS.md)'s "Conditional
GET" note under Security; this module only wires the two call sites into it.

## Login: a sessionless page with its own CSRF model

`/login` is the one route an anonymous visitor can always reach, which used
to make it a disk-exhaustion vector: every hit started (and persisted) a
session, so a burst of anonymous requests could mint a week-lived session
file each (issue #462). `redirect_login()` now starts no session for an
anonymous visitor at all — a server-side session is only established *after*
the password verifies.

That leaves the form's CSRF protection unable to ride in the session, so it
uses a signed double-submit cookie instead (`issue_login_csrf()` /
`valid_login_csrf()`): a token is both set as a cookie and embedded in a
hidden field, and a submission is accepted only when the two match
byte-for-byte *and* carry a valid signature. The signing key
(`login_csrf_secret()`) is deliberately derived from, but distinct from, the
raw login hash that signs an authenticated `lamb_logged_in` marker
(`set_login_marker()`) — sharing one key would make a CSRF token harvested
from a plain `GET /login` also work as a login marker, reopening the same
DoS by another route. A wrong password re-renders the login page in place
with the error (`login_page_data()`), rather than a session flash, for the
same sessionless reason (#460).

Failed attempts are throttled per client address *before* `password_verify()`
runs (`login_throttle_retry_after()`, issue #443), so a blocked client costs
no bcrypt. The counter lives in the `option` table keyed by an HMAC of the
address (`login_throttle_key()`) rather than the plaintext address, is pruned
lazily on the write path, and a refused attempt is not itself counted — so
retrying cannot extend a block. `client_ip()` reads `REMOTE_ADDR` only; see
[AGENTS.md](../../AGENTS.md)'s Security section for why `X-Forwarded-For` is
not trusted here.

The peek only rejects an already-blocked client; the race-free gate is
`reserve_login_attempt()`, which takes a `BEGIN IMMEDIATE` write lock and
checks-and-increments the counter atomically before bcrypt. Without it a
concurrent burst from one IP could all read the same under-limit count and each
run `password_verify()`, serialising only on the write — the bcrypt pile-up the
throttle exists to stop. SQLite's busy handler is left at the host default
(tens of seconds), so the reservation forces `busy_timeout = 0` for that
critical section: a contended lock then refuses immediately (the client retries)
instead of stalling every attempt behind the lock and reopening the pile-up. The
lazy prune runs inside that same zero-timeout window on purpose — a prune that
blocked on contention would reopen it just as surely.

Post-login, `local_redirect_target()` constrains `?redirect_to=` to a local
path so the parameter can't be turned into an open redirect — the same
protocol-relative-path check reappears in `posts.php` (see below), because a
raw path from any source can carry the same trick.

## Referer-based redirect targets

Deleting or editing a post redirects back to where the action was invoked
rather than always to the home page, using the request `Referer` header
(`safe_referer_path()` in `posts.php`). A same-origin host check on its own
is not enough: a `Referer` of `https://site/​/evil.test/x` has host `site` and
still yields the path `//evil.test/x`, which a browser resolves as
protocol-relative and follows off-site (`/\evil.test` the same way, since
browsers normalise the backslash). `safe_referer_path()` therefore delegates
the final path shape to `local_redirect_target()` (auth.php), which already
refuses both forms — one guard for the pattern instead of one per caller.
`delete_return_path()` adds one more rule for deletes specifically: falling
back to the home page when the computed target is the just-deleted post's
own permalink, which would otherwise 404 straight back at the visitor.

## Feed-sourced posts and the edit lock

`lock_if_feed_sourced()` sets `feed_locked` the first time an author edits a
feed-ingested post through the edit form, so a later crawl stops re-syncing
over their changes. This is the response-side half of an invariant that
lives mostly in feed ingestion — see
[`../network/README.md`](../network/README.md) ("The watermark model") for
why `feed_locked` exists and how the crawl side reads it.

## Checkbox toggle: AJAX without CSRF

`respond_checkbox()` is a login-only, no-CSRF AJAX endpoint (`posts.php`) —
its protection is the same `SameSite=Strict` session-cookie invariant
`respond_upload()` relies on, documented once in
[AGENTS.md](../../AGENTS.md)'s Security section rather than repeated here.
The toggle itself is guarded against a narrower problem: the box the request
names is identified by a *rendered* index (`data-checkbox-index`), so
`apply_checkbox_toggle()` re-renders before and after the edit
(`rendered_checkbox_states()`) and refuses the write unless exactly the
requested box changed state. Without that check, a source scan that reads a
checkbox's position differently from the renderer could tick a different
task than the one the author clicked.

## Uploads: validate the whole batch, then store, then convert

`respond_upload()` (`upload.php`) checks every file in a dropped batch
(`accept_upload_batch()`) before writing any of it. The client posts the
whole batch in one request, so refusing file 2 midway through a naive loop
would leave file 1 already written under `src/assets/` — stored, unreferenced,
and invisible to the author. `accept_upload_batch()` is a pure function
(reads the batch, resolves each file's extension, sniffs its content) so this
ordering is unit-testable independently of the responder that stores and
dies on every exit path.

An extension allowlist (`safe_upload_extension()`) is the primary defence —
uploads land inside the web root, so anything else could be requested back
as PHP. A content-type sniff (`upload_content_allowed()`) is the second line,
catching an HTML/SVG payload stored under an image extension so a
sniffing browser can't render it as markup from this origin; it fails open
when `fileinfo` is unavailable, since the extension check still stands.

JPEG/PNG uploads are re-encoded to WebP and downscaled to at most 1600px on
the longest edge (`convert_to_webp()`/`scaled_dimensions()`), falling back to
the original bytes on any failure. `max_upload_pixels()` guards the decode
itself: GD allocates a decoded image's full pixel buffer outside PHP's memory
manager as soon as it reads the header, so a small file that declares an
enormous width × height can force a multi-gigabyte allocation regardless of
`memory_limit`. The declared dimensions are checked before decoding in both
the file-path and in-memory paths; `LAMB_MAX_UPLOAD_PIXELS` lets a
memory-constrained host lower the cap without a code change.

The same WebP pipeline serves three callers with three different starting
points — the web upload form (`store_webp_copy()`, a file on disk), and the
WordPress importer and Micropub inline photos (`persist_image_bytes()`, bytes
already in memory) — so the convert-or-fall-back decision and the destination
naming (`$seed.$ext` vs `$seed.webp`) are made once and shared rather than
reimplemented per caller.

`asset_url()`/`asset_dimensions()` are inverses of each other: the former
builds the root-relative URL a post body links to, the latter resolves that
URL back to a file under `src/assets/` (so the renderer can emit intrinsic
`width`/`height`). Because post bodies are hand-written Markdown,
`asset_dimensions()` treats anything that isn't provably inside `src/assets/`
— a scheme/host, a `../` escape, a symlink out of the tree — as
unresolvable rather than guessing.

## Discovery: sitemap, robots.txt, and noindex

`/sitemap.xml` (`discovery.php`) lists the home page and every publicly
visible post through the same `visible_clause()` the listings use, deduping
by URL since two posts can share a slug. `/robots.txt` derives its
`Disallow` list from `Lamb\Route\register_private_route()` — the same
registry the router itself is built from — plus one hand-added wildcard
pattern for preview links (`?preview=`, which are ordinary permalinks and
so can't be disallowed by path). A static `robots.txt` dropped in the web
root still wins over the generated one, so an operator can override it.

The sitemap is served from disk. `respond_sitemap()` reads the validator
first — one indexed row for the newest visible `updated`, plus the config
timestamp — so a crawler revalidating a copy it already holds is answered 304
without the document being built at all. Those same two values, together with
`ROOT_URL`, key a cached copy under `data/cache/sitemap-<key>-<page>.xml`, so a
request that does get a 200 streams a file instead of rebuilding 25,000
entries. Keying on the content is what keeps the cache honest: it turns over
exactly when the ETag does, so the bytes sent always match the validator sent
with them, and there is no staleness window to reason about.

Past `SITEMAP_MAX_URLS` visible entries — sitemaps.org's 50,000-URL cap on a
single document — `/sitemap.xml` itself becomes a `<sitemapindex>`
(`render_sitemap_index()`) and each `/sitemap.xml/page/N` (the site's usual
`/page/N` pagination convention) serves one `SITEMAP_MAX_URLS`-sized slice,
sliced in SQL via `LIMIT`/`OFFSET` (`sitemap_urls()`'s `$page`/`$cap`
arguments) rather than loading everything and cutting it down, for the same
memory reason described below. `<page>` in the cache filename keeps each
page's copy distinct so storing one page's cache can't evict another's; only
an older generation's files (a different `<key>`) get pruned. A page number
that doesn't exist for the current split — any page at all under the cap, or
one past the last page once split — 404s like any other unmatched route.
Under the cap there is exactly one page and behaviour is unchanged from
before the split existed.

The cached copy is host-independent: it
holds a `{ROOT}` placeholder where the site root belongs, and the current
`ROOT_URL` is substituted on the way out. That matters because with no
`site_url` configured `ROOT_URL` comes from the request's own `Host`, which
index.php flags as attacker-chosen — caching a rendered host would serve one
visitor's claimed host to everyone else, and keying the cache per host instead
would let junk `Host` headers evict the honest entry and disable the cache.
Substituting at render time costs about 3 ms on a hit at 30,000 posts and
removes both. The substituted root is escaped exactly as `render_sitemap()`
escaped the rest of the `<loc>`, since `site_url` is only validated for a
scheme and a host and could contain an `&`. Every filesystem operation is
best-effort: an unwritable data directory costs the cache, never the
response.

`should_noindex()`/`mark_noindex()` mark a response noindex for any private
route or any request carrying a `preview` parameter (valid or not); this
runs per request rather than inside the preview-token check, since some
handlers (feeds, the export download) terminate before a theme ever gets to
render `Theme\the_robots()`'s matching `<meta>` tag. See
[DECISIONS.md](../../DECISIONS.md) ("2026-08-03") for why both the header and
the meta tag exist side by side.

## Export download

`respond_export()` (`export.php`) streams the zip `Export\build_export_archive()`
builds to a temp file, then hands it to the browser as an attachment
(`stream_export_download()`), deleting the temp file afterwards. Output
buffering is unwound before `readfile()` so a large archive isn't held in
memory twice over, and `Content-Length` is sent so the browser can show real
download progress. The archive format itself — front-matter Markdown plus a
JSON manifest — is documented in `docs/export.md` and
[DECISIONS.md](../../DECISIONS.md) ("2026-07-26"); this file only owns the
HTTP delivery of it.

# Feed ingestion (`Lamb\Network`)

Developer notes for the feed-ingestion subsystem. This is the *why* behind the
code in this directory; the per-function docblocks carry the contract (params,
returns, invariants) and point here for the design. For the project-wide
architecture see [AGENTS.md](../../AGENTS.md); for the end-user cron setup see
[docs/cron-scheduled-tasks.md](../../docs/cron-scheduled-tasks.md).

## Files

All files declare the `Lamb\Network` namespace (a split, not separate
namespaces — callers don't care which file a function lives in):

| File | Responsibility |
|------|----------------|
| `../network.php` | `/_cron` orchestrator: lock, rate-limit, maintenance steps, per-feed dispatch, output lines |
| `sources.php` | Feed config, SimplePie setup, the SSRF/body-cap-hardened `SafeFile` fetch class |
| `json_feed.php` | JSON Feed (jsonfeed.org) detection, parsing, and the `JsonFeedItem` adapter |
| `ingest.php` | Turning a feed item into a post: dedup, watermark decisions, slug, citation |
| `status.php` | The per-feed `feedstatus` health bean (read by the Logs tab) |

The `/_cron` route is registered centrally in `Lamb\Route\register_app_routes()`.

## The run — trigger, lock, rate-limit

`GET /_cron` (`process_feeds()`) is **unauthenticated** — it's meant to be hit by
an external scheduler — so it defends itself three ways:

1. **A non-blocking exclusive lock** (`acquire_cron_lock()`, a `flock` on
   `<data_dir>/cron.lock`). Nothing stops a burst of concurrent requests, and
   the rate-limit watermark below is only written *after* all work completes —
   so without the lock every request in a burst reads the same stale watermark,
   passes the "too often" check, and runs in parallel, multiplying outbound HTTP
   and risking duplicate ingestion (no unique constraint on `feeditem_uuid`) and
   duplicate webmention sends (no atomic claim on a queued row). The lock
   releases when the request ends, so every exit path must `die()`/`exit()`
   rather than return. Failure to *open* the lock file is kept distinct from
   contention: a broken/mislocated lock file is a 500, not "already running" —
   reporting it as contention once silently stopped every cron run while the
   endpoint kept answering "try again later".
2. **A whole-run gate** of one run per minute (`cron_run_due()`).
3. **A per-feed gate** of one fetch per 30 minutes (`feed_fetch_due()`), keyed on
   the last **attempt**, not the last success — so a failing feed is retried on
   schedule instead of locked out, and a healthy one isn't re-fetched early.

## Finishing the run

The crawl loop can run for a long time — up to `FEED_FETCH_TIMEOUT` per feed —
while the notification drains (`Websub\ping_scheduled_publishes()`,
`Webmention\process_outbound()`) and the rate-limit watermark come after it. If
the crawl is cut short, both are skipped, and because the watermark is unwritten
the next run walks the same feeds and dies the same way, so outbound webmentions
never deliver. Three things keep the run finishing:

- **`set_time_limit(1800)`** at the top of `process_feeds()`, so a slow feed
  cannot hit a web request's PHP limit (typically 30s under FPM) mid-crawl. It's
  a finite cap, not `0`: the run holds the cron flock until the process ends, so
  an unbounded run that wedged would leave every later `/_cron` stuck on "already
  running". 30 minutes clears any legitimate run yet still frees the lock if one
  hangs — though on Unix it only catches a CPU-bound wedge, so a hung socket
  still relies on the per-fetch curl/SimplePie timeouts.
- **A per-feed guard** (`crawl_feed_guarded()`), because `crawl_feed()` only
  catches `SQL` around individual stores — a throw from the JSON or SimplePie
  path would otherwise abort the whole run. One bad feed reports a `FAILED` line
  and the loop continues.
- **The drains and the watermark run in a `finally`**, so feed fetching can never
  starve notification delivery, and a partial run still advances the watermark
  and so cannot immediately re-run and repeat the same failure.

## The watermark model (the crux)

Three timestamps on the `feedstatus` bean do three different jobs. Conflating
them is what caused both the "dropped items" and "recreated draft" bugs, so keep
them distinct:

| Field | Question it answers | Used by |
|-------|--------------------|---------|
| `last_attempt` | When did we last *try* to reach the host? | the per-feed rate-limit gate |
| `last_success` | When did we last *reach* the host? | crawl health only (Logs tab) |
| `last_item_date` | How new is the newest entry we've *seen*? | the create/skip decision |

Two rules follow, and both matter:

- **`last_attempt` is stamped *before* the fetch** (`begin_crawl()`), persisted
  immediately. If it were left to the success/failure recorders, a fetch that
  never returns (OOM on a hostile body, `max_execution_time`, a parser fatal)
  would leave `last_attempt` untouched, the feed would still be due next run,
  and `/_cron` would die at the same feed every time — taking down everything
  downstream of the feed loop with it (remaining feeds, WebSub pings, the entire
  outbound webmention queue). One row-write per crawl turns "cron wedged
  forever" into "one lost run per 30-minute window".

- **Every date comparison is against something the *feed* said, never the
  clock** (`ingest_item()`):
  - A **new** item (no post with its `feeditem_uuid`) is created only when its
    publish date is newer than `last_item_date`. That mark exists solely to stop
    an entry still sitting in the feed window from resurrecting a post the author
    trashed. It used to be the *crawl* timestamp, which silently dropped items:
    any crawl that succeeded without seeing the entry (CDN-stale feed, lagging
    publisher) stamped `last_success = now`, and the entry's own date was by then
    older than that stamp, so it was never created.
  - An **existing** post is re-synced only when the item's modified date is newer
    than the post's `updated` column (the copy we last took from the feed) **and**
    `feed_locked` is false. `feed_locked` means the author took the post over via
    the edit form, so a published, re-slugged post is left intact.

- **`last_item_date` only ever moves forward** (`record_crawl_success()` takes a
  `max`). A feed whose newest entry has scrolled out of its window, or that
  briefly serves a truncated copy, must not lower it — or every older entry still
  listed becomes eligible again. A run that **lost an entry to a failed write**
  also reports no new watermark at all: `ingest_item()` returns `null` for a
  create that should have happened but didn't, and `ingest_items()` then withholds
  the date. Stepping the watermark over an entry the run failed to create would
  drop it below the create line for good — the same drop-forever bug by another
  route. Holding the watermark for one run re-creates nothing that did land, since
  every entry is deduped on `feeditem_uuid`.

Dedup key throughout: `feeditem_uuid = md5(feed_name . item_id)`.

## Two source types, one spine

`crawl_feed()` dispatches by URL: a `.json` path goes to the JSON Feed parser,
everything else to SimplePie (the default path — most feeds are RSS/Atom).

The two paths deliberately **share** `begin_crawl()`, `record_crawl_success()`,
`record_crawl_failure()`, and `ingest_items()`, so they can't drift on which
watermark they read or how they record health — the exact divergence this shape
is prone to. `JsonFeedItem` (in `json_feed.php`) adapts a JSON Feed item to the
subset of SimplePie's `snake_case` `Item` API that the ingest pipeline uses, so a
JSON item flows through `ingest_item()` / `create_item()` / `populate_bean()`
unchanged. Dateless items return `null` (as SimplePie does) and are not ingested —
we match RSS/Atom rather than invent a date.

## Fetch hardening

Feed URLs are admin-configured (trusted at add-time), but nothing pins their
*eventual* destination — a compromised host or a redirect could point the
unauthenticated cron worker at internal/loopback addresses or an endless body.
Both fetch paths defend against this on **every redirect hop**:

- **SSRF pinning.** `SafeFile` (SimplePie's fetch class, subclassed) refuses a
  request whose host doesn't resolve to a public address, and pins curl to the
  exact validated address via `CURLOPT_RESOLVE` (keeping the original hostname
  for `Host:`, SNI, and cert verification). Validating the URL and then letting
  curl do its own DNS lookup is a DNS-rebinding TOCTOU; the pin closes it. That
  only works through curl, so a forced-`fsockopen` request (no `CURLOPT_RESOLVE`
  equivalent) is refused rather than left unpinned. SimplePie follows redirects
  by recursively re-entering the constructor, so the check runs per hop. The
  JSON path uses `Http\fetch_guarded()`, which re-checks each hop the same way.
- **Body cap** (`FEED_FETCH_MAX_BYTES`, `SafeFile::capBodyCurlOptions()`). Without
  a cap a feed can stream an endless body into the worker until it fatals.
  SimplePie reads the body out of `curl_exec()`'s return value, so a
  `WRITEFUNCTION` (what `fetch_guarded()` uses) would lose it; instead the cap is
  enforced with `CURLOPT_ENCODING: identity` (an uncompressed body, so the cap
  bounds real bytes and not a gzip bomb's compressed size), `CURLOPT_MAXFILESIZE`
  (rejects an over-cap declared `Content-Length` up front), and a progress
  callback (catches chunked/undeclared-length bodies; aborting surfaces as a
  fetch error, so the success watermark is left alone exactly as for a timeout).
- **Timeout** (`FEED_FETCH_TIMEOUT`) so a slow or hostile feed can't stall the run.

`FEED_FETCH_TIMEOUT` / `FEED_FETCH_MAX_BYTES` / `FEED_FETCH_INTERVAL` are defined
in `constants.php`.

## Untrusted content → front matter

A feed item's title is remote and untrusted, and it ends up inside a post's YAML
front matter (delimited by `---`). `sanitize_feed_title()` collapses whitespace,
shortens `---` runs, and length-caps it so a title can't inject extra keys or
close the block early; `get_structured_content()` then renders the block with
`Yaml::dump()` (via `Post\build_matter()`) rather than string interpolation.
Interpolation let the feed choose the YAML *type* of its own title — `[a, b]`
arrived as a list, `2024-01-02` as a date object — and the run died on the first
such item. Dumping always quotes the scalar back to text.

## `feedstatus` bean

Config is the source of truth for *which* feeds exist; `feedstatus` records only
crawl *health*, keyed by `md5(name . url)`. Beans are created lazily
(`feed_status_bean()`), seed their success watermark from any legacy
`last_processed_date_<key>` option so upgraded installs don't re-ingest
everything on the first run, and are pruned when their feed leaves config
(`prune_feed_status()`). `get_feed_statuses()` returns a zeroed row for
never-crawled feeds so the Logs tab lists them too.

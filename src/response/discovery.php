<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;

use const ROOT_DIR;
use const ROOT_URL;
use const SITEMAP_MAX_URLS;

/**
 * Formats a stored `Y-m-d H:i:s` datetime as a W3C/ISO-8601 string for a sitemap
 * `<lastmod>`. Returns null for empty/unparseable input so the element is omitted.
 *
 * @param string|null $datetime A stored datetime string.
 * @return string|null ISO-8601 datetime, or null.
 */
function sitemap_date(?string $datetime): ?string
{
    if (empty($datetime)) {
        return null;
    }
    $ts = strtotime($datetime);
    return $ts ? date('c', $ts) : null;
}

/**
 * Builds the ordered list of sitemap URL entries: the home page followed by
 * every publicly visible post, newest first.
 *
 * Reuses the canonical visible_clause() so drafts, deleted posts, and
 * future-scheduled posts are excluded exactly as the public listings exclude
 * them. Menu/standalone pages are intentionally included — unlike the home and
 * Atom feeds they are real public URLs worth indexing.
 *
 * Two distinct posts can share a slug (slugs are not DB-unique), which would
 * otherwise emit the same <loc> twice — invalid for a sitemap. Entries are
 * deduplicated by URL, keeping the first (newest, since ordered by updated DESC).
 *
 * Only the three columns a URL entry is built from are read. /sitemap.xml is
 * anonymous and unpaginated — unlike the feeds, which cap at 20 rows — so
 * loading whole post beans put every body and every rendered `transformed`
 * blob in memory at once: about 14 MB at 2,000 posts, and a fatal
 * "Allowed memory size of 134217728 bytes exhausted" at 20,000 against the
 * images' default 128M limit, which is a blank 500 for every crawler. The URL
 * is built from the row via Lamb\post_path(), which is also what permalink()
 * resolves to, so the rule stays in one place without a bean per row — making
 * one cost about 130 ms of this response at 30,000 posts.
 *
 * $page splits the same ordered list into sitemaps.org-sized slices instead of
 * returning it whole, for a site past SITEMAP_MAX_URLS (see respond_sitemap()).
 * Entry 0 is the home page and entries 1.. are posts newest-first, so page 1
 * carries the home page plus a $cap-1 slice of posts and page 2 onwards carries
 * a $cap slice with no home entry. The slicing is done in SQL via LIMIT/OFFSET
 * rather than fetching everything and array_slice()ing it, for the same memory
 * reason the whole function avoids loading post beans (see below).
 *
 * @param string $root The site root each `<loc>` is built from. Defaults to
 *                     this request's ROOT_URL; respond_sitemap() passes
 *                     SITEMAP_ROOT so the cached copy is host-independent.
 * @param int    $page Which sitemap page to return; 0 (the default) returns
 *                     every entry, unpaginated.
 * @param int    $cap  Entries per page when $page is set. Defaults to
 *                     SITEMAP_MAX_URLS; overridable so tests can exercise
 *                     pagination without seeding 50,000 posts.
 * @return list<array{loc: string, lastmod: string|null}>
 */
function sitemap_urls(string $root = ROOT_URL, int $page = 0, int $cap = SITEMAP_MAX_URLS): array
{
    $visible = \Lamb\visible_clause();
    $sql = 'SELECT id, slug, updated FROM post WHERE ' . $visible['sql'] . 'ORDER BY updated DESC';
    $params = $visible['params'];

    if ($page >= 1) {
        // Entry 0 is the home page, so post index i is global entry i + 1: page 1's
        // post slice is one shorter (it also carries the home entry) and starts at
        // offset 0, while page k > 1 starts one post short of ($page - 1) * $cap.
        $sql .= ' LIMIT ? OFFSET ?';
        if ($page === 1) {
            $params[] = max(0, $cap - 1);
            $params[] = 0;
        } else {
            $params[] = $cap;
            $params[] = ($page - 1) * $cap - 1;
        }
    }

    $rows = R::getAll($sql, $params);

    $entries = [];
    $seen = [];
    foreach ($rows as $row) {
        $loc = $root . \Lamb\post_path((string) $row['slug'], (int) $row['id']);
        if (isset($seen[$loc])) {
            continue;
        }
        $seen[$loc] = true;
        $entries[] = [
            'loc'     => $loc,
            'lastmod' => sitemap_date((string) ($row['updated'] ?? '')),
        ];
    }

    // Home page first; its lastmod tracks the freshest post (null when empty).
    // Only pages 0 (everything) and 1 (the first slice) carry it — its rows
    // start at offset 0, so entries[0] is still the newest post overall.
    if ($page <= 1) {
        array_unshift($entries, [
            'loc'     => $root . '/',
            'lastmod' => $entries[0]['lastmod'] ?? null,
        ]);
    }

    return $entries;
}

/**
 * Counts the publicly visible posts a sitemap would list (excluding the home
 * page entry sitemap_urls() prepends) — the same visible_clause() allow-list,
 * read as one row instead of the whole list.
 *
 * @return int
 */
function count_visible_posts(): int
{
    $visible = \Lamb\visible_clause();
    return (int) R::getCell(
        'SELECT COUNT(*) FROM post WHERE ' . $visible['sql'],
        $visible['params']
    );
}

/**
 * The number of sitemap pages (P) a $total-entry sitemap needs at $cap URLs
 * per page — sitemaps.org's cap on a single document.
 *
 * @param int $total The total number of sitemap entries (posts + the home page).
 * @param int $cap   Entries per page. Defaults to SITEMAP_MAX_URLS; overridable
 *                   so tests can exercise the split with a tiny cap.
 * @return int
 */
function sitemap_page_count(int $total, int $cap = SITEMAP_MAX_URLS): int
{
    return $cap > 0 ? (int) ceil($total / $cap) : 1;
}

/**
 * Renders sitemap URL entries as a sitemaps.org 0.9 XML document.
 *
 * @param list<array{loc: string, lastmod: string|null}> $urls
 * @return string The complete XML document.
 */
function render_sitemap(array $urls): string
{
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];
    foreach ($urls as $url) {
        $lines[] = '  <url>';
        // Theme\escape_xml() is the one XML escaper (ENT_XML1|ENT_QUOTES|
        // ENT_SUBSTITUTE): a malformed UTF-8 byte degrades to U+FFFD instead of
        // making htmlspecialchars() return '' for the whole string — which would
        // emit an empty, invalid <loc>.
        $lines[] = '    <loc>' . \Lamb\Theme\escape_xml($url['loc']) . '</loc>';
        if (!empty($url['lastmod'])) {
            $lines[] = '    <lastmod>' . $url['lastmod'] . '</lastmod>';
        }
        $lines[] = '  </url>';
    }
    $lines[] = '</urlset>';
    return implode("\n", $lines) . "\n";
}

/**
 * Renders a sitemaps.org 0.9 sitemap index: one `<sitemap>` entry per child
 * page, for a site whose visible URL count has passed SITEMAP_MAX_URLS.
 *
 * Child locs follow the site's existing pagination convention
 * (`/sitemap.xml/page/N`) rather than page_path(), which would collapse page 1
 * to the bare `/sitemap.xml` — the wrong URL here, since page 1 is a child
 * page, not the index itself.
 *
 * Every child shares the one $lastmod given (the sitemap's newest lastmod, as
 * newest_visible_update()/sitemap_date() produce it) rather than each computing
 * its own: page 1 always holds the newest entry, so a per-page value would only
 * ever be current for that one child.
 *
 * @param string      $root       The site root each child `<loc>` is built
 *                                from. SITEMAP_ROOT for the cached copy, like
 *                                render_sitemap().
 * @param int         $page_count The number of child pages (P).
 * @param string|null $lastmod    ISO-8601 lastmod applied to every child entry,
 *                                or null to omit it.
 * @return string The complete XML document.
 */
function render_sitemap_index(string $root, int $page_count, ?string $lastmod): string
{
    $lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ];
    for ($page = 1; $page <= $page_count; $page++) {
        $lines[] = '  <sitemap>';
        $lines[] = '    <loc>' . \Lamb\Theme\escape_xml($root . '/sitemap.xml/page/' . $page) . '</loc>';
        if (!empty($lastmod)) {
            $lines[] = '    <lastmod>' . $lastmod . '</lastmod>';
        }
        $lines[] = '  </sitemap>';
    }
    $lines[] = '</sitemapindex>';
    return implode("\n", $lines) . "\n";
}

/**
 * The `updated` of the newest publicly visible post — the sitemap's validator.
 *
 * Identical to the `lastmod` sitemap_urls() puts on its first entry (it orders
 * by `updated` descending, and the home entry inherits the newest post's date),
 * but read as one row instead of derived from all of them. Null when there is
 * no visible post to date the sitemap from.
 *
 * @return string|null A stored `Y-m-d H:i:s` datetime, or null.
 */
function newest_visible_update(): ?string
{
    $visible = \Lamb\visible_clause();
    $updated = R::getCell(
        'SELECT updated FROM post WHERE ' . $visible['sql'] . 'ORDER BY updated DESC LIMIT 1',
        $visible['params']
    );

    return $updated === null ? null : (string) $updated;
}

/**
 * Names the cached copy of the sitemap: one key per distinct document.
 *
 * The two timestamps are what the ETag is built from, so the cache turns over
 * exactly when the validator does and a served copy always matches the ETag
 * sent with it — no staleness window to reason about.
 *
 * $page_count is folded in too, because it can change independently of both:
 * trashing/restoring a post that is not the newest, or a scheduled post
 * crossing into visibility purely by clock, changes count_visible_posts()
 * (and so whether /sitemap.xml is a <urlset> or a <sitemapindex>, and how many
 * pages it has) without touching $updated. Without this, such a change left
 * the key — and so the served document's shape — stale: a <sitemapindex>
 * served after the count dropped back under the cap, or a <urlset> missing
 * everything past the old count after it rose past the cap.
 *
 * The site root is deliberately *not* part of the key. The cached document is
 * host-independent — it holds SITEMAP_ROOT where the root belongs, and
 * emit_sitemap() substitutes this request's ROOT_URL on the way out — so one
 * entry is correct for every host. Keying on the root instead would have made
 * an install with no `site_url` (the shipped default) cache whatever Host a
 * request claimed: harmless as a per-request answer, but persisted it becomes
 * a poisoning, and evicting the honest entry with junk Hosts would disable
 * the cache outright. Substituting at render time removes both.
 *
 * @param string $updated    The newest visible post's stored datetime.
 * @param int    $config_ts  The config's last-modified timestamp.
 * @param int    $page_count The sitemap's current page count, as
 *                            sitemap_page_count() computes it. Defaults to 1
 *                            (unsplit) for callers that don't split.
 * @return string A filename-safe cache key.
 */
function sitemap_cache_key(string $updated, int $config_ts, int $page_count = 1): string
{
    return md5($updated . '|' . $config_ts . '|' . $page_count);
}

/**
 * The stand-in for the site root inside a cached sitemap.
 *
 * Safe as a marker because it can never occur in a rendered `<loc>`:
 * Lamb\encode_path_segment() leaves only alphanumerics and a fixed set of
 * sub-delimiters unescaped, and the braces are not among them, so a slug
 * containing them arrives percent-encoded.
 */
const SITEMAP_ROOT = '{ROOT}';

/**
 * Where the cached sitemap for $key/$page lives — under the same
 * `data/cache/` directory the feed cache already uses (see
 * Network\ensure_feed_cache()).
 *
 * $page is appended rather than folded into $key so store_sitemap_cache()'s
 * pruning can tell sibling pages of the same generation (same $key, different
 * $page) apart from an older generation's files (see
 * sitemap_cache_generation()) — sibling pages must survive each other's writes,
 * since a split sitemap caches one file per page.
 *
 * @param string $key  As built by sitemap_cache_key().
 * @param int    $page The sitemap page this cache entry is for; 0 for the
 *                      entry point (the single urlset, or the index).
 * @return string Absolute or data-dir-relative path to the cache file.
 */
function sitemap_cache_path(string $key, int $page = 0): string
{
    return \Lamb\Bootstrap\data_dir() . '/cache/sitemap-' . $key . '-' . $page . '.xml';
}

/**
 * The generation portion of a sitemap cache filename — everything before an
 * optional trailing `-<page>` page suffix — so store_sitemap_cache() can tell
 * a sibling page of the current generation from a stale one.
 *
 * Falls back to the whole filename for anything not shaped like
 * sitemap_cache_path()'s output, so an old-format cache file (from before
 * pages existed) is still treated as its own distinct generation and pruned.
 *
 * @param string $filename Basename of a `sitemap-*.xml` cache file.
 * @return string The generation key.
 */
function sitemap_cache_generation(string $filename): string
{
    if (preg_match('/^sitemap-([0-9a-f]{32})-\d+\.xml$/', $filename, $matches) === 1) {
        return $matches[1];
    }
    return $filename;
}

/**
 * Writes the rendered sitemap to its cache path, best-effort.
 *
 * Written to a temporary name and renamed, because rename() is atomic within a
 * filesystem: a second request can never read a half-written document, and two
 * requests racing on the same key simply write the same bytes twice. Every
 * failure path returns quietly — the response has already been sent by the
 * time this runs, so an unwritable data directory costs the cache, not the
 * sitemap.
 *
 * Older generations are removed once the new copy is in place, so the
 * directory holds one sitemap's worth of pages rather than one per edit — but
 * a sibling page of the *same* generation (see sitemap_cache_generation()) is
 * kept, since a split sitemap's pages are written one request at a time and
 * must not evict each other.
 *
 * Every call is `@`-suppressed on purpose. These are best-effort filesystem
 * operations on a path the operator controls, each already handled by its
 * return value, and the response has been sent — a data directory that cannot
 * be written must cost a warning in the log at most, never a PHP notice
 * appended to a served XML document.
 *
 * @param string $path The cache path for the current key.
 * @param string $xml  The rendered document.
 * @return void
 */
function store_sitemap_cache(string $path, string $xml): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $xml) === false) {
        return;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return;
    }
    $generation = sitemap_cache_generation(basename($path));
    foreach (glob($dir . '/sitemap-*.xml') ?: [] as $stale) {
        if ($stale !== $path && sitemap_cache_generation(basename($stale)) !== $generation) {
            @unlink($stale);
        }
    }
}

/**
 * Sends the sitemap for $path, rendering it first when that copy is not there.
 *
 * The cached copy holds SITEMAP_ROOT where the site root belongs; this puts
 * the current ROOT_URL back. A hit costs 4.7 ms against 79 ms to rebuild at
 * 30,000 posts, and 5 MB of transient string against the 32 MB the build
 * holds in rows, entries and output at once.
 *
 * The root is escaped exactly as render_sitemap() escaped the rest of the
 * `<loc>` around it. htmlspecialchars() works per character, so escaping the
 * root and the path separately gives the same bytes as escaping the joined
 * URL once — which matters because `site_url` is only checked for a scheme
 * and a host, so a `&` in it would otherwise be substituted raw into the XML.
 *
 * A miss renders, echoes, and only then writes, so nothing about the cache can
 * delay or break the response. The read is `@`-suppressed like the writes, and
 * because a concurrent request replacing the cache can unlink this key between
 * the is_readable() check and the open; losing that race just renders.
 *
 * @param string        $path   The cache path for the current key/page.
 * @param callable|null $render Builds the document on a miss; defaults to the
 *                              single, unpaginated urlset render_sitemap()
 *                              produces for sitemap_urls(SITEMAP_ROOT).
 *                              respond_sitemap() passes one that renders the
 *                              index or the page slice this path is for.
 * @return void
 */
function emit_sitemap(string $path, ?callable $render = null): void
{
    $render ??= static fn (): string => render_sitemap(sitemap_urls(SITEMAP_ROOT));

    $template = is_readable($path) ? @file_get_contents($path) : false;
    $miss = $template === false;
    if ($miss) {
        $template = $render();
    }

    echo str_replace(
        SITEMAP_ROOT,
        \Lamb\Theme\escape_xml(ROOT_URL),
        $template
    );

    if ($miss) {
        store_sitemap_cache($path, $template);
    }
}

/**
 * Responds to /sitemap.xml with the generated sitemap, cached like a feed.
 *
 * The validator is computed before the sitemap rather than taken from it. A
 * crawler revalidating a sitemap it already holds is the common request here,
 * and deriving the date from the finished URL list meant building all of it —
 * 25,000 entries and a 2.6 MB document, about 90 ms at 30,000 posts — only to
 * answer 304 and throw it away. `updated` is indexed, so one row answers the
 * same question.
 *
 * The same two values then key the on-disk copy, so a request that does get a
 * 200 — a first fetch, or one after an edit — is served from a file rather
 * than rebuilt.
 *
 * Past SITEMAP_MAX_URLS visible entries, a single `<urlset>` would break
 * sitemaps.org's 50,000-URL cap, so /sitemap.xml itself becomes a
 * `<sitemapindex>` and each `/sitemap.xml/page/N` (fed in via the site's usual
 * `/page/N` convention — see Http\extract_page_segment()) serves one slice.
 * Under the cap there is exactly one page, and behaviour is unchanged: this
 * request, with no page segment, serves the sitemap directly. A page number
 * that does not exist — any page at all under the cap, or one past the last
 * page once split — 404s the same way any other route does, via respond_404().
 *
 * @return array{title: string, intro: string, action: string, requested: string}|never
 *         respond_404()'s view data for an out-of-range page; otherwise never
 *         returns.
 */
function respond_sitemap(): array
{
    $updated = newest_visible_update() ?? \Lamb\now();
    $page = (int) ($_GET['page'] ?? 0);
    $page_count = sitemap_page_count(count_visible_posts() + 1);
    $split = $page_count > 1;

    if ($split ? $page > $page_count : $page >= 1) {
        return respond_404([]);
    }

    header('Content-Type: application/xml; charset=UTF-8');
    // Pass page_count as the shape discriminator: a change between index/urlset
    // or in child-page count must invalidate the 304/ETag the same way it
    // invalidates the disk cache key below, even when $updated is unchanged.
    feed_cache($updated, $page_count);
    $path = sitemap_cache_path(
        sitemap_cache_key($updated, \Lamb\Config\config_modified_timestamp(), $page_count),
        $page
    );

    if (!$split) {
        emit_sitemap($path);
    } elseif ($page === 0) {
        emit_sitemap($path, static fn (): string => render_sitemap_index(
            SITEMAP_ROOT,
            $page_count,
            sitemap_date($updated)
        ));
    } else {
        emit_sitemap($path, static fn (): string => render_sitemap(
            sitemap_urls(SITEMAP_ROOT, $page)
        ));
    }
    die();
}

/**
 * The crawler hint emitted for pages that are not meant to be found: don't
 * index this page, and don't follow the links on it (a preview page links back
 * to its own ?preview= URL).
 */
const NOINDEX = 'noindex, nofollow';

/**
 * robots.txt pattern covering the shareable preview link. Preview URLs are
 * ordinary permalinks plus a `?preview=<token>` query, so unlike the private
 * routes they cannot be disallowed by path — hence the wildcard form, which
 * the major crawlers understand.
 */
const PREVIEW_DISALLOW = '/*?preview=';

/**
 * Returns true when the current request must not be indexed: an admin/internal
 * route, or a `?preview=` link.
 *
 * Preview links (src/lamb.php: preview_token_valid()) deliberately serve an
 * unpublished post to anyone holding the token, with no login — which is
 * exactly what makes them indexable if pasted into a page a crawler already
 * follows. Any `preview` parameter counts, even an empty or wrong one; see
 * response/README.md ("Discovery: sitemap, robots.txt, and noindex") and
 * DECISIONS.md ("2026-08-03") for the full model.
 *
 * @param bool|string          $action The current request action (first path segment).
 * @param array<string, mixed> $query  The request query parameters (i.e. $_GET).
 * @return bool
 */
function should_noindex(bool|string $action, array $query): bool
{
    return \Lamb\Route\is_private_route($action) || array_key_exists('preview', $query);
}

/**
 * Marks the current response as noindex and sends the X-Robots-Tag header.
 *
 * The header covers responses no theme renders (redirects, the export
 * download); Theme\the_robots() emits the matching <meta> so the hint survives
 * a page saved or re-served without its headers.
 *
 * @return void
 */
function mark_noindex(): void
{
    global $noindex;
    $noindex = true;
    if (!headers_sent()) {
        header('X-Robots-Tag: ' . NOINDEX);
    }
}

/**
 * Returns true when the current response has been marked noindex.
 *
 * @return bool
 */
function is_noindex(): bool
{
    global $noindex;
    return !empty($noindex);
}

/**
 * Builds the default robots.txt body: allow crawling, point at the sitemap, and
 * disallow every private route.
 *
 * The Disallow list is derived from the routes registered via
 * Lamb\Route\register_private_route() — the single source of truth — so it can
 * never drift out of sync with the admin/internal routes it is meant to cover.
 * It is already inaccessible to anonymous visitors, so this is a hint to
 * crawlers rather than a security control. Sorted for deterministic output.
 *
 * The preview-link pattern is appended after the sorted paths: it is not a
 * route, so it has no entry in the private registry to sort with them.
 *
 * @return string The robots.txt content.
 */
function robots_txt_body(): string
{
    $paths = array_map(
        static fn ($action): string => '/' . ltrim((string) $action, '/'),
        \Lamb\Route\private_routes()
    );
    sort($paths);
    $paths[] = PREVIEW_DISALLOW;

    $lines = ['User-agent: *', 'Allow: /'];
    foreach ($paths as $path) {
        $lines[] = 'Disallow: ' . $path;
    }
    $lines[] = '';
    $lines[] = 'Sitemap: ' . ROOT_URL . '/sitemap.xml';
    return implode("\n", $lines) . "\n";
}

/**
 * Returns the robots.txt content to serve: a static robots.txt dropped in the
 * web root wins (so it stays overridable), otherwise the generated default.
 *
 * @param string $root_dir Web-root directory to look for a static robots.txt in.
 * @return string The robots.txt content.
 */
function robots_txt_content(string $root_dir): string
{
    $static = $root_dir . '/robots.txt';
    if (is_file($static)) {
        return (string) file_get_contents($static);
    }
    return robots_txt_body();
}

/**
 * Responds to /robots.txt, preferring a static file in the web root.
 *
 * @return never
 */
#[NoReturn]
function respond_robots(): never
{
    header('Content-Type: text/plain; charset=UTF-8');
    echo robots_txt_content(ROOT_DIR);
    die();
}

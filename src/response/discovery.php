<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;

use const ROOT_DIR;
use const ROOT_URL;

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
 * @return list<array{loc: string, lastmod: string|null}>
 */
function sitemap_urls(): array
{
    $visible = \Lamb\visible_clause();
    $rows = R::getAll(
        'SELECT id, slug, updated FROM post WHERE ' . $visible['sql'] . 'ORDER BY updated DESC',
        $visible['params']
    );

    $entries = [];
    $seen = [];
    foreach ($rows as $row) {
        $loc = ROOT_URL . \Lamb\post_path((string) $row['slug'], (int) $row['id']);
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
    array_unshift($entries, [
        'loc'     => ROOT_URL . '/',
        'lastmod' => $entries[0]['lastmod'] ?? null,
    ]);

    return $entries;
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
        // Match the Atom feed's escaping (themes/base/feed.php): ENT_SUBSTITUTE
        // means a malformed UTF-8 byte degrades to U+FFFD instead of making
        // htmlspecialchars() return '' for the whole string — which would emit
        // an empty, invalid <loc>.
        $lines[] = '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE) . '</loc>';
        if (!empty($url['lastmod'])) {
            $lines[] = '    <lastmod>' . $url['lastmod'] . '</lastmod>';
        }
        $lines[] = '  </url>';
    }
    $lines[] = '</urlset>';
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
 * Responds to /sitemap.xml with the generated sitemap, cached like a feed.
 *
 * The validator is computed before the sitemap rather than taken from it. A
 * crawler revalidating a sitemap it already holds is the common request here,
 * and deriving the date from the finished URL list meant building all of it —
 * 25,000 entries and a 2.6 MB document, about 90 ms at 30,000 posts — only to
 * answer 304 and throw it away. `updated` is indexed, so one row answers the
 * same question.
 *
 * @return never
 */
#[NoReturn]
function respond_sitemap(): never
{
    header('Content-Type: application/xml; charset=UTF-8');
    feed_cache(newest_visible_update() ?? \Lamb\now());
    echo render_sitemap(sitemap_urls());
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

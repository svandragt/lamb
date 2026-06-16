<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;

use const ROOT_DIR;
use const ROOT_URL;

/**
 * Login-gated routes that crawlers should not waste budget on. They are already
 * inaccessible to anonymous visitors, so this is a hint rather than a security
 * control. Kept in sync with the admin routes registered in index.php.
 */
const ROBOTS_DISALLOW = ['/login', '/logout', '/settings', '/edit', '/drafts', '/trash', '/scheduled', '/_cron'];

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
 * @return list<array{loc: string, lastmod: string|null}>
 */
function sitemap_urls(): array
{
    $visible = \Lamb\visible_clause();
    $posts = R::find('post', $visible['sql'] . 'ORDER BY updated DESC', $visible['params']);

    $entries = [];
    $seen = [];
    foreach ($posts as $post) {
        $loc = \Lamb\permalink($post);
        if (isset($seen[$loc])) {
            continue;
        }
        $seen[$loc] = true;
        $entries[] = [
            'loc'     => $loc,
            'lastmod' => sitemap_date($post->updated),
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
 * Responds to /sitemap.xml with the generated sitemap, cached like a feed.
 *
 * @return never
 */
#[NoReturn]
function respond_sitemap(): never
{
    $urls = sitemap_urls();
    header('Content-Type: application/xml; charset=UTF-8');
    feed_cache($urls[0]['lastmod'] ?? \Lamb\now());
    echo render_sitemap($urls);
    die();
}

/**
 * Builds the default robots.txt body: allow crawling, point at the sitemap, and
 * disallow the login-gated admin routes.
 *
 * @return string The robots.txt content.
 */
function robots_txt_body(): string
{
    $lines = ['User-agent: *', 'Allow: /'];
    foreach (ROBOTS_DISALLOW as $path) {
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

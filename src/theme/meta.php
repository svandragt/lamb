<?php

/** @noinspection PhpUnused */

namespace Lamb\Theme;

use function Lamb\permalink;

use const ROOT_URL;

/**
 * Emits OpenGraph and Twitter Card <meta> tags for the current status post.
 * Does nothing when the current template is not 'status'.
 *
 * @return void
 */
function the_opengraph(): void
{
    global $template;
    global $config;
    global $data;
    if ($template !== 'status') {
        return;
    }
    $bean = $data['posts'][0];
    $description = $bean->description;

    $image = og_image($bean);

    $og_tags = [
        'og:description' => $description,
        'og:image' => $image['url'],
    ];
    if (isset($image['width'])) {
        $og_tags['og:image:width'] = $image['width'];
    }
    if (isset($image['height'])) {
        $og_tags['og:image:height'] = $image['height'];
    }
    if (isset($image['type'])) {
        $og_tags['og:image:type'] = $image['type'];
    }
    $og_tags += [
        'og:locale' => 'en_GB',
        'og:modified_time' => $bean->updated,
        'og:published_time' => $bean->created,
        'og:publisher' => ROOT_URL,
        'og:site_name' => $config['site_title'],
        'og:type' => 'article',
        'og:url' => permalink($bean),
        'twitter:card' => $image['card'],
        'twitter:description' => $description,
        'twitter:domain' => $_SERVER["HTTP_HOST"],
        'twitter:image' => $image['url'],
        'twitter:url' => permalink($bean),
    ];
    if (isset($bean->title)) {
        $og_tags['og:title'] = $bean->title;
        $og_tags['twitter:title'] = $bean->title;
    }
    foreach ($og_tags as $property => $content) {
        if (empty($content)) {
            continue;
        }
        printf('<meta property="%s" content="%s"/>' . PHP_EOL, og_escape($property), og_escape($content));
    }
}

/**
 * Resolves the OpenGraph/Twitter card image for a status post. See
 * theme/README.md ("OpenGraph image selection") for the fallback order and
 * why dimensions are only emitted for a locally-resolvable image.
 *
 * @param \RedBeanPHP\OODBBean $bean
 * @return array{url:string, card:string, width?:string, height?:string, type?:string}
 */
function og_image(\RedBeanPHP\OODBBean $bean): array
{
    $embedded = first_embedded_image((string) ($bean->transformed ?? ''));
    if ($embedded !== null) {
        // Post images are stored root-relative ("/assets/..."); OG/Twitter scrapers
        // require an absolute URL or the social card image is broken.
        $url = \Lamb\absolute_url($embedded);
        return ['url' => $url, 'card' => 'summary_large_image'] + image_dimensions($url);
    }

    if (defined('ROOT_DIR')) {
        foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
            if (is_file(ROOT_DIR . '/og-image.' . $ext)) {
                $url = ROOT_URL . '/og-image.' . $ext;
                return ['url' => $url, 'card' => 'summary'] + image_dimensions($url);
            }
        }
    }

    return [
        'url' => ROOT_URL . '/images/og-image-lamb.webp',
        'card' => 'summary',
        'width' => '1200',
        'height' => '630',
        'type' => 'image/webp',
    ];
}

/**
 * Returns the src of the first <img> in the given HTML, or null when there is none.
 *
 * @param string $html Rendered post HTML.
 * @return string|null
 */
function first_embedded_image(string $html): ?string
{
    if ($html === '' || !preg_match('/<img\b[^>]*\bsrc\s*=\s*("|\')(.*?)\1/i', $html, $m)) {
        return null;
    }
    $src = trim($m[2]);
    return $src === '' ? null : $src;
}

/**
 * Reads width/height/type for a same-origin image URL. Returns [] when the image can't be
 * sized (remote URL, missing file, unreadable) — the OG dimension hints are optional, so
 * we omit them rather than guess ("assume success, communicate failure").
 *
 * @param string $url Image URL.
 * @return array{width?:string, height?:string, type?:string}
 */
function image_dimensions(string $url): array
{
    $path = og_local_path($url);
    if ($path === null || !is_file($path)) {
        return [];
    }
    $size = @getimagesize($path);
    if ($size === false) {
        return [];
    }
    return [
        'width'  => (string) $size[0],
        'height' => (string) $size[1],
        'type'   => $size['mime'],
    ];
}

/**
 * Maps a same-origin image URL to a real file inside ROOT_DIR, or null for a
 * remote URL (which can't be measured locally) and for anything that resolves
 * outside the web root.
 *
 * The URL is body-supplied (post Markdown, including feed items and
 * `create`-scope Micropub posts), so `realpath()` containment against
 * ROOT_DIR — the same defence `Response\asset_dimensions()` applies — guards
 * against a path built straight out of the web root. See theme/README.md
 * ("OpenGraph image selection") for why the containment root is ROOT_DIR
 * rather than the narrower uploads tree.
 *
 * @param string $url Image URL.
 * @return string|null The resolved path inside ROOT_DIR, or null.
 */
function og_local_path(string $url): ?string
{
    if (!defined('ROOT_DIR')) {
        return null;
    }
    if (str_starts_with($url, ROOT_URL . '/')) {
        $path = ROOT_DIR . substr($url, strlen(ROOT_URL));
    } elseif (str_starts_with($url, '/')) {
        $path = ROOT_DIR . $url;
    } else {
        return null;
    }

    $root = realpath(ROOT_DIR);
    $real = realpath($path);
    if ($root === false || $real === false) {
        return null;
    }

    return str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
}

/**
 * Emits <meta name="robots" content="noindex, nofollow"> when the current
 * response has been marked private (see Lamb\Response\should_noindex()):
 * admin pages, and the ?preview= link that opens an unpublished post without
 * a login. Outputs nothing on ordinary public pages, so the default stays
 * "index this".
 *
 * index.php already sends the same hint as an X-Robots-Tag header. This tag is
 * the copy that travels with the HTML — a preview page saved to disk, re-served
 * by a proxy, or scraped through an embed keeps it.
 *
 * @return void
 */
function the_robots(): void
{
    if (!\Lamb\Response\is_noindex()) {
        return;
    }
    printf('<meta name="robots" content="%s">' . PHP_EOL, \Lamb\Response\NOINDEX);
}

/**
 * Emits a <meta name="description"> tag for the current page.
 *
 * For single-post (status) pages: uses the post's description field.
 * For all other pages: uses $config['site_description'] when configured.
 * Outputs nothing when no description is available.
 *
 * @return void
 */
function the_meta_description(): void
{
    global $template;
    global $config;
    global $data;

    if ($template === 'status' && !empty($data['posts'][0])) {
        $description = (string) ($data['posts'][0]->description ?? '');
    } else {
        $description = (string) ($config['site_description'] ?? '');
    }

    if ($description === '') {
        return;
    }

    printf('<meta name="description" content="%s"/>' . PHP_EOL, og_escape($description));
}

/**
 * Most origins the_preconnect() hints at: each hint opens a connection, and more than a few
 * compete with the page's own resources for bandwidth.
 */
const MAX_PRECONNECT_ORIGINS = 3;

/**
 * Emits <link rel="preconnect"> and <link rel="dns-prefetch"> tags for the remote origins the
 * page's posts load media from, in order of first appearance, after any origins in the
 * deprecated $config['preconnect'] setting (removed in 0.17). Emits at most
 * MAX_PRECONNECT_ORIGINS tags of each kind.
 *
 * @return void
 */
function the_preconnect(): void
{
    global $config;
    $origins = array_unique(array_merge(array_values($config['preconnect'] ?? []), media_origins()));
    foreach (array_slice($origins, 0, MAX_PRECONNECT_ORIGINS) as $origin) {
        printf('<link rel="preconnect" href="%s">' . PHP_EOL, escape($origin));
        printf('<link rel="dns-prefetch" href="%s">' . PHP_EOL, escape($origin));
    }
}

/**
 * Lists the remote origins that the posts on the current page load media from.
 *
 * Reads $data['posts'], the same list the page's templates render, so listings, tag and search
 * pages and single posts are covered. Own-origin and relative URLs are left out.
 *
 * @return list<string> Origins ("https://host[:port]"), in order of first appearance.
 */
function media_origins(): array
{
    global $data, $template;
    if (empty($data['posts'])) {
        return [];
    }

    $own_origin = url_origin(ROOT_URL);
    $origins = [];
    foreach ($data['posts'] as $bean) {
        // Menu pages are skipped from listings, so their media never loads there.
        if (is_hidden_menu_item($template, $bean)) {
            continue;
        }
        foreach (media_urls((string) $bean->transformed) as $url) {
            $origin = url_origin($url);
            if ($origin !== null && $origin !== $own_origin) {
                $origins[$origin] = true;
            }
        }
    }

    return array_keys($origins);
}

/**
 * Returns the URLs in the src, poster and srcset attributes of the <img>, <video> and <source>
 * elements of the given HTML, in document order.
 *
 * @param string $html Rendered post HTML.
 * @return list<string>
 */
function media_urls(string $html): array
{
    $urls = [];
    preg_match_all('/<(?:img|video|source)\b[^>]*>/i', $html, $tags);
    foreach ($tags[0] as $tag) {
        preg_match_all('/(?<![\w-])(src|poster|srcset)\s*=\s*("|\')(.*?)\2/is', $tag, $attributes, PREG_SET_ORDER);
        foreach ($attributes as [, $name, , $value]) {
            if (strtolower($name) !== 'srcset') {
                $urls[] = trim($value);
                continue;
            }
            // "url 480w, url 960w": the URL is the first word of each comma-separated candidate.
            foreach (explode(',', $value) as $candidate) {
                $urls[] = (string) strtok(trim($candidate), " \t\r\n");
            }
        }
    }

    return $urls;
}

/**
 * Returns the origin ("scheme://host[:port]") of an absolute http(s) URL, or null for a relative
 * URL, another scheme, or a host that is not made of plain hostname characters.
 *
 * @param string $url The URL.
 * @return string|null
 */
function url_origin(string $url): ?string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!\Lamb\Http\is_valid_http_url($url) || !preg_match('/^[\w.-]+$/', $host)) {
        return null;
    }
    $port = parse_url($url, PHP_URL_PORT);

    return strtolower(parse_url($url, PHP_URL_SCHEME) . '://' . $host) . ($port === null ? '' : ':' . $port);
}

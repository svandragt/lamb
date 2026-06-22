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

    printf('<meta property="description" content="%s"/>' . PHP_EOL, og_escape($description));

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
        'og:modified_time' => $bean->created,
        'og:published_time' => $bean->updated,
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
 * Resolves the OpenGraph/Twitter card image for a status post.
 *
 * Selection order, most specific first:
 *   1. the first image embedded in the post body — so sharing a photo/screenshot post
 *      previews that image (twitter:card upgrades to summary_large_image);
 *   2. a site default dropped in the web root as og-image.<ext>, user-replaceable in the
 *      same spirit as the favicon.png / logo.png feed convention;
 *   3. the shipped Lamb card.
 *
 * Width/height/type are emitted only when the image maps to a readable local file.
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
 * Maps a same-origin image URL to its filesystem path under ROOT_DIR, or null for remote
 * URLs (which can't be measured locally).
 *
 * @param string $url Image URL.
 * @return string|null
 */
function og_local_path(string $url): ?string
{
    if (!defined('ROOT_DIR')) {
        return null;
    }
    if (str_starts_with($url, ROOT_URL . '/')) {
        return ROOT_DIR . substr($url, strlen(ROOT_URL));
    }
    if (str_starts_with($url, '/')) {
        return ROOT_DIR . $url;
    }
    return null;
}

/**
 * Emits <link rel="preconnect"> and <link rel="dns-prefetch"> tags for origins in $config['preconnect'].
 * Does nothing when no preconnect origins are configured.
 *
 * @return void
 */
function the_preconnect(): void
{
    global $config;
    if (empty($config['preconnect'])) {
        return;
    }
    foreach ($config['preconnect'] as $origin) {
        printf('<link rel="preconnect" href="%s">' . PHP_EOL, escape($origin));
        printf('<link rel="dns-prefetch" href="%s">' . PHP_EOL, escape($origin));
    }
}

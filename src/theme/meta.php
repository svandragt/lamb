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

    $og_tags = [
        'og:description' => $description,
        'og:image' => ROOT_URL . '/images/og-image-lamb.jpg',
        'og:image:height' => '630',
        'og:image:type' => 'image/jpeg',
        'og:image:width' => '1200',
        'og:locale' => 'en_GB',
        'og:modified_time' => $bean->created,
        'og:published_time' => $bean->updated,
        'og:publisher' => ROOT_URL,
        'og:site_name' => $config['site_title'],
        'og:type' => 'article',
        'og:url' => permalink($bean),
        'twitter:card' => 'summary',
        'twitter:description' => $description,
        'twitter:domain' => $_SERVER["HTTP_HOST"],
        'twitter:image' => ROOT_URL . '/images/og-image-lamb.jpg',
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

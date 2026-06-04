<?php

global $config;
global $data;

if (!function_exists('escape')) {
    function escape(string $html): string
    {
        return htmlspecialchars($html, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE);
    }
}

header('Content-type: application/atom+xml');
$channel_link = $data['feed_url'] ?? ROOT_URL . '/feed';

$Xml = new SimpleXMLElement(
    '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:thr="http://purl.org/syndication/thread/1.0"></feed>'
);
$Xml->addChild('title', escape($data['title'] ?? $config['site_title']));
$Xml->addChild('id', escape($channel_link));
$Xml->addChild('updated', date(DATE_ATOM, strtotime($data['updated'])));
$Xml->addChild('generator', 'Lamb');

// Atom <icon> (square avatar) and <logo> (wider banner) are sourced by
// convention from the web root: drop favicon.png / logo.png next to index.php.
// Only emitted when the file actually exists, so we never advertise a broken
// image URL to feed readers (e.g. micro.blog renders <icon> as the avatar).
if (defined('ROOT_DIR')) {
    foreach (['favicon.png' => 'icon', 'logo.png' => 'logo'] as $file => $element) {
        if (file_exists(ROOT_DIR . '/' . $file)) {
            $Xml->addChild($element, escape(ROOT_URL . '/' . $file));
        }
    }
}

$Link = $Xml->addChild('atom:link');
$Link->addAttribute('rel', 'self');
$Link->addAttribute('href', escape($channel_link));

// WebSub: advertise the configured hubs so subscribers can get real-time pushes.
foreach (Lamb\Websub\hub_urls($config) as $websub_hub) {
    $Hub = $Xml->addChild('link');
    $Hub->addAttribute('rel', 'hub');
    $Hub->addAttribute('href', escape($websub_hub));
}

$Author = $Xml->addChild('author');
$Author->addChild('name', escape($config['author_name']));
$Author->addChild('uri', ROOT_URL);

foreach ($data['posts'] as $bean) {
    $Entry = $Xml->addChild('entry');
    $Entry->addChild('id', Lamb\permalink($bean));
    $Entry->addChild('title', escape($bean->title ?: ''));
    $Entry->addChild('published', date(DATE_ATOM, strtotime($bean->created)));
    $Entry->addChild('updated', date(DATE_ATOM, strtotime($bean->updated)));
    $Content = $Entry->addChild('content', $bean->transformed);
    $Content->addAttribute('type', 'html');
    if (!empty($bean->in_reply_to)) {
        // Raw URL: SimpleXML escapes attribute values itself, so pre-escaping
        // would double-encode any query-string ampersands.
        $Thread = $Entry->addChild('in-reply-to', null, 'http://purl.org/syndication/thread/1.0');
        $Thread->addAttribute('ref', $bean->in_reply_to);
        $Thread->addAttribute('href', $bean->in_reply_to);
    }
    $Link = $Entry->addChild('link');
    $Link->addAttribute('rel', 'alternate');
    $Link->addAttribute('type', 'text/html');
    $Link->addAttribute('href', Lamb\permalink($bean));
}
echo $Xml->asXML();

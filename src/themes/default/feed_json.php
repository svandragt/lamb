<?php

global $config;
global $data;

header('Content-type: application/feed+json');
$channel_link = $data['feed_url'] ?? ROOT_URL . '/feed.json';

$feed = [
    'version'       => 'https://jsonfeed.org/version/1.1',
    'title'         => $data['title'] ?? $config['site_title'],
    'home_page_url' => ROOT_URL,
    'feed_url'      => $channel_link,
    'authors'       => [
        [
            'name' => $config['author_name'] ?? '',
            'url'  => ROOT_URL,
        ],
    ],
    'items'         => [],
];

foreach ($data['posts'] as $bean) {
    $url = Lamb\permalink($bean);
    $item = [
        'id'             => $url,
        'url'            => $url,
        'content_html'   => $bean->transformed,
        'date_published' => date(DATE_RFC3339, strtotime($bean->created)),
        'date_modified'  => date(DATE_RFC3339, strtotime($bean->updated)),
    ];
    if (!empty($bean->title)) {
        $item['title'] = $bean->title;
    }
    $feed['items'][] = $item;
}

echo json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

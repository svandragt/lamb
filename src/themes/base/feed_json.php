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

// WebSub: advertise the configured hubs so subscribers can get real-time pushes.
$websub_hubs = \Lamb\Websub\hub_urls($config);
if ($websub_hubs !== []) {
    $feed['hubs'] = array_map(
        fn($hub) => ['type' => 'WebSub', 'url' => $hub],
        $websub_hubs
    );
}

foreach ($data['posts'] as $bean) {
    $url = Lamb\permalink($bean);
    $item = [
        'id'             => $url,
        'url'            => $url,
        // Reply context inside content_html as well as _microblog below: the
        // extension is a micro.blog convention, while the u-in-reply-to markup is
        // what a plain reader shows and what mf2 consumers parse.
        'content_html'   => Lamb\Theme\the_reply_context($bean) . Lamb\absolute_urls($bean->transformed),
        'date_published' => date(DATE_RFC3339, strtotime($bean->created)),
        'date_modified'  => date(DATE_RFC3339, strtotime($bean->updated)),
    ];
    if (!empty($bean->title)) {
        $item['title'] = $bean->title;
    }
    // Guarded like the reply context in content_html above: the consumer turns
    // this into a link, and in_reply_to is not author-only (a Micropub client
    // with `create` scope sets it, unvalidated).
    if (!empty($bean->in_reply_to) && Lamb\Http\is_valid_http_url((string) $bean->in_reply_to)) {
        // micro.blog reply convention.
        $item['_microblog'] = ['in_reply_to_url' => $bean->in_reply_to];
    }
    $feed['items'][] = $item;
}

// JSON_INVALID_UTF8_SUBSTITUTE: json_encode() returns false for the whole
// document if any one string is not valid UTF-8, which served subscribers an
// empty 200 with no clue why. A post stored before parse_bean() started
// repairing bodies can still hold such a byte, so the feed degrades that one
// character to U+FFFD instead of vanishing.
echo json_encode(
    $feed,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
);

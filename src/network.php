<?php

namespace Lamb\Network;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\set_option;

// MINUTE_IN_SECONDS is defined in constants.php
//
// Feed ingestion is split across src/network/ (all in this namespace):
//   - sources.php   feed config, SimplePie setup, RSS/Atom crawl recording
//   - json_feed.php JSON Feed (jsonfeed.org) detection, parsing and adapter
//   - ingest.php    turning a feed item into a post (dedup, slug, citation)
//   - status.php    the per-feed `feedstatus` health bean (read by the Logs tab)
//
// The `/_cron` route is registered centrally in Lamb\Route\register_app_routes().

#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');
    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if ((time() - $cron_last_updated->value) < MINUTE_IN_SECONDS) {
        die('Too often, try again later.');
    }

    $purged = purge_deleted_posts();
    if ($purged > 0) {
        echo("Purged $purged deleted post(s)." . PHP_EOL);
    }

    $pruned = prune_feed_status();
    if ($pruned > 0) {
        echo("Pruned $pruned stale feed status row(s)." . PHP_EOL);
    }

    $flattened = \Lamb\flatten_redirects();
    if ($flattened > 0) {
        echo("Flattened $flattened redirect(s)." . PHP_EOL);
    }

    echo("Updating feeds..." . PHP_EOL);
    foreach ($feeds as $name => $url) {
        flush();
        $status = feed_status_bean($name, $url);
        // The 30-minute fetch-frequency skip is gated on the last *attempt*, not the
        // last success, so a failing feed is retried on schedule rather than locked
        // out (and a healthy feed is not re-fetched within the window).
        if ((time() - (int)$status->last_attempt) < MINUTE_IN_SECONDS * 30) {
            echo('Skipped ' . $url . PHP_EOL);
            continue;
        }

        $result = crawl_feed($name, $url);
        if ($result['ok']) {
            printf("OK: %s - %d item(s) ingested" . PHP_EOL, $name, $result['items']);
        } else {
            printf("FAILED: %s - %s" . PHP_EOL, $name, $result['error']);
        }
    }

    $published = \Lamb\Websub\ping_scheduled_publishes();
    if ($published > 0) {
        printf("WebSub: pinged hub for %d scheduled post(s) now published." . PHP_EOL, $published);
    }

    $sent = \Lamb\Webmention\process_outbound();
    if ($sent['sent'] || $sent['failed'] || $sent['skipped'] || $sent['cancelled']) {
        printf(
            "Webmentions sent: %d, failed: %d, skipped: %d, cancelled: %d" . PHP_EOL,
            $sent['sent'],
            $sent['failed'],
            $sent['skipped'],
            $sent['cancelled']
        );
    }

    set_option($cron_last_updated, (int)date('U'));
    exit('Done');
}

/**
 * Fetches and ingests one configured feed, dispatching by source type: JSON Feed
 * URLs (`.json`) go through the JSON parser, everything else through SimplePie.
 * Returns the crawl outcome for the cron summary line.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return array{ok: bool, items: int, error: ?string}
 */
function crawl_feed(string $name, string $url): array
{
    // JSON Feed sources are not XML, so SimplePie cannot parse them. Route
    // .json URLs through a small JSON parser instead; RSS/Atom is unchanged.
    if (is_json_feed_url($url)) {
        echo PHP_EOL . "Processing " . $url . PHP_EOL;
        return record_json_feed_crawl($name, $url);
    }

    $feed = init_simplepie_feed($url);
    echo PHP_EOL . "Processing " . $feed->get_title() . PHP_EOL;
    return record_feed_crawl($name, $url, $feed);
}

/** @noinspection PhpUnused */
/**
 * Hard-delete posts that were soft-deleted more than 30 days ago.
 *
 * @return int Number of posts permanently deleted.
 */
function purge_deleted_posts(): int
{
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $posts  = R::find('post', ' deleted = 1 AND deleted_at < ? ', [$cutoff]);
    foreach ($posts as $post) {
        R::trash($post);
    }
    return count($posts);
}

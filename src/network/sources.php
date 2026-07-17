<?php

namespace Lamb\Network;

use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\Http\is_valid_http_url;

// FEED_FETCH_TIMEOUT is defined in constants.php

/**
 * @return array<array-key, mixed> Configured feed URLs keyed by feed name.
 */
function get_feeds(): array
{
    global $config;

    // A setting accidentally placed under [feeds] (e.g. `feeds_draft = false`)
    // would otherwise be fetched as a feed URL. Only keep http(s) URLs.
    return array_filter($config['feeds'] ?? [], fn($url) => is_valid_http_url((string) $url));
}

/**
 * Ensures the SimplePie cache directory exists, creating it when missing.
 *
 * SimplePie warns loudly (HTML in the text/plain cron output) when the cache
 * location is not writable, so create it up front and disable caching when
 * that fails.
 *
 * @param string $dir The cache directory path.
 * @return string|false The directory when usable, false otherwise.
 */
function ensure_feed_cache(string $dir): string|false
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return false;
    }
    return is_writable($dir) ? $dir : false;
}

/**
 * Builds and initialises a SimplePie instance for a feed URL, wiring up the
 * shared cache directory (disabling caching when it is not writable) and the
 * per-fetch timeout that stops a slow or hostile feed stalling the cron run.
 *
 * @param string $url The RSS/Atom feed URL.
 * @return SimplePie The initialised SimplePie instance.
 */
function init_simplepie_feed(string $url): SimplePie
{
    $feed = new SimplePie();
    $cache_dir = ensure_feed_cache('../data/cache/simplepie');
    if ($cache_dir === false) {
        $feed->enable_cache(false);
    } else {
        /** @noinspection PhpDeprecationInspection */
        $feed->set_cache_location($cache_dir);
    }
    $feed->set_feed_url($url);
    // Cap each fetch so a slow or hostile feed URL cannot stall the cron run.
    $feed->set_timeout(FEED_FETCH_TIMEOUT);
    $feed->init();

    return $feed;
}

/**
 * Crawls a single initialised feed and records the outcome on its feedstatus bean.
 *
 * A failed fetch (`!$feed->data` or a non-empty `$feed->error()`) does NOT advance the
 * success watermark — it only stamps `last_attempt` and records the error so the
 * Logs tab can surface it. On success, items newer than the watermark are created or
 * updated, the watermark advances, the item count is recorded and any prior error is
 * cleared.
 *
 * @param string    $name Feed name from config.
 * @param string    $url  Feed URL from config.
 * @param SimplePie $feed The initialised SimplePie instance.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_feed_crawl(string $name, string $url, SimplePie $feed): array
{
    $status = feed_status_bean($name, $url);
    $now    = (int)date('U');
    $status->last_attempt = $now;

    $error = $feed->error();
    if (is_array($error)) {
        $error = implode('; ', array_filter($error));
    }

    if (!$feed->data || $error) {
        $message = (string)($error ?: 'Feed fetch failed: no data returned.');
        $status->last_error    = $now;
        $status->error_message = $message;
        R::store($status);
        return ['ok' => false, 'items' => 0, 'error' => $message];
    }

    $watermark = (int)$status->last_success;
    $items     = 0;
    /** @var SimplePieItem $item */
    foreach ($feed->get_items() as $item) {
        if (ingest_item($item, $name, $watermark)) {
            $items++;
        }
    }

    $status->last_success  = $now;
    $status->item_count    = $items;
    $status->error_message = '';
    R::store($status);

    return ['ok' => true, 'items' => $items, 'error' => null];
}

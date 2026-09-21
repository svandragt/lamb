<?php

/** @noinspection PhpUnused */

namespace Lamb\Websub;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\is_draft;
use function Lamb\is_scheduled;
use function Lamb\set_option;

use const Lamb\SQL_PUBLISHED;
use const ROOT_URL;

/**
 * Option key holding the wall-clock time of the last scheduled-publish sweep.
 */
const SCHEDULED_PUBLISH_WATERMARK = 'websub_last_scheduled_publish';

/**
 * Seconds before a hub ping is abandoned. Pings run in the publish request,
 * so this is kept short to avoid a dead hub holding up the redirect.
 */
const WEBSUB_PING_TIMEOUT = 2;

/**
 * The configured WebSub hub URLs.
 *
 * `websub_hubs` is a comma-separated list; most sites need just one. Entries that
 * are not absolute http(s) URLs are dropped, matching the filtering get_feeds()
 * applies to feed sources: without it a stray value goes straight to the fetcher,
 * which would happily open any scheme PHP has a stream wrapper for (`file://`,
 * `php://…`) on the author's behalf.
 *
 * @param array<string, mixed>|null $config Config array; defaults to the global config.
 * @return list<string>
 */
function hub_urls(?array $config = null): array
{
    if ($config === null) {
        global $config;
    }
    $value = (string) (($config ?? [])['websub_hubs'] ?? '');

    $hubs = array_map('trim', explode(',', $value));
    return array_values(array_filter($hubs, fn($hub) => \Lamb\Http\is_valid_http_url($hub)));
}

/**
 * The feed URLs a hub is notified about.
 *
 * Every feed Lamb renders advertises `<link rel="hub">` (the Atom and JSON
 * renderers are shared, so the listings feed gets it too), and a hub
 * advertised on a topic it is never told about is worse than no hub at all:
 * the subscriber waits for a push that cannot arrive rather than falling back
 * to polling. So a feed that carries the hub link must appear here whenever
 * its contents can have changed.
 *
 * The listings topics are only added for a listing. Tying the ping to the post
 * that changed is the same rule the main feed follows, edge for edge: neither
 * feed is pinged when a post is deleted or edited out of it, because the only
 * hub ping is on `post.published` (see Post\register_default_subscribers()).
 *
 * @param bool $listings Whether the listings feeds changed too.
 * @return list<string>
 */
function feed_topics(bool $listings = false): array
{
    $topics = [ROOT_URL . '/feed', ROOT_URL . '/feed.json'];

    if ($listings) {
        $topics[] = ROOT_URL . '/listings/feed';
        $topics[] = ROOT_URL . '/listings/feed.json';
    }

    return $topics;
}

/**
 * Notify the configured hubs that the site's feeds have new content.
 *
 * Sends one `hub.mode=publish` ping per hub per feed URL (Atom and JSON).
 * A no-op when no hub is configured. Fire-and-forget: WebSub is best-effort,
 * subscribers fall back to polling if a ping is missed. The sender is
 * injectable for testing; in production it defaults to {@see send_ping}.
 *
 * @param array<string, mixed>|null $config Config array; defaults to the global config.
 * @param callable|null $sender fn(string $hub, string $topic): void.
 * @param list<string>|null $topics The feed URLs that changed; defaults to the
 *        site feeds alone, see {@see feed_topics}.
 * @return void
 */
function ping_hub(?array $config = null, ?callable $sender = null, ?array $topics = null): void
{
    $sender ??= __NAMESPACE__ . '\\send_ping';
    $topics ??= feed_topics();

    foreach (hub_urls($config) as $hub) {
        foreach ($topics as $topic) {
            $sender($hub, $topic);
        }
    }
}

/**
 * Ping the hub for a freshly saved post, if it is eligible.
 *
 * Skips ingested feed items, drafts, and future-dated scheduled posts — the
 * same eligibility rules as outbound webmentions.
 *
 * @param OODBBean      $bean   A stored post bean.
 * @param array<string, mixed>|null $config Config array; defaults to the global config.
 * @param callable|null $sender Injectable sender, see {@see ping_hub}.
 * @return void
 */
function ping_for_post(OODBBean $bean, ?array $config = null, ?callable $sender = null): void
{
    if (!$bean->id || !empty($bean->feed_name) || is_draft($bean)) {
        return;
    }
    if (is_scheduled($bean)) {
        return;
    }

    ping_hub($config, $sender, feed_topics(\Lamb\Listing\is_listing($bean)));
}

/**
 * POST a publish notification to the hub.
 *
 * @param string $hub   The hub endpoint URL.
 * @param string $topic The feed URL that changed.
 * @return void
 */
function send_ping(string $hub, string $topic): void
{
    // Every outbound POST goes through fetch_guarded(): it refuses loopback/
    // private/link-local destinations and re-validates every redirect hop, so a
    // hub URL cannot aim the publish request at an internal service.
    \Lamb\Http\fetch_guarded($hub, [
        'method' => 'POST',
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: Lamb-WebSub',
        ],
        'content' => http_build_query(['hub.mode' => 'publish', 'hub.url' => $topic]),
        'timeout' => WEBSUB_PING_TIMEOUT,
        'max_bytes' => 65536,
    ], 1);
}

/**
 * Ping the hub for scheduled posts whose publication time has arrived since the
 * last cron run. Intended to run from `/_cron`.
 *
 * ping_for_post() skips future-dated posts at save time, and a scheduled post
 * with no external links has no webmention queue row to piggyback on, so the
 * publication-time notification has to happen here. A watermark records the
 * wall-clock time of the previous sweep; a scheduled post that crossed from the
 * future into the past during the window pings the hub once (one ping covers
 * all crossings in the window, since the feed is the topic).
 *
 * Scheduled posts are distinguished from ordinary posts — which already pinged
 * at save time — by `created > updated`: their publish date is later than the
 * last save. The first sweep on an install records the watermark without
 * pinging, so a backlog of already-published posts is not swept into a single
 * catch-up ping.
 *
 * @param array<string, mixed>|null $config Config array; defaults to the global config.
 * @param callable|null $sender Injectable sender, see {@see ping_hub}.
 * @return int Number of scheduled posts found to have published this run.
 */
function ping_scheduled_publishes(?array $config = null, ?callable $sender = null): int
{
    $option = get_option(SCHEDULED_PUBLISH_WATERMARK, 0);
    $now    = time();

    if ((int) $option->id === 0) {
        set_option($option, $now);
        return 0;
    }

    $since   = date('Y-m-d H:i:s', (int) $option->value);
    $now_str = date('Y-m-d H:i:s', $now);

    $posts = R::find(
        'post',
        SQL_PUBLISHED
        . " AND (feed_name IS NULL OR feed_name = '') "
        . ' AND created > updated AND created > ? AND created <= ? ',
        [$since, $now_str]
    );

    $count = count($posts);
    if ($count > 0) {
        $listings = false;
        foreach ($posts as $post) {
            if (\Lamb\Listing\is_listing($post)) {
                $listings = true;
                break;
            }
        }
        ping_hub($config, $sender, feed_topics($listings));
    }

    set_option($option, $now);

    return $count;
}

<?php

/** @noinspection PhpUnused */

namespace Lamb\Websub;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\set_option;

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
 * `websub_hubs` is a comma-separated list; most sites need just one.
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
    return array_values(array_filter($hubs, fn($hub) => $hub !== ''));
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
 * @return void
 */
function ping_hub(?array $config = null, ?callable $sender = null): void
{
    $sender ??= __NAMESPACE__ . '\\send_ping';

    foreach (hub_urls($config) as $hub) {
        foreach ([ROOT_URL . '/feed', ROOT_URL . '/feed.json'] as $topic) {
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
    if (!$bean->id || !empty($bean->feed_name) || !empty($bean->draft)) {
        return;
    }
    if (!empty($bean->created) && strtotime((string) $bean->created) > time()) {
        return;
    }

    ping_hub($config, $sender);
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
    \Lamb\Http\post_form(
        $hub,
        ['hub.mode' => 'publish', 'hub.url' => $topic],
        WEBSUB_PING_TIMEOUT,
        'Lamb-WebSub'
    );
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
        ' (draft IS NULL OR draft != 1) AND (deleted IS NULL OR deleted != 1) '
        . " AND (feed_name IS NULL OR feed_name = '') "
        . ' AND created > updated AND created > ? AND created <= ? ',
        [$since, $now_str]
    );

    $count = count($posts);
    if ($count > 0) {
        ping_hub($config, $sender);
    }

    set_option($option, $now);

    return $count;
}

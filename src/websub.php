<?php

/** @noinspection PhpUnused */

namespace Lamb\Websub;

use RedBeanPHP\OODBBean;

use const ROOT_URL;

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
 * @param array|null $config Config array; defaults to the global config.
 * @return string[]
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
 * @param array|null    $config Config array; defaults to the global config.
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
 * @param array|null    $config Config array; defaults to the global config.
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

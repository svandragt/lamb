<?php

/** @noinspection PhpUnused */

namespace Lamb\Websub;

use RedBeanPHP\OODBBean;

use const ROOT_URL;

/**
 * Seconds before a hub ping is abandoned.
 */
const WEBSUB_PING_TIMEOUT = 5;

/**
 * The configured WebSub hub URL, or '' when none is set.
 *
 * @param array|null $config Config array; defaults to the global config.
 * @return string
 */
function hub_url(?array $config = null): string
{
    if ($config === null) {
        global $config;
    }
    return trim((string) (($config ?? [])['websub_hub'] ?? ''));
}

/**
 * Notify the configured hub that the site's feeds have new content.
 *
 * Sends one `hub.mode=publish` ping per feed URL (Atom and JSON). A no-op
 * when no hub is configured. The sender is injectable for testing; in
 * production it defaults to {@see send_ping}.
 *
 * @param array|null    $config Config array; defaults to the global config.
 * @param callable|null $sender fn(string $hub, string $topic): int (HTTP status).
 * @return array<string,int> Map of topic URL → HTTP status code.
 */
function ping_hub(?array $config = null, ?callable $sender = null): array
{
    $hub = hub_url($config);
    if ($hub === '') {
        return [];
    }

    $sender ??= __NAMESPACE__ . '\\send_ping';

    $results = [];
    foreach ([ROOT_URL . '/feed', ROOT_URL . '/feed.json'] as $topic) {
        $results[$topic] = (int) $sender($hub, $topic);
    }
    return $results;
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
 * POST a publish notification to the hub and return the HTTP status code.
 *
 * @param string $hub   The hub endpoint URL.
 * @param string $topic The feed URL that changed.
 * @return int HTTP status code, or 0 on transport failure.
 */
function send_ping(string $hub, string $topic): int
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Lamb-WebSub',
            ]),
            'content' => http_build_query(['hub.mode' => 'publish', 'hub.url' => $topic]),
            'timeout' => WEBSUB_PING_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);

    $http_response_header = [];
    @file_get_contents($hub, false, $context);

    $status = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
            $status = (int) $m[1];
        }
    }
    return $status;
}

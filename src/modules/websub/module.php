<?php

/**
 * WebSub: pings the configured hubs when a post is published and advertises
 * them in the Atom and JSON feeds. The `websub_hubs` setting still lives in core
 * config.
 */

namespace Lamb\Websub;

use Lamb\Hooks;

use function Lamb\Network\count_line;

require_once __DIR__ . '/websub.php';

return static function (): void {
    Hooks\add_action('post.published', __NAMESPACE__ . '\\ping_for_post');
    Hooks\add_filter(
        'feed.hubs',
        /**
         * @param list<string>         $hubs
         * @param array<string, mixed> $config
         * @return list<string>
         */
        static fn(array $hubs, array $config): array => [...$hubs, ...hub_urls($config)]
    );
    Hooks\add_action('cron.run', static function (callable $output): void {
        $output(count_line(
            ping_scheduled_publishes(),
            'WebSub: pinged hub for %d scheduled post(s) now published.'
        ));
    });
};

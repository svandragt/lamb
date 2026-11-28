<?php

/**
 * Micropub: the /micropub and /micropub-media endpoints (create, update,
 * delete and media uploads from Micropub clients) and their discovery links.
 * The endpoint code loads only on a Micropub request.
 */

namespace Lamb\Micropub;

use Lamb\Hooks;
use Lamb\Route;

use const ROOT_URL;

return static function (): void {
    Hooks\add_action('routes.register', static function (bool|string $action): void {
        if ($action === 'micropub' || $action === 'micropub-media') {
            require_once __DIR__ . '/micropub.php';
        }
        Route\register_route('micropub', __NAMESPACE__ . '\\respond_micropub');
        Route\register_route('micropub-media', __NAMESPACE__ . '\\respond_micropub_media');
    });
    Hooks\add_filter(
        'http.link_headers',
        /**
         * @param list<string> $links
         * @return list<string>
         */
        static fn(array $links): array => [...$links, '<' . ROOT_URL . '/micropub>; rel="micropub"']
    );
    Hooks\add_action('theme.head', static function (): void {
        echo '<link rel="micropub" href="' . ROOT_URL . '/micropub">' . "\n";
    });
};

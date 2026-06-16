<?php

namespace Lamb\Route;

use Lamb\Response;

/**
 * Registers a new route.
 *
 * @param bool|string $action The action to register. It can be a boolean or a string.
 * @param string $callback The callback function to execute when the route is accessed.
 * @param mixed ...$args Additional arguments to pass to the callback function.
 *
 * @return void
 */
function register_route(bool|string $action, string $callback, mixed ...$args): void
{
    global $routes;
    $routes[$action] = [$callback, $args];
}

/**
 * Registers a route that anonymous crawlers should not index — login-gated
 * admin pages/actions plus internal endpoints (login, logout, _cron). Behaves
 * exactly like register_route() but also records the action as private so
 * robots.txt can be derived from this single source of truth (see
 * Lamb\Response\robots_txt_body()). This keeps the crawler hint from drifting
 * out of sync with the routes it is meant to cover.
 *
 * @param bool|string $action The action to register.
 * @param string $callback The callback function to execute for the route.
 * @param mixed ...$args Additional arguments to pass to the callback function.
 *
 * @return void
 */
function register_private_route(bool|string $action, string $callback, mixed ...$args): void
{
    global $private_routes;
    $private_routes[$action] = true;
    register_route($action, $callback, ...$args);
}

/**
 * Returns the actions registered as private (i.e. disallowed for crawlers).
 *
 * @return list<int|string> The private route actions, in registration order.
 */
function private_routes(): array
{
    global $private_routes;
    return array_keys($private_routes ?? []);
}

/**
 * Registers every application route into the global registry.
 *
 * Extracted from index.php so the route table (and which routes are private)
 * is exercisable in isolation by tests. Routes whose handler requires a login,
 * plus the internal login/logout/_cron endpoints, are registered privately so
 * they are disallowed in robots.txt; everything else is publicly crawlable.
 *
 * @param bool|string $action The current request action (first path segment).
 * @param string|false|null $lookup The second path segment, passed to handlers.
 * @param string|false|null $sublookup The third path segment (tag feed switch).
 *
 * @return void
 */
function register_app_routes(
    bool|string $action,
    string|false|null $lookup = null,
    string|false|null $sublookup = null
): void {
    register_route(false, 'Lamb\\Response\\respond_404');
    register_route('404', 'Lamb\\Response\\respond_404');
    register_private_route('delete', 'Lamb\\Response\\redirect_deleted', $lookup);
    register_private_route('restore', 'Lamb\\Response\\redirect_restored', $lookup);
    register_private_route('drafts', 'Lamb\\Response\\respond_drafts');
    register_private_route('scheduled', 'Lamb\\Response\\respond_scheduled');
    register_private_route('trash', 'Lamb\\Response\\respond_trash');
    register_private_route('edit', 'Lamb\\Response\\respond_edit', $lookup);
    register_route('feed', 'Lamb\\Response\\respond_feed');
    register_route('feed.json', 'Lamb\\Response\\respond_feed_json');
    register_route('sitemap.xml', 'Lamb\\Response\\respond_sitemap');
    register_route('robots.txt', 'Lamb\\Response\\respond_robots');
    if ($action === 'home' && $lookup === 'feed') {
        register_route('home', 'Lamb\\Response\\respond_feed');
    } elseif ($action === 'home' && $lookup === 'feed.json') {
        register_route('home', 'Lamb\\Response\\respond_feed_json');
    } else {
        register_route('home', 'Lamb\\Response\\respond_home');
    }
    register_private_route('login', 'Lamb\\Response\\redirect_login');
    register_private_route('logout', 'Lamb\\Response\\redirect_logout');
    register_route('search', 'Lamb\\Response\\respond_search', $lookup);
    register_private_route('settings', 'Lamb\\Response\\respond_settings');
    register_route('status', 'Lamb\\Response\\respond_status', $lookup);
    if ($action === 'tag' && $sublookup === 'feed') {
        register_route('tag', 'Lamb\\Response\\respond_tag_feed', $lookup);
    } elseif ($action === 'tag' && $sublookup === 'feed.json') {
        register_route('tag', 'Lamb\\Response\\respond_tag_feed_json', $lookup);
    } else {
        register_route('tag', 'Lamb\\Response\\respond_tag', $lookup);
    }
    register_route('webmention', 'Lamb\\Webmention\\respond_webmention');
    register_route('micropub', 'Lamb\\Micropub\\respond_micropub');
    register_route('micropub-media', 'Lamb\\Micropub\\respond_micropub_media');
    register_private_route('upload', 'Lamb\\Response\\respond_upload', $lookup);
    register_private_route('checkbox', 'Lamb\\Response\\respond_checkbox', $lookup);
    register_private_route('_cron', 'Lamb\\Network\\process_feeds');
}

/**
 * Calls the callback function associated with the specified action and returns the result.
 *
 * @param bool|string $action The action to call the callback function for.
 *
 * @return array<string, mixed> The result of the callback function.
 */
function call_route(bool|string $action): array
{
    global $routes;
    [$callback, $args] = $routes[$action] ?? [null, []];

    if (is_null($callback)) {
        return Response\respond_404([], true);
    }

    return $callback($args);
}

/**
 * Checks if a given route is reserved.
 *
 * @param string $name The name of the route to check.
 *
 * @return bool True if the route is reserved, false otherwise.
 */
function is_reserved_route(string $name): bool
{
    global $routes;

    return isset($routes[$name]);
}

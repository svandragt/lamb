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
 * Calls the callback function associated with the specified action and returns the result.
 *
 * @param bool|string $action The action to call the callback function for.
 *
 * @return array The result of the callback function.
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

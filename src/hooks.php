<?php

/**
 * The hook API: the one seam optional features plug into.
 *
 * Two kinds of hook share a registry:
 *  - an action runs callbacks for their side effects (do_action());
 *  - a filter threads a value through callbacks and returns the result
 *    (apply_filters()).
 *
 * Callbacks run by ascending priority, then registration order. Core code
 * fires hooks; modules (src/modules/<name>/module.php) only register them, so
 * removing a module's directory removes the feature without touching core.
 *
 * Hooks fired by core:
 *  - post.created, post.updated, post.published (action, OODBBean $bean)
 *  - routes.register (action, $action, $lookup, $sublookup) — call
 *    Route\register_route() here to add routes.
 *  - http.link_headers (filter, list<string> $links) — `Link:` header values.
 *  - feed.hubs (filter, list<string> $hubs, array $config) — WebSub hub URLs
 *    advertised in the Atom and JSON feeds.
 *  - cron.run (action, callable(string): void $output) — work for each /_cron
 *    run, after the feeds are crawled; write result lines to $output.
 */

namespace Lamb\Hooks;

const DEFAULT_PRIORITY = 10;

/**
 * The hook registry: hook name => priority => list of callbacks.
 *
 * @return array<string, array<int, list<callable>>> The mutable registry.
 */
function &registry(): array
{
    static $hooks = [];

    return $hooks;
}

/**
 * Registers a callback on a hook.
 *
 * @param string   $hook     The hook name.
 * @param callable $callback The callback.
 * @param int      $priority Lower runs earlier.
 * @return void
 */
function add(string $hook, callable $callback, int $priority = DEFAULT_PRIORITY): void
{
    $hooks = &registry();
    $hooks[$hook][$priority][] = $callback;
    ksort($hooks[$hook]);
}

/**
 * Registers an action callback. Alias of add(), named for the call site.
 *
 * @param string   $hook     The hook name.
 * @param callable $callback fn(mixed ...$args): void.
 * @param int      $priority Lower runs earlier.
 * @return void
 */
function add_action(string $hook, callable $callback, int $priority = DEFAULT_PRIORITY): void
{
    add($hook, $callback, $priority);
}

/**
 * Registers a filter callback. Alias of add(), named for the call site.
 *
 * @param string   $hook     The hook name.
 * @param callable $callback fn(mixed $value, mixed ...$args): mixed.
 * @param int      $priority Lower runs earlier.
 * @return void
 */
function add_filter(string $hook, callable $callback, int $priority = DEFAULT_PRIORITY): void
{
    add($hook, $callback, $priority);
}

/**
 * Returns a hook's callbacks in the order they run.
 *
 * @param string $hook The hook name.
 * @return list<callable> The callbacks.
 */
function callbacks(string $hook): array
{
    $by_priority = registry()[$hook] ?? [];

    return $by_priority === [] ? [] : array_merge(...array_values($by_priority));
}

/**
 * Whether any callback is registered on a hook.
 *
 * @param string $hook The hook name.
 * @return bool True when at least one callback is registered.
 */
function has(string $hook): bool
{
    return isset(registry()[$hook]);
}

/**
 * Runs every callback on an action hook.
 *
 * @param string $hook    The hook name.
 * @param mixed  ...$args Passed to each callback.
 * @return void
 */
function do_action(string $hook, mixed ...$args): void
{
    foreach (callbacks($hook) as $callback) {
        $callback(...$args);
    }
}

/**
 * Threads a value through every callback on a filter hook.
 *
 * @param string $hook    The hook name.
 * @param mixed  $value   The value to filter.
 * @param mixed  ...$args Extra context passed to each callback.
 * @return mixed The filtered value.
 */
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (callbacks($hook) as $callback) {
        $value = $callback($value, ...$args);
    }

    return $value;
}

/**
 * Clears callbacks: one hook's, or every hook's. For test isolation;
 * production wiring runs once per request.
 *
 * @param string|null $hook The hook to clear, or null for all.
 * @return void
 */
function reset(?string $hook = null): void
{
    $hooks = &registry();
    if ($hook === null) {
        $hooks = [];
        return;
    }
    unset($hooks[$hook]);
}

/**
 * Loads every module under a directory.
 *
 * A module is a directory holding `module.php`, which returns a callable that
 * registers the module's hooks. Modules load in directory-name order, each at
 * most once per process. A module that fails to load is logged and skipped, so
 * one broken module cannot take down the site (and the login to fix it).
 *
 * @param string $dir The modules directory.
 * @return list<string> The names of the loaded modules.
 */
function load_modules(string $dir): array
{
    static $seen = [];

    $files = glob(rtrim($dir, '/') . '/*/module.php') ?: [];
    sort($files);
    $loaded = [];
    foreach ($files as $file) {
        $key = realpath($file) ?: $file;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        try {
            $register = require $file;
            if (!is_callable($register)) {
                error_log(sprintf('Module %s did not return a callable; skipped.', $file));
                continue;
            }
            $register();
        } catch (\Throwable $e) {
            error_log(sprintf('Module %s failed to load: %s: %s', $file, $e::class, $e->getMessage()));
            continue;
        }
        $loaded[] = basename(dirname($file));
    }

    return $loaded;
}

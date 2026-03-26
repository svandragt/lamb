<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\parse_bean;

define('LOGIN_PASSWORD', getenv("LAMB_LOGIN_PASSWORD"));

// IMAGE_FILES is defined in constants.php

/**
 * Returns cookie options with the given expiry timestamp.
 *
 * @param int $expires Unix timestamp for cookie expiry.
 * @return array Cookie options array.
 */
function get_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

/**
 * Builds a SQL NOT IN clause for excluding posts by slug.
 *
 * @param array $slugs Slugs to exclude.
 * @return array{sql: string, params: array}|null Clause and params, or null when list is empty.
 */
function build_exclude_slugs_clause(array $slugs): ?array
{
    if (empty($slugs)) {
        return null;
    }
    $slots = implode(', ', array_fill(0, count($slugs), '?'));
    return [
        'sql'    => " slug NOT IN ($slots) ",
        'params' => $slugs,
    ];
}

/**
 * Builds the pagination metadata array from pre-computed values.
 *
 * @param int $page         Current page number (1-based).
 * @param int $per_page     Items per page.
 * @param int $total_posts  Total number of matching posts.
 * @param int $offset       Row offset for the current page.
 * @return array
 */
function build_pagination_meta(int $page, int $per_page, int $total_posts, int $offset): array
{
    $total_pages = $total_posts > 0 ? (int)ceil($total_posts / $per_page) : 1;
    return [
        'current'     => $page,
        'per_page'    => $per_page,
        'total_posts' => $total_posts,
        'total_pages' => $total_pages,
        'prev_page'   => $page > 1 ? $page - 1 : null,
        'next_page'   => $page < $total_pages ? $page + 1 : null,
        'offset'      => $offset,
    ];
}

/**
 * Redirects the user to a 404 page with the provided fallback URL.
 *
 * @param string $fallback The URL to redirect to if the 404 page is not available.
 * @return void
 */
#[NoReturn]
function redirect_404(string $fallback): void
{
    global $request_uri;
    header("Location: $fallback$request_uri");
    die("Redirecting to $fallback$request_uri");
}

/**
 * Responds with a 404 error page.
 *
 * @param array $_args   Unused.
 * @param bool  $use_fallback Whether to redirect to the configured fallback URL.
 * @return array An array containing the title, intro, and action of the 404 error page.
 */
function respond_404(array $_args = [], bool $use_fallback = false): array
{
    global $config;
    if ($use_fallback && isset($config['404_fallback'])) {
        $fallback = $config['404_fallback'];
        if (filter_var($fallback, FILTER_VALIDATE_URL)) {
            redirect_404($fallback);
        }
    }
    $header = "HTTP/1.0 404 Not Found";
    header($header);

    return [
        'title' => $header,
        'intro' => 'Page not found.',
        'action' => '404',
    ];
}

/**
 * Redirects the user to a specified URL.
 *
 * @param string $where The URL to redirect to. If empty, redirects to the root URL.
 * @return never
 */
#[NoReturn]
function redirect_uri(string $where): never
{
    if (empty($where)) {
        $where = '/';
    }
    header("Location: $where");
    die("Redirecting to $where");
}

/**
 * Upgrades the given posts by transforming the beans and storing them in the database if not already transformed.
 *
 * @param array $posts The array of posts to upgrade.
 * @return void
 */
function upgrade_posts(array $posts): void
{
    foreach ($posts as $bean) {
        if ($bean === null) {
            continue;
        }
        switch ($bean->version) {
            case 1:
                # Get all beans on the current version 1.
                break;
            default:
                parse_bean($bean);
                try {
                    $bean->version = 1;
                    R::store($bean);
                } catch (SQL $e) {
                    $_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
                }
        }
    }
}

/**
 * Paginates an in-memory array of items.
 *
 * @param array $values   Flat array of items to paginate.
 * @param int   $page     Current page (1-based, already clamped by caller).
 * @param int   $per_page Items per page.
 * @return array{items: array, pagination: array}
 */
function paginate_array(array $values, int $page, int $per_page): array
{
    $total_posts = count($values);
    $total_pages = $total_posts > 0 ? (int)ceil($total_posts / $per_page) : 1;
    $page   = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    return [
        'items'      => array_slice($values, $offset, $per_page),
        'pagination' => build_pagination_meta($page, $per_page, $total_posts, $offset),
    ];
}

/**
 * Paginates a database bean type with an optional WHERE clause.
 *
 * @param string      $bean_type       RedBeanPHP bean type.
 * @param string      $order_by_clause SQL ORDER BY expression (without keyword).
 * @param string|null $where_sql       Optional WHERE clause.
 * @param array       $params          Bound parameters for the WHERE clause.
 * @param int         $page            Current page (1-based, already clamped by caller).
 * @param int         $per_page        Items per page.
 * @return array{items: array, pagination: array}
 */
function paginate_db(string $bean_type, string $order_by_clause, ?string $where_sql, array $params, int $page, int $per_page): array
{
    $total_posts = !empty($where_sql) ? R::count($bean_type, $where_sql, $params) : R::count($bean_type);

    $total_pages = $total_posts > 0 ? (int)ceil($total_posts / $per_page) : 1;
    $page   = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    if (!empty($where_sql)) {
        // When params are provided, use R::find with param binding and append offset/limit
        $find_params   = $params;
        $find_params[] = (int)$offset;
        $find_params[] = (int)$per_page;
        $items = R::find($bean_type, $where_sql . ' ORDER BY ' . $order_by_clause . ' LIMIT ?, ?', $find_params);
    } else {
        // No params: safe to use the simpler findAll with a constructed LIMIT
        $items = R::findAll($bean_type, 'ORDER BY ' . $order_by_clause . ' LIMIT ' . (int)$offset . ', ' . $per_page);
    }

    upgrade_posts($items);
    return [
        'items'      => $items,
        'pagination' => build_pagination_meta($page, $per_page, $total_posts, $offset),
    ];
}

/**
 * Paginates a collection of posts, either from an array or a database query.
 *
 * @param mixed       $source          Array of items, or a bean type string for DB pagination.
 * @param string      $order_by_clause SQL ORDER BY expression (DB path only).
 * @param string|null $where_sql       Optional WHERE clause (DB path only).
 * @param array       $params          Bound parameters for the WHERE clause.
 * @param int|null    $per_page        Items per page; falls back to config when null.
 * @param int|null    $page            Current page; falls back to $_GET['page'] when null.
 * @return array{items: array, pagination: array}
 */
function paginate_posts(mixed $source, string $order_by_clause = 'created DESC', ?string $where_sql = null, array $params = [], ?int $per_page = null, ?int $page = null): array
{
    // Explicit $per_page avoids the global; fall back to config only when not provided.
    if ($per_page === null) {
        global $config;
        $per_page = (int)($config['posts_per_page'] ?? 10);
    }

    // Explicit $page avoids the superglobal; fall back to $_GET only when not provided.
    $page = $page ?? max(1, (int)($_GET['page'] ?? 1));

    if (is_array($source)) {
        return paginate_array(array_values($source), $page, $per_page);
    }

    return paginate_db((string)$source, $order_by_clause, $where_sql, $params, $page, $per_page);
}

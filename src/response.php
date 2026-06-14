<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\parse_bean;
use function Lamb\Post\inject_title_matter;
use function Lamb\Post\parse_matter;

define('LOGIN_PASSWORD', getenv("LAMB_LOGIN_PASSWORD") ?: '');

// IMAGE_FILES is defined in constants.php

/**
 * Returns cookie options with the given expiry timestamp.
 *
 * @param int $expires Unix timestamp for cookie expiry.
 * @return array<string, mixed> Cookie options array.
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
 * Returns the Unix timestamp of the most recently updated published post.
 *
 * Used as a coarse, monotonic content validator for conditional GETs: any post
 * edit/publish moves it forward, so anonymous pages revalidate; the max-age
 * window covers edge cases (config edits, scheduled posts going live).
 *
 * @return int Unix timestamp, or 0 when there is no published content yet.
 */
function latest_content_timestamp(): int
{
    $latest = R::findOne('post', \Lamb\SQL_PUBLISHED . ' ORDER BY updated DESC LIMIT 1');
    $post_ts = ($latest !== null && !empty($latest->updated)) ? (strtotime($latest->updated) ?: 0) : 0;
    return max($post_ts, \Lamb\Config\config_modified_timestamp());
}

/**
 * Emits ETag/Last-Modified validators for a cacheable response and short-circuits
 * with 304 Not Modified when the client already holds the current version.
 *
 * No-ops when there is no content timestamp. Callers must only use this for
 * cacheable (anonymous, non-error) GET/HEAD responses, before any output.
 *
 * Last-Modified is the second-resolution max() of the two timestamps (the only
 * resolution HTTP-date supports), while the ETag keeps them distinct so a config
 * edit in the same second as the latest post still invalidates caches (#279).
 *
 * @param int $contentTs Unix timestamp of the most recent content change.
 * @param int $configTs  Unix timestamp of the last config edit.
 * @return void
 */
function send_304_if_current(int $contentTs, int $configTs): void
{
    $lastModifiedTs = max($contentTs, $configTs);
    if ($lastModifiedTs <= 0) {
        return;
    }
    $etag = \Lamb\Bootstrap\content_etag($contentTs, $configTs);
    header('ETag: ' . $etag);
    header('Last-Modified: ' . \Lamb\Bootstrap\http_date($lastModifiedTs));
    if (\Lamb\Bootstrap\client_has_current_version($_SERVER, $etag, $lastModifiedTs)) {
        http_response_code(304);
        exit;
    }
}

/**
 * Builds a SQL NOT IN clause for excluding posts by slug.
 *
 * @param list<string> $slugs Slugs to exclude.
 * @return array{sql: string, params: list<string>}|null Clause and params, or null when list is empty.
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
 * @return array{current: int, per_page: int, total_posts: int, total_pages: int, prev_page: int|null, next_page: int|null, offset: int}
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
    $location = \Lamb\Http\sanitize_location($fallback . $request_uri);
    header("Location: $location");
    die();
}

/**
 * Responds with a 404 error page.
 *
 * @param array<int, string> $_args   Unused.
 * @param bool  $use_fallback Whether to redirect to the configured fallback URL.
 * @return array{title: string, intro: string, action: string} An array containing the title, intro, and action of the 404 error page.
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
    $location = \Lamb\Http\sanitize_location($where);
    header("Location: $location");
    die();
}

/**
 * Upgrades the given posts by transforming the beans and storing them in the database if not already transformed.
 *
 * @param array<int, mixed> $posts The array of posts to upgrade.
 * @return void
 */
function upgrade_posts(array $posts): void
{
    foreach ($posts as $bean) {
        if (!$bean instanceof \RedBeanPHP\OODBBean) {
            continue;
        }
        if ((int)$bean->version === POST_VERSION) {
            continue;
        }
        // An upgrade re-parse is not an edit, so it must not apply edit
        // semantics to fields the body doesn't carry. Legacy posts (old feed
        // items) store their title only on the title column: migrate it into
        // the body's front matter so parse_bean() doesn't clear it. Slugs are
        // reserved/adjusted at publish time, so the stored slug is restored
        // unconditionally (good URLs don't change, and slug-less posts must
        // not be minted one). Column-only drafts (feeds_draft) are restored
        // when front matter carries no draft key, so the upgrade can't
        // publish them.
        $matter = parse_matter($bean->body);
        if (!empty($bean->title) && !isset($matter['title'])) {
            $bean->body = inject_title_matter($bean->body, (string)$bean->title);
        }
        $previous_slug = $bean->slug;
        $previous_draft = $bean->draft;
        parse_bean($bean);
        $bean->slug = $previous_slug;
        if (!isset($matter['draft'])) {
            $bean->draft = $previous_draft;
        }
        try {
            $bean->version = POST_VERSION;
            R::store($bean);
        } catch (SQL $e) {
            $_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
        }
    }
}

/**
 * Paginates an in-memory array of items.
 *
 * @param list<mixed> $values   Flat array of items to paginate.
 * @param int   $page     Current page (1-based, already clamped by caller).
 * @param int   $per_page Items per page.
 * @return array{items: list<mixed>, pagination: array<string, mixed>}
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
 * @param array<int, mixed> $params    Bound parameters for the WHERE clause.
 * @param int         $page            Current page (1-based, already clamped by caller).
 * @param int         $per_page        Items per page.
 * @return array{items: array<int, \RedBeanPHP\OODBBean>, pagination: array<string, mixed>}
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
 * @param array<int, mixed> $params    Bound parameters for the WHERE clause.
 * @param int|null    $per_page        Items per page; falls back to config when null.
 * @param int|null    $page            Current page; falls back to $_GET['page'] when null.
 * @return array{items: array<int, mixed>, pagination: array<string, mixed>}
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

<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Security;
use RedBeanPHP\R;

use function Lamb\Post\posts_by_tag;
use function Lamb\Theme\part;

use const Lamb\SQL_IS_DELETED;
use const Lamb\SQL_IS_DRAFT;
use const Lamb\SQL_IS_SCHEDULED;
use const ROOT_URL;

/**
 * Retrieves and prepares the homepage data, including paginated posts and site title.
 *
 * @return array The prepared homepage data, including posts, pagination details, and the site title.
 */
function respond_home(): array
{
    global $config;
    if (!empty($_POST)) {
        redirect_created();
    }

    $data['title'] = $config['site_title'];

    $visible = \Lamb\visible_clause();
    $where_sql = $visible['sql'];
    $where_params = $visible['params'];
    $clause = build_exclude_slugs_clause(Config\get_menu_slugs());
    if ($clause !== null) {
        $where_sql .= ' AND ' . $clause['sql'];
        $where_params = array_merge($where_params, $clause['params']);
    }
    $paginated = paginate_posts('post', 'created DESC', $where_sql, $where_params);
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    return $data;
}

/**
 * Responds with the drafts page showing all draft posts (login required).
 *
 * @return array The drafts page data including posts and pagination.
 */
function respond_drafts(): array
{
    Security\require_login();

    $data['title'] = 'Drafts';
    $paginated = paginate_posts('post', 'created DESC', SQL_IS_DRAFT);
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    return $data;
}

/**
 * Returns paginated soft-deleted posts for the Trash view.
 *
 * @return array
 */
function respond_trash(): array
{
    Security\require_login();

    $data['title'] = 'Trash';
    $paginated = paginate_posts('post', 'deleted_at DESC', SQL_IS_DELETED);
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    return $data;
}

/**
 * Returns the count of draft posts.
 *
 * @return int
 */
function count_drafts(): int
{
    return R::count('post', SQL_IS_DRAFT);
}

/**
 * Returns the count of soft-deleted (trashed) posts.
 *
 * @return int
 */
function count_trash(): int
{
    return R::count('post', SQL_IS_DELETED);
}

/**
 * Responds with the scheduled page showing posts dated in the future (login required).
 *
 * @return array The scheduled page data including posts and pagination.
 */
function respond_scheduled(): array
{
    Security\require_login();

    $data['title'] = 'Scheduled';
    $paginated = paginate_posts('post', 'created ASC', SQL_IS_SCHEDULED, [\Lamb\now()]);
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    return $data;
}

/**
 * Returns the count of scheduled (future-dated) posts.
 *
 * @return int
 */
function count_scheduled(): int
{
    return R::count('post', SQL_IS_SCHEDULED, [\Lamb\now()]);
}

/**
 * Redirects the user to a search page with the provided query.
 *
 * @param string $query The search query to be included in the redirected URL.
 * @return void
 */
#[NoReturn]
function redirect_search(string $query): void
{
    header("Location: /search/$query");
    die("Redirecting to /search/$query");
}

/**
 * Returns the updated timestamp for the feed.
 *
 * @param array $posts List of post beans.
 * @return string Date string suitable for strtotime(), falls back to current time when empty.
 */
function get_feed_updated_date(array $posts): string
{
    $first = reset($posts);
    return $first !== false ? $first->updated : \Lamb\now();
}

/**
 * Returns the data needed to render the main Atom feed.
 *
 * @return array{posts: array, title: string, feed_url: string, updated: string}
 */
function get_feed_data(): array
{
    global $config;

    $visible = \Lamb\visible_clause();
    $clause = build_exclude_slugs_clause(Config\get_menu_slugs());
    if ($clause !== null) {
        $posts = R::find(
            'post',
            $clause['sql'] . ' AND' . $visible['sql'] . 'ORDER BY updated DESC LIMIT 20',
            array_merge($clause['params'], $visible['params'])
        );
    } else {
        $posts = R::find(
            'post',
            $visible['sql'] . 'ORDER BY updated DESC LIMIT 20',
            $visible['params']
        );
    }

    $first_post = reset($posts);
    return [
        'updated'  => $first_post['updated'] ?? \Lamb\now(),
        'title'    => $config['site_title'],
        'feed_url' => ROOT_URL . '/feed',
        'posts'    => $posts,
    ];
}

/**
 * Sets caching headers for an anonymous feed response.
 *
 * Feeds are polled by readers rather than browsed, so they get a longer max-age
 * than regular pages, plus a conditional-GET 304 short-circuit keyed on the
 * feed's freshest item. No-op for logged-in users (their responses are private).
 *
 * @param string $updated The feed's latest-updated datetime string.
 * @return void
 */
function feed_cache(string $updated): void
{
    if (isset($_SESSION[SESSION_LOGIN])) {
        return;
    }
    header('Cache-Control: max-age=1800');
    // Fold in config edits so changes to feed-affecting settings (title, menu
    // exclusions, …) invalidate cached feeds immediately.
    $config_ts = Config\config_modified_timestamp();
    $ts = max(strtotime($updated) ?: 0, $config_ts);
    send_304_if_current($ts, $config_ts);
}

/**
 * Decodes and HTML-escapes the tag name from a tag-feed route's arguments.
 *
 * @param array $args Route arguments; the first element is the raw tag segment.
 * @return string The sanitised tag name.
 */
function sanitize_tag_arg(array $args): string
{
    [$tag] = $args;

    return htmlspecialchars(urldecode($tag));
}

/**
 * Renders a feed template with the given feed data and terminates the request.
 *
 * Shared tail of all four feed responders: merge the feed data into the global
 * view data, emit cache headers (with a conditional-GET 304 short-circuit),
 * upgrade stale posts, render, die.
 *
 * @param array       $feed_data As built by get_feed_data()/get_tag_feed_data().
 * @param string      $template  Feed template name ('feed' or 'feed_json').
 * @param string|null $feed_url  Optional feed_url override (the JSON variants).
 * @return never
 */
function emit_feed(array $feed_data, string $template, ?string $feed_url = null): never
{
    global $data;

    foreach ($feed_data as $key => $value) {
        $data[$key] = $value;
    }
    if ($feed_url !== null) {
        $data['feed_url'] = $feed_url;
    }
    feed_cache($data['updated']);
    upgrade_posts($data['posts']);

    part($template, '');
    die();
}

/**
 * Responds to a feed request by fetching and rendering the Atom feed.
 *
 * @return void
 */
#[NoReturn]
function respond_feed(): void
{
    emit_feed(get_feed_data(), 'feed');
}

/**
 * Responds to a JSON feed request by fetching and rendering the JSON Feed.
 *
 * @return void
 */
#[NoReturn]
function respond_feed_json(): void
{
    emit_feed(get_feed_data(), 'feed_json', ROOT_URL . '/feed.json');
}

/**
 * Returns the data needed to render a tag Atom feed.
 *
 * @param string $tag The already-sanitised tag name.
 * @return array{posts: array, title: string, feed_url: string, updated: string}
 */
function get_tag_feed_data(string $tag): array
{
    global $config;

    $all_posts = posts_by_tag($tag);

    // Sort by updated DESC and limit to 20
    $posts = array_values($all_posts);
    usort($posts, fn($a, $b) => strtotime($b->updated) - strtotime($a->updated));
    $posts = array_slice($posts, 0, 20);

    return [
        'updated'  => get_feed_updated_date($posts),
        'title'    => $config['site_title'] . ' — #' . $tag,
        'feed_url' => ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed',
        'posts'    => $posts,
    ];
}

/**
 * Responds to a tag feed request by rendering an Atom feed for posts with a specific tag.
 *
 * @param array $args An array where the first element is the tag name.
 * @return void
 */
#[NoReturn]
function respond_tag_feed(array $args): void
{
    emit_feed(get_tag_feed_data(sanitize_tag_arg($args)), 'feed');
}

/**
 * Responds to a tag JSON feed request by rendering a JSON Feed for posts with a specific tag.
 *
 * @param array $args An array where the first element is the tag name.
 * @return void
 */
#[NoReturn]
function respond_tag_feed_json(array $args): void
{
    $tag = sanitize_tag_arg($args);
    emit_feed(get_tag_feed_data($tag), 'feed_json', ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed.json');
}

/**
 * Responds to a search query with paginated results.
 *
 * @param array $args The first element should be the search query.
 * @return array
 */
function respond_search(array $args): array
{
    $query = urldecode($args[0] ?? '');
    if (empty($query)) {
        $query = $_GET['s'] ?? '';
        if (empty($query)) {
            return [];
        }
        redirect_search($query);
    }
    // Keep $query raw: SQL uses bound parameters, and every output path
    // (page title, search box) escapes at render time. Escaping here too would
    // double-encode HTML metacharacters in the displayed search term.

    // Support multiple words filtering
    $words = explode(' ', $query);
    $where_clauses = [];
    $params = [];
    foreach ($words as $word) {
        $where_clauses[] = 'body LIKE ?';
        $params[] = "%$word%";
    }
    $visible = \Lamb\visible_clause();
    $where_sql = '(' . implode(' AND ', $where_clauses) . ') AND' . $visible['sql'];
    $params = array_merge($params, $visible['params']);

    $paginated = paginate_posts('post', 'created DESC', $where_sql, $params);

    $data['query'] = $query;
    $data['title'] = 'Searched for "' . $query . '"';
    return get_results($data, $paginated['items'], $paginated['pagination']);
}

/**
 * Builds the response array for search/tag results, including intro text and pagination.
 *
 * @param array $data       Base data array to enrich.
 * @param array $posts      Posts for the current page.
 * @param array $pagination Pagination metadata, as built by build_pagination_meta().
 * @return array
 */
function get_results(array $data, array $posts, array $pagination): array
{
    $total_posts = (int) $pagination['total_posts'];
    if ($total_posts > 0) {
        $result = ngettext("result", "results", $total_posts);
        $data['intro'] = $total_posts . " $result found.";
    } else {
        $data['intro'] = "No results found.";
    }

    $data['posts'] = $posts;
    $data['pagination'] = $pagination;

    upgrade_posts($posts);

    return $data;
}

/**
 * Retrieves posts tagged with the given tag and returns paginated, enriched data.
 *
 * @param array $args The first element is the tag name.
 * @return array
 */
function respond_tag(array $args): array
{
    [$tag] = $args;
    $tag = urldecode($tag);
    // Keep $tag raw: matching, URL-encoding and the page title each handle it
    // correctly, and the title is escaped at render time (so no double-encoding).

    // Get all posts for this tag (in-memory array)
    $all_posts = posts_by_tag($tag);

    if (empty($all_posts)) {
        return respond_404();
    }

    $paginated = paginate_posts($all_posts);

    $data['title'] = 'Tagged with #' . $tag;
    $data['feed_url'] = ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed';
    return get_results($data, $paginated['items'], $paginated['pagination']);
}

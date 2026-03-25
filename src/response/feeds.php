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
use const Lamb\SQL_PUBLISHED;
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

    $where_sql = SQL_PUBLISHED;
    $where_params = [];
    $clause = build_exclude_slugs_clause(Config\get_menu_slugs());
    if ($clause !== null) {
        $where_sql .= ' AND ' . $clause['sql'];
        $where_params = $clause['params'];
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
    return $first !== false ? $first->updated : date('Y-m-d H:i:s');
}

/**
 * Returns the data needed to render the main Atom feed.
 *
 * @return array{posts: array, title: string, feed_url: string, updated: string}
 */
function get_feed_data(): array
{
    global $config;

    $clause = build_exclude_slugs_clause(Config\get_menu_slugs());
    if ($clause !== null) {
        $posts = R::find(
            'post',
            $clause['sql'] . ' AND' . SQL_PUBLISHED . 'ORDER BY updated DESC LIMIT 20',
            $clause['params']
        );
    } else {
        $posts = R::findAll('post', SQL_PUBLISHED . 'ORDER BY updated DESC LIMIT 20');
    }

    $first_post = reset($posts);
    return [
        'updated'  => $first_post['updated'] ?? date('Y-m-d H:i:s'),
        'title'    => $config['site_title'],
        'feed_url' => ROOT_URL . '/feed',
        'posts'    => $posts,
    ];
}

/**
 * Responds to a feed request by fetching and rendering the Atom feed.
 *
 * @return void
 */
#[NoReturn]
function respond_feed(): void
{
    global $data;

    $feed_data = get_feed_data();
    foreach ($feed_data as $key => $value) {
        $data[$key] = $value;
    }
    upgrade_posts($data['posts']);

    part("feed", '');
    die();
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
    global $data;

    [$tag] = $args;
    $tag = urldecode($tag);
    $tag = htmlspecialchars($tag);

    $feed_data = get_tag_feed_data($tag);
    foreach ($feed_data as $key => $value) {
        $data[$key] = $value;
    }
    upgrade_posts($data['posts']);

    part("feed", '');
    die();
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
    $query = htmlspecialchars($query);

    // Support multiple words filtering
    $words = explode(' ', $query);
    $where_clauses = [];
    $params = [];
    foreach ($words as $word) {
        $where_clauses[] = 'body LIKE ?';
        $params[] = "%$word%";
    }
    $where_sql = '(' . implode(' AND ', $where_clauses) . ') AND' . SQL_PUBLISHED;

    $paginated = paginate_posts('post', 'created DESC', $where_sql, $params);

    $data['query'] = $query;
    $data['title'] = 'Searched for "' . $query . '"';
    $pagination = $paginated['pagination'];
    return get_results(
        $pagination['total_posts'],
        $data,
        $paginated['items'],
        $pagination['current'],
        $pagination['per_page'],
        $pagination['total_pages'],
        $pagination['offset']
    );
}

/**
 * Builds the response array for search/tag results, including intro text and pagination.
 *
 * @param int        $total_posts  Total number of matching posts.
 * @param array      $data         Base data array to enrich.
 * @param array      $posts        Posts for the current page.
 * @param mixed      $page         Current page number.
 * @param mixed      $perPage      Items per page.
 * @param int        $total_pages  Total number of pages.
 * @param float|int  $offset       Row offset for the current page.
 * @return array
 */
function get_results(int $total_posts, array $data, array $posts, mixed $page, mixed $perPage, int $total_pages, float|int $offset): array
{
    if ($total_posts > 0) {
        $result = ngettext("result", "results", $total_posts);
        $data['intro'] = $total_posts . " $result found.";
    } else {
        $data['intro'] = "No results found.";
    }

    $data['posts'] = $posts;

    // Pagination metadata (same shape as paginate_posts)
    $data['pagination'] = [
        'current' => $page,
        'per_page' => $perPage,
        'total_posts' => $total_posts,
        'total_pages' => $total_pages,
        'prev_page' => $page > 1 ? $page - 1 : null,
        'next_page' => $page < $total_pages ? $page + 1 : null,
        'offset' => $offset,
    ];

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
    $tag = htmlspecialchars($tag);

    // Get all posts for this tag (in-memory array)
    $all_posts = posts_by_tag($tag);

    if (empty($all_posts)) {
        return respond_404();
    }

    $paginated = paginate_posts($all_posts);

    $data['title'] = 'Tagged with #' . $tag;
    $data['feed_url'] = ROOT_URL . '/tag/' . rawurlencode($tag) . '/feed';
    $pagination = $paginated['pagination'];
    return get_results(
        $pagination['total_posts'],
        $data,
        $paginated['items'],
        $pagination['current'],
        $pagination['per_page'],
        $pagination['total_pages'],
        $pagination['offset']
    );
}

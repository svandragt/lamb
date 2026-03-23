<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use Exception;
use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Lamb\Config;
use Lamb\Security;
use Random\RandomException;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use RuntimeException;

use function Lamb\delete_redirect_for_slug;
use function Lamb\find_redirect;
use function Lamb\parse_bean;
use function Lamb\Post\populate_bean;
use function Lamb\Post\posts_by_tag;
use function Lamb\Route\is_reserved_route;
use function Lamb\Theme\part;

use const ROOT_DIR;

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
 *
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
 * @param bool $use_fallback (optional) Whether to use the fallback URL when the 404 page is not available. Default is false.
 *
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
 * Redirects the user after successfully creating a post.
 *
 * This method performs several operations, including checking for user authentication,
 * verifying the presence of a CSRF token, validating the submitted form data,
 * saving the post to the database, and redirecting the user to the home page.
 * If any of these operations fail, the method returns null and no redirection occurs.
 *
 * @return array|null An array containing post data if the redirection was successful,
 *                   otherwise null.
 */
function redirect_created(): ?array
{
    global $config;
    Security\require_login();
    Security\require_csrf();
    if ($_POST['submit'] !== SUBMIT_CREATE) {
        return null;
    }
    $contents = trim($_POST['contents'] ?? '');
    if (empty($contents)) {
        return null;
    }

    $bean = populate_bean($contents);
    if ($bean === null) {
        $_SESSION['flash'][] = 'Failed to create status: Invalid content.';
        return null;
    }

    try {
        $id = R::store($bean);
        if (is_reserved_route($bean->slug)) {
            $bean->slug .= "-" . $id;
            R::store($bean);
        }
        // Remove any existing redirect for this slug — the new post takes priority
        if (!empty($bean->slug)) {
            delete_redirect_for_slug($bean->slug);
            $redirections = $config['redirections'] ?? [];
            if (isset($redirections[$bean->slug])) {
                $_SESSION['flash'][] = 'A manual redirect for <code>' . $bean->slug
                    . '</code> still exists in Settings → [redirections]. You may want to remove it.';
            }
        }
    } catch (SQL $e) {
        $_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
    }
    redirect_uri('/');
}

/**
 * Redirects the user to the homepage if the request is not a POST request.
 * If the request is a POST request, then the user is required to be logged in and have a valid CSRF token.
 * If the post with the provided ID exists, it is deleted using RedBean ORM.
 * Finally, the user is redirected to the homepage.
 *
 * @param mixed $args The arguments for the method (expects an array with the first element as the post ID).
 *
 * @return void
 */
#[NoReturn]
function redirect_deleted(mixed $args): void
{
    if (empty($_POST)) {
        redirect_uri('/');
    }
    Security\require_login();
    Security\require_csrf();

    [$id] = $args;
    $post = R::load('post', (int)$id);
    if ($post !== null) {
        R::trash($post);
    }
    redirect_uri('/');
}

/**
 * Redirects the user after editing a post.
 *
 * This method performs several checks and updates the post's content and metadata.
 * If any of the checks fail, the method returns null and no redirection is performed.
 *
 * @return void
 */
function redirect_edited(): void
{
    global $config;

    Security\require_login();
    Security\require_csrf();
    if ($_POST['submit'] !== SUBMIT_EDIT) {
        return;
    }

    $contents = trim(($_POST['contents']));
    $id = trim(filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT));
    if (empty($contents) || empty($id)) {
        return;
    }

    $bean = R::load('post', (int)$id);
    $old_slug = $bean->slug;

    $bean->body = $contents;

    parse_bean($bean);
    $bean->version = 1;
    $bean->updated = date("Y-m-d H:i:s");

    if (is_reserved_route($bean->slug)) {
        $_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $bean->slug . '</code>';

        return;
    }

    try {
        R::store($bean);
    } catch (SQL $e) {
        $_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();
    }

    $new_slug = $bean->slug;
    if (!empty($old_slug) && $old_slug !== $new_slug) {
        // Remove any redirect pointing to the new slug to avoid redirect loops
        delete_redirect_for_slug($new_slug);
        // Store a redirect from the old slug to the new one
        $auto_redirect = R::dispense('redirect');
        $auto_redirect->from_slug = $old_slug;
        $auto_redirect->to_url = '/' . $new_slug;
        R::store($auto_redirect);
    }

    if (!empty($new_slug)) {
        $redirections = $config['redirections'] ?? [];
        if (isset($redirections[$new_slug])) {
            $_SESSION['flash'][] = 'A manual redirect for <code>' . $new_slug
                . '</code> still exists in Settings → [redirections]. You may want to remove it.';
        }
    }

    $redirect = $_SESSION['edit-referrer'];
    unset($_SESSION['edit-referrer']);
    redirect_uri($redirect);
}

/**
 * Redirects the user to a specified URL.
 *
 * @param string $where The URL to redirect to. If empty, redirects to the root URL.
 *
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

    // Use the shared paginator for posts; paginate_posts will read config and $_GET when needed
    $where_parts = [' (draft IS NULL OR draft != 1) '];
    $where_params = [];
    $clause = build_exclude_slugs_clause(Config\get_menu_slugs());
    if ($clause !== null) {
        $where_parts[] = $clause['sql'];
        $where_params = $clause['params'];
    }
    $paginated = paginate_posts(
        'post',
        'created DESC',
        implode(' AND ', $where_parts),
        $where_params
    );
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    upgrade_posts($data['posts']);

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
    $paginated = paginate_posts('post', 'created DESC', ' draft = 1 ');
    $data['posts'] = $paginated['items'];
    $data['pagination'] = $paginated['pagination'];

    upgrade_posts($data['posts']);

    return $data;
}

/**
 * Redirects the user to the login page if not already logged in.
 *
 * If the user is already logged in, their session is regenerated and they are redirected to the root URL.
 * If the login form has not been submitted or the submitted value is not SUBMIT_LOGIN, it returns an empty array to show the login page.
 * If the submitted password is incorrect, it sets a flash message and redirects to the root URL.
 * If the login is successful, it sets the SESSION_LOGIN session variable to true, regenerates the session ID, and redirects to the specified URL.
 *
 * @return array|null
 * @throws RandomException
 */
function redirect_login(): ?array
{
    // Prevent caching for this page
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if (isset($_SESSION[SESSION_LOGIN])) {
        // Already logged in
        session_regenerate_id(true);
        redirect_uri('/');
    }
    if (!isset($_POST['submit']) || $_POST['submit'] !== SUBMIT_LOGIN) {
        // Show login page by returning a non-empty array.
        return [];
    }
    Security\require_csrf();

    $user_pass = $_POST['password'];
    if (!password_verify($user_pass, base64_decode(LOGIN_PASSWORD))) {
        $_SESSION['flash'][] = 'Password is incorrect, please try again.';
        redirect_uri('/');
    }

    $_SESSION[SESSION_LOGIN] = true;
    session_regenerate_id(true);

    $uuid = bin2hex(random_bytes(16)); // Generate a UUID
    setcookie('lamb_logged_in', $uuid, get_cookie_options(time() + 3600));
    $where = filter_input(INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL);
    redirect_uri($where);
}

/**
 * Logs out the user by unsetting the session login information, regenerating the session ID, and redirecting to the home page.
 *
 * @return void
 */
#[NoReturn]
function redirect_logout(): void
{
    unset($_SESSION[SESSION_LOGIN]);

    setcookie('lamb_logged_in', '', get_cookie_options(time() - 3600));

    session_regenerate_id(true);
    redirect_uri('/');
}

/**
 * Redirects the user to a search page with the provided query.
 *
 * @param string $query The search query to be included in the redirected URL.
 *
 * @return void
 */
#[NoReturn]
function redirect_search(string $query): void
{
    header("Location: /search/$query");
    die("Redirecting to /search/$query");
}

# Single
/**
 * Handles the settings page logic, including displaying, validating, and saving settings.
 *
 * @return array An array containing the page title and the current or updated INI configuration text.
 * @throws Exception
 */
function respond_settings(): array
{
    Security\require_login();

    $data = [
        'title' => 'Settings',
        'ini_text' => Config\get_ini_text(),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security\require_csrf();

        if (isset($_POST['action']) && $_POST['action'] === 'reset') {
            $default_ini = Config\get_default_ini_text();
            Config\save_ini_text($default_ini);
            $_SESSION['flash'][] = "Settings reset to defaults.";
            redirect_uri('/settings');
        }

        $submitted_ini = $_POST['ini_text'] ?? '';
        $validation = Config\validate_ini($submitted_ini);

        if ($validation['valid']) {
            Config\save_ini_text($submitted_ini);
            $_SESSION['flash'][] = "Settings saved successfully.";
            redirect_uri('/settings');
        } else {
            $_SESSION['flash'][] = "Invalid INI syntax. Your changes were not saved.";
            if ($validation['error']) {
                $_SESSION['flash'][] = $validation['error'];
            }
            $data['ini_text'] = $submitted_ini; // Preserve typed content
        }
    }

    return $data;
}

/**
 * Responds with the status of a post.
 *
 * @param array $args An array containing the post ID.
 *
 * @return array The transformed data representing the post's status.
 */
function respond_status(array $args): array
{
    [$id] = $args;
    $bean = R::load('post', (int)$id);
    if (!$bean->id) {
        respond_404([], true);
    }

    $posts = [$bean];
    $data['posts'] = $posts;

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}

/**
 * Responds to the edit request with the provided arguments.
 *
 * @param array $args The arguments passed to the method.
 *                    The first argument should be the ID of the post to edit.
 *
 * @return array The response data.
 *               - The 'post' key contains the loaded post object from the database.
 */
function respond_edit(array $args): array
{
    if (!empty($_POST)) {
        redirect_edited();
    }
    Security\require_login();

    [$id] = $args;

    $_SESSION['edit-referrer'] = $_SERVER['HTTP_REFERER'] ?? null;

    return ['post' => R::load('post', (int)$id)];
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

# Atom feed
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
            $clause['sql'] . ' AND (draft IS NULL OR draft != 1) ORDER BY updated DESC LIMIT 20',
            $clause['params']
        );
    } else {
        $posts = R::findAll('post', ' (draft IS NULL OR draft != 1) ORDER BY updated DESC LIMIT 20 ');
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
 * Responds to a feed request by fetching and rendering the necessary data.
 *
 * This method fetches the feed data by excluding pages with slugs and ordering the posts by the most recent updates.
 * It limits the number of posts returned to 20.
 * After fetching the data, it merges it with the existing data array and renders the feed view.
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
 *
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
 * Responds to a POST request by retrieving and transforming a single post.
 *
 * @param array $args The arguments for the POST request.
 *                    - string $slug The slug of the post to retrieve.
 *
 * @return array The transformed post.
 */
function respond_post(array $args): array
{
    [$slug] = $args;
    $post = R::findOne('post', ' slug = ? ', [$slug]);
    if ($post === null || $post->draft == 1) {
        return respond_404([]);
    }
    $data['posts'] = [$post];

    upgrade_posts($data['posts']);

    $data['title'] = $data['posts'][0]->title;

    return $data;
}

/**
 * Upgrades the given posts by transforming the beans and storing them in the database if not already transformed.
 *
 * @param array &$posts The array of posts to be upgraded, passed by reference.
 *
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

# Search result (non-FTS)
/**
 * Responds to a search query with an array of search results.
 *
 * @param array $args The arguments for the search. The first element of the array should be the search query.
 *
 * @return array The search results as an associative array. The array may contain the following keys:
 *               - 'title': The title of the search results page.
 *               - 'intro': A short introduction message about the search results.
 *               - 'items': An array of search result items. Each item should be an associative array with the following keys:
 *                          - 'id': The ID of the search result item.
 *                          - 'title': The title of the search result item.
 *                          - 'content': The content of the search result item.
 *
 *               If no search results are found, an empty array will be returned.
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
    $where_sql = '(' . implode(' AND ', $where_clauses) . ') AND (draft IS NULL OR draft != 1)';

    // Use the shared paginator which supports WHERE + params; omit per_page/page so helper reads config/$_GET
    $paginated = paginate_posts('post', 'created DESC', $where_sql, $params);

    // Results
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
 * Processes the provided data, posts, and pagination details, returning a structured array with results and metadata.
 *
 * @param int $total_posts The total number of posts available.
 * @param array $data An array of additional data to be transformed or enriched.
 * @param array $posts An array of posts to include in the output.
 * @param mixed $page The current page number.
 * @param mixed $perPage The number of posts per page.
 * @param int $total_pages The total number of pages.
 * @param float|int $offset The starting index for the posts on the current page.
 *
 * @return array The structured data array including posts, pagination metadata, and additional information.
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

# Tag pages
/**
 * Retrieves posts that are tagged with the provided tag and returns the transformed data.
 *
 * @param array $args The arguments array containing the tag.
 *
 * @return array The transformed data containing the tagged posts.
 */
function respond_tag(array $args): array
{
    [$tag] = $args;
    $tag = urldecode($tag);
    $tag = htmlspecialchars($tag);

    // Get all posts for this tag (in-memory array)
    $all_posts = posts_by_tag($tag);

    // Use the shared paginator which accepts an array source; omit per_page/page so helper reads config/$_GET
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

/**
 * Responds to an upload request by processing the uploaded files.
 *
 * @param array $_args The arguments for the upload request.
 *
 * @return void
 * @throws JsonException
 */
#[NoReturn]
function respond_upload(array $_args): void
{
    if (empty($_FILES[IMAGE_FILES])) {
        // invalid request http status code
        header('HTTP/1.1 400 Bad Request');
        die('No files uploaded!');
    }
    Security\require_login();

    $files = [];
    foreach ($_FILES[IMAGE_FILES] as $name => $items) {
        foreach ($items as $k => $value) {
            $files[$k][$name] = $_FILES[IMAGE_FILES][$name][$k];
        }
    }

    $out = '';
    foreach ($files as $f) {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            // File upload failed
            echo json_encode('File upload error: ' . $f['error'], JSON_THROW_ON_ERROR);
            die();
        }
        // File upload successful
        $temp_fp = $f['tmp_name'];
        $ext = pathinfo($f['name'])['extension'];
        $new_fn = sha1($f['name']) . ".$ext";
        $new_fp = sprintf("%s/%s", get_upload_dir(), $new_fn);
        if (!move_uploaded_file($temp_fp, $new_fp)) {
            echo json_encode('Move upload error: ' . $temp_fp, JSON_THROW_ON_ERROR);
            die();
        }
        $upload_url = str_replace(ROOT_DIR, ROOT_URL, get_upload_dir());
        $out .= sprintf("![%s](%s)", $f['name'], "$upload_url/$new_fn");
    }

    echo json_encode($out, JSON_THROW_ON_ERROR);
    die();
}

/**
 * Retrieves the upload directory for storing files.
 *
 * The upload directory is generated based on the current year/month of the server's date,
 * and is created if it does not exist.
 *
 * @return string The path of the upload directory.
 *
 * @throws RuntimeException If the upload directory cannot be created.
 */
function get_upload_dir(): string
{
    // get an upload directory in the current directory based on YYYY/MM/filename.ext
    $upload_dir = sprintf("%s/assets/%s", ROOT_DIR, date("Y/m"));
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $upload_dir));
        }
    }

    return $upload_dir;
}

/**
 * Paginates a collection of posts, either from an array or a database query.
 *
 * @param mixed $source The source to paginate, either an array of items or a string representing a database bean type.
 * @param string $order_by_clause The SQL order by clause to apply when querying the database. Defaults to 'created DESC'.
 * @param string|null $where_sql Optional SQL WHERE clause for filtering database results (null by default).
 * @param array $params Parameters to bind for the SQL WHERE clause, if provided.
 *
 * @return array An array containing paginated items and pagination details such as current page, total posts, total pages, and offsets.
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

    // If source is an array, do array pagination
    if (is_array($source)) {
        $values = array_values($source);
        $total_posts = count($values);
        $total_pages = $total_posts > 0 ? (int)ceil($total_posts / $per_page) : 1;
        $page = min($page, $total_pages);
        $offset = ($page - 1) * $per_page;

        return [
            'items'      => array_slice($values, $offset, $per_page),
            'pagination' => build_pagination_meta($page, $per_page, $total_posts, $offset),
        ];
    }

    // Otherwise expect a bean type string and run DB pagination.
    $bean_type = (string)$source;

    // Count posts (with or without WHERE/params)
    if (!empty($where_sql)) {
        $total_posts = R::count($bean_type, $where_sql, $params);
    } else {
        $total_posts = R::count($bean_type);
    }

    $total_pages = $total_posts > 0 ? (int)ceil($total_posts / $per_page) : 1;
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    if (!empty($where_sql)) {
        // When params are provided, use R::find with param binding and append offset/limit
        $find_params = $params;
        $find_params[] = (int)$offset;
        $find_params[] = (int)$per_page;
        $sql = $where_sql . ' ORDER BY ' . $order_by_clause . ' LIMIT ?, ?';
        $items = R::find($bean_type, $sql, $find_params);
    } else {
        // No params: safe to use the simpler findAll with a constructed LIMIT
        $limit_sql = 'ORDER BY ' . $order_by_clause . ' LIMIT ' . (int)$offset . ', ' . $per_page;
        $items = R::findAll($bean_type, $limit_sql);
    }

    return [
        'items'      => $items,
        'pagination' => build_pagination_meta($page, $per_page, $total_posts, $offset),
    ];
}

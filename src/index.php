<?php

namespace Lamb;

global $template, $action;


define('ROOT_DIR', __DIR__);

require '../vendor/autoload.php';

$data_dir = getenv('LAMB_DATA_DIR') ?: '../data';
Bootstrap\bootstrap_db($data_dir);
Bootstrap\bootstrap_session($data_dir);

$config = Config\load();
Config\apply_timezone($config);

define('ROOT_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"]);
// Config\ensure_explicit_theme() guarantees a renderable theme value on read,
// so no runtime fallback/alias is needed here (see #291).
define("THEME", (string) $config['theme']);
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');
define("THEME_URL", 'themes/' . THEME . '/');

# Bootstrap
foreach (Bootstrap\cache_headers(isset($_SESSION[SESSION_LOGIN])) as $cache_header) {
    header($cache_header);
}
header('Link: <' . ROOT_URL . '/micropub>; rel="micropub"', false);
header('Link: <' . ROOT_URL . '/webmention>; rel="webmention"', false);
header('Link: <' . $config['authorization_endpoint'] . '>; rel="authorization_endpoint"', false);
header('Link: <' . $config['token_endpoint'] . '>; rel="token_endpoint"', false);

# Routing
$request_uri = Http\get_request_uri();

# Strip a trailing /page/N pagination segment so list routes keep routing on
# their base path; the page number flows through the normal $_GET['page'] path.
[$request_uri, $page_from_path] = Http\extract_page_segment((string)$request_uri);
if ($page_from_path !== null) {
    $_GET['page'] = $page_from_path;
}

# Legacy ?page=N links → permanent redirect to the clean /…/page/N URL.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['GET', 'HEAD'], true)) {
    parse_str($_SERVER['QUERY_STRING'] ?? '', $query_params);
    if (isset($query_params['page'])) {
        $page_num = max(1, (int)$query_params['page']);
        unset($query_params['page']);
        $clean_path = (string)strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        $target = Http\page_path($clean_path, $page_num);
        $remaining = http_build_query($query_params);
        if ($remaining !== '') {
            $target .= '?' . $remaining;
        }
        header('Location: ' . Http\sanitize_location($target), true, 301);
        exit;
    }
}

$action = strtok($request_uri, '/');
$lookup = strtok('/');
$sublookup = strtok('/');

$request_uri_with_query = $_SERVER['REQUEST_URI'] ?? '';

$redirect_path = trim((string) $request_uri, '/');
if (str_contains($redirect_path, '/')) {
    $redirect_url = find_redirect($redirect_path);
    if ($redirect_url !== null) {
        header('Location: ' . Http\sanitize_location($redirect_url), true, 301);
        exit;
    }
}

Route\register_app_routes($action, $lookup, $sublookup);
$template = $action;
if (post_has_slug($action) === $action) {
    Route\register_route($action, __NAMESPACE__ . '\\Response\respond_post', $action);
    $template = 'status';
} elseif ($action !== false && !Route\is_reserved_route($action)) {
    $redirect_url = find_redirect($action);
    if ($redirect_url !== null) {
        header('Location: ' . Http\sanitize_location($redirect_url), true, 301);
        exit;
    }
}
$data = Route\call_route($action);
$action = $data['action'] ?? $action;

switch ($action) {
    case false:
    case '404':
        $action = '404';
        $template = '404';
        break;
}

# Views
if (isset($_SESSION[SESSION_LOGIN])) {
    ob_start();
    Theme\part('html', '');
    $page = ob_get_clean();
    echo preg_replace(
        '/<body([^>]*)>/',
        '<body$1>' . Theme\admin_toolbar_html(),
        $page,
        1
    );
} else {
    // Conditional GET for cacheable content pages. Excludes the always-fresh
    // login page and the (intentionally cacheable but non-200) 404 response,
    // which must not answer with 304.
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD'], true) && !in_array($action, ['login', '404'], true)) {
        Response\send_304_if_current(
            Response\latest_content_timestamp(),
            \Lamb\Config\config_modified_timestamp()
        );
    }
    Theme\part('html', '');
}

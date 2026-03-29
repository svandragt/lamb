<?php

namespace Lamb;

global $template, $action;


define('ROOT_DIR', __DIR__);

require '../vendor/autoload.php';

Bootstrap\bootstrap_db(getenv('LAMB_DATA_DIR') ?: '../data');
Bootstrap\bootstrap_session();

$config = Config\load();

define('ROOT_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"]);
define("THEME", $config['theme'] ?? 'default');
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');
define("THEME_URL", 'themes/' . THEME . '/');

# Bootstrap
header('Cache-Control: max-age=300');
header('Link: <' . ROOT_URL . '/micropub>; rel="micropub"', false);
header('Link: <' . $config['authorization_endpoint'] . '>; rel="authorization_endpoint"', false);
header('Link: <' . $config['token_endpoint'] . '>; rel="token_endpoint"', false);

# Routing
$request_uri = Http\get_request_uri();
$action = strtok($request_uri, '/');
$lookup = strtok('/');
$sublookup = strtok('/');

$request_uri_with_query = $_SERVER['REQUEST_URI'] ?? '';

Route\register_route(false, __NAMESPACE__ . '\\Response\respond_404');
Route\register_route('404', __NAMESPACE__ . '\\Response\respond_404');
Route\register_route('delete', __NAMESPACE__ . '\\Response\redirect_deleted', $lookup);
Route\register_route('restore', __NAMESPACE__ . '\\Response\redirect_restored', $lookup);
Route\register_route('drafts', __NAMESPACE__ . '\\Response\respond_drafts');
Route\register_route('trash', __NAMESPACE__ . '\\Response\respond_trash');
Route\register_route('edit', __NAMESPACE__ . '\\Response\respond_edit', $lookup);
Route\register_route('feed', __NAMESPACE__ . '\\Response\respond_feed');
if ($action === 'home' && $lookup === 'feed') {
    Route\register_route('home', __NAMESPACE__ . '\\Response\respond_feed');
} else {
    Route\register_route('home', __NAMESPACE__ . '\\Response\respond_home');
}
Route\register_route('login', __NAMESPACE__ . '\\Response\redirect_login');
Route\register_route('logout', __NAMESPACE__ . '\\Response\redirect_logout');
Route\register_route('search', __NAMESPACE__ . '\\Response\respond_search', $lookup);
Route\register_route('settings', __NAMESPACE__ . '\\Response\respond_settings');
Route\register_route('status', __NAMESPACE__ . '\\Response\respond_status', $lookup);
if ($action === 'tag' && $sublookup === 'feed') {
    Route\register_route('tag', __NAMESPACE__ . '\\Response\respond_tag_feed', $lookup);
} else {
    Route\register_route('tag', __NAMESPACE__ . '\\Response\respond_tag', $lookup);
}
Route\register_route('micropub', __NAMESPACE__ . '\\Micropub\respond_micropub');
Route\register_route('micropub-media', __NAMESPACE__ . '\\Micropub\respond_micropub_media');
Route\register_route('upload', __NAMESPACE__ . '\\Response\respond_upload', $lookup);
$template = $action;
if (post_has_slug($action) === $action) {
    Route\register_route($action, __NAMESPACE__ . '\\Response\respond_post', $action);
    $template = 'status';
} elseif ($action !== false && !Route\is_reserved_route($action)) {
    $redirect_url = find_redirect($action);
    if ($redirect_url !== null) {
        header('Location: ' . $redirect_url, true, 301);
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
    Theme\part('html', '');
}

<?php

namespace Lamb;

global $template, $action;


define('ROOT_DIR', __DIR__);

require '../vendor/autoload.php';

# Bootstrap
header('Cache-Control: max-age=300');

Bootstrap\bootstrap_db('../data');
Bootstrap\bootstrap_session();

$config = Config\load();

define('HIDDEN_CSRF_NAME', 'csrf');
define('ROOT_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"]);
define('SESSION_LOGIN', 'logged_in');
define('SUBMIT_CREATE', 'Create post');
define('SUBMIT_EDIT', 'Update post');
define('SUBMIT_LOGIN', 'Log in');
define('SUBMIT_SETTINGS', 'Save settings');
define("THEME", $config['theme'] ?? 'default');
define("THEME_DIR", ROOT_DIR . '/themes/' . THEME . '/');
define("THEME_URL", 'themes/' . THEME . '/');

# Routing
$action = strtok(Http\get_request_uri(), '/');
$lookup = strtok('/');

Route\register_route(false, __NAMESPACE__ . '\\Response\respond_404');
Route\register_route('404', __NAMESPACE__ . '\\Response\respond_404');
Route\register_route('delete', __NAMESPACE__ . '\\Response\redirect_deleted', $lookup);
Route\register_route('edit', __NAMESPACE__ . '\\Response\respond_edit', $lookup);
Route\register_route('feed', __NAMESPACE__ . '\\Response\respond_feed');
Route\register_route('home', __NAMESPACE__ . '\\Response\respond_home');
Route\register_route('login', __NAMESPACE__ . '\\Response\redirect_login');
Route\register_route('logout', __NAMESPACE__ . '\\Response\redirect_logout');
Route\register_route('search', __NAMESPACE__ . '\\Response\respond_search', $lookup);
Route\register_route('settings', __NAMESPACE__ . '\\Response\respond_settings');
Route\register_route('status', __NAMESPACE__ . '\\Response\respond_status', $lookup);
Route\register_route('tag', __NAMESPACE__ . '\\Response\respond_tag', $lookup);
Route\register_route('upload', __NAMESPACE__ . '\\Response\respond_upload', $lookup);
$template = $action;
if (post_has_slug($action) === $action) {
    Route\register_route($action, __NAMESPACE__ . '\\Response\respond_post', $action);
    $template = 'status';
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
Theme\part('html', '');

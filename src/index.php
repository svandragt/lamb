<?php

namespace Lamb;

use RuntimeException;
use RedBeanPHP\R;

require '../vendor/autoload.php';

$root_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ) . "://" . $_SERVER["HTTP_HOST"];
define( 'HIDDEN_CSRF_NAME', 'csrf' );
define( 'LOGIN_PASSWORD', getenv( "LAMB_LOGIN_PASSWORD" ) );
define( 'ROOT_DIR', __DIR__ );
define( 'ROOT_URL', $root_url );
define( 'SESSION_LOGIN', 'logged_in' );
define( 'SUBMIT_CREATE', 'Create post' );
define( 'SUBMIT_EDIT', 'Update post' );
define( 'SUBMIT_LOGIN', 'Log in' );
unset( $root_url );

# Bootstrap
header( 'Cache-Control: max-age=300' );

$data_dir = '../data';
if ( ! is_dir( $data_dir ) ) {
	if ( ! mkdir( $data_dir, 0777, true ) && ! is_dir( $data_dir ) ) {
		throw new RuntimeException( sprintf( 'Directory "%s" was not created', $data_dir ) );
	}
}
R::setup( 'sqlite:../data/lamb.db' );

// Make cookies inaccessible via JavaScript (XSS).
ini_set( "session.cookie_httponly", 1 );
// Prevent the browser from sending cookies along with cross-site requests (CSRF)
session_set_cookie_params( [ 'samesite' => 'Strict' ] ); // or 'Lax'
session_start();

// Validate user agents
if ( isset( $_SESSION['HTTP_USER_AGENT'] ) ) {
	if ( $_SESSION['HTTP_USER_AGENT'] !== md5( $_SERVER['HTTP_USER_AGENT'] ) ) {
		/* Possible session hijacking attempt */
		exit( "Security fail" );
	}
} else {
	$_SESSION['HTTP_USER_AGENT'] = md5( $_SERVER['HTTP_USER_AGENT'] );
}

$config = Config\load();
define( "THEME", $config['theme'] ?? 'default' );
define( "THEME_DIR", __DIR__ . '/themes/' . THEME );

# Routing
$request_uri = Http\get_request_uri();
$action = strtok( $request_uri, '/' );
$lookup = strtok( '/' );

if ( $action === 'favicon.ico' ) {
	Response\respond_404();

	return;
}

Route\register_route( false, __NAMESPACE__ . '\\Response\respond_404' );
Route\register_route( '404', __NAMESPACE__ . '\\Response\respond_404' );
Route\register_route( 'delete', __NAMESPACE__ . '\\Response\redirect_deleted', $lookup );
Route\register_route( 'edit', __NAMESPACE__ . '\\Response\respond_edit', $lookup );
Route\register_route( 'feed', __NAMESPACE__ . '\\Response\respond_feed' );
Route\register_route( 'home', __NAMESPACE__ . '\\Response\respond_home' );
Route\register_route( 'login', __NAMESPACE__ . '\\Response\redirect_login' );
Route\register_route( 'logout', __NAMESPACE__ . '\\Response\redirect_logout' );
Route\register_route( 'search', __NAMESPACE__ . '\\Response\respond_search', $lookup );
Route\register_route( 'status', __NAMESPACE__ . '\\Response\respond_status', $lookup );
Route\register_route( 'tag', __NAMESPACE__ . '\\Response\respond_tag', $lookup );
Route\register_route( 'upload', __NAMESPACE__ . '\\Response\respond_upload', $lookup );

$template = $action;
if ( post_has_slug( $action ) === $action ) {
	Route\register_route( $action, __NAMESPACE__ . '\\Response\respond_post', $action );
	$template = 'status';
}
$data = Route\call_route( $action );
$action = $data['action'] ?? $action;

switch ( $action ) {
	case false:
	case '404':
		$action = '404';
		$template = '404';
		break;
}

# Views
require_once( "themes/default/html.php" );

<?php

namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use http\Exception\RuntimeException;
use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

$root_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ) . "://" . $_SERVER["HTTP_HOST"];
define( 'HIDDEN_CSRF_NAME', 'csrf' );
define( 'LOGIN_PASSWORD', getenv( "LAMB_LOGIN_PASSWORD" ) );
define( 'ROOT_DIR', __DIR__ );
define( 'ROOT_URL', $root_url );
define( 'SESSION_LOGIN', 'logged_in' );
define( 'SUBMIT_CREATE', 'Bleat!' );
define( 'SUBMIT_EDIT', 'Save' );
define( 'SUBMIT_LOGIN', 'Log in' );
unset( $root_url );

function permalink( $item ) : string {
	if ( $item['slug'] ) {
		return ROOT_URL . "/{$item['slug']}";
	}

	return ROOT_URL . '/status/' . $item['id'];
}

# Security
function require_login() : void {
	if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		$redirect_to = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		$_SESSION['flash'][] = "Please login. You will be redirected to $redirect_to";
		redirect_uri( "/login?redirect_to=$redirect_to" );
	}
}

function require_csrf() : void {
	$token = htmlspecialchars( $_POST[ HIDDEN_CSRF_NAME ] );
	if ( ! $token || $token !== $_SESSION[ HIDDEN_CSRF_NAME ] ) {
		$txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
		header( $txt );
		die( $txt );
	}
	unset( $_SESSION[ HIDDEN_CSRF_NAME ] );
}

/**
 * @param string $text
 *
 * @return array
 */
function parse_matter( string $text ) : array {
	$matter = \yaml_parse( $text );
	if ( ! is_array( $matter ) ) {
		$matter = parse_ini_matter( $text );
		if ( ! $matter ) {
			return [];
		}
	}

	if ( isset( $matter['title'] ) ) {
		$matter['slug'] = strtolower( preg_replace( '/\W+/m', "-", $matter['title'] ) );
	}

	return $matter;
}

function is_menu_item( string $slug ) : bool {
	global $config;

	return isset( $config['menu_items'][ $slug ] );
}

function parse_ini_matter( string $text ) : array|false {
	trigger_error( "Deprecated function called - " . __FUNCTION__, E_USER_DEPRECATED );
	$parts = explode( '---', $text );
	if ( count( $parts ) < 3 ) {
		return [];
	}
	$ini_text = trim( $parts[1] );

	return parse_ini_string( $ini_text );
}

# Transformation
function transform( $bleats ) : array {
	if ( empty( $bleats ) ) {
		return [];
	}
	function render( $bleat ) : array {
		$parts = explode( '---', $bleat );
		$front_matter = parse_matter( $bleat );

		$md_text = trim( $parts[ count( $parts ) - 1 ] );
		$parser = new LambDown();
		$parser->setSafeMode( true );
		$markdown = $parser->text( $md_text );

		$front_matter['description'] = strtok( strip_tags( $markdown ), "\n" );

		if ( isset( $front_matter['title'] ) ) {
			# Only posts have titles
			$markdown = $parser->text( "## {$front_matter['title']}" ) . PHP_EOL . $markdown;
		}

		return array_merge( $front_matter, [ 'body' => $markdown ] );
	}

	$data = [];

	foreach ( $bleats as $b ) {
		if ( $b->id === 0 ) {
			continue;
		}
		$data['items'][] = array_merge( render( $b->body ), [
			'created' => $b->created,
			'id' => $b->id,
			'slug' => $b->slug,
			'updated' => $b->updated,
		] );
	}

	return $data;
}

# Router handling
#[NoReturn] function redirect_404( $fallback ) : void {
	global $request_uri;
	header( "Location: $fallback$request_uri" );
	die( "Redirecting to $fallback$request_uri" );
}

function redirect_created() {
	require_login();
	require_csrf();
	if ( $_POST['submit'] !== SUBMIT_CREATE ) {
		return null;
	}
	$contents = trim( filter_input( INPUT_POST, 'contents', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES ) );
	if ( empty( $contents ) ) {
		return null;
	}

	$matter = parse_matter( $contents );
	$bleat = R::dispense( 'bleat' );
	$bleat->body = $contents;
	$bleat->slug = $matter['slug'] ?? '';
	$bleat->created = date( "Y-m-d H:i:s" );
	$bleat->updated = date( "Y-m-d H:i:s" );
	try {
		R::store( $bleat );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to save status: ' . $e->getMessage();
	}
	redirect_uri( '/' );
}

#[NoReturn] function redirect_deleted( $id ) : void {
	if ( empty( $_POST ) ) {
		redirect_uri( '/' );
	}
	require_login();
	require_csrf();

	$bleat = R::load( 'bleat', (integer) $id );
	if ( $bleat !== null ) {
		R::trash( $bleat );
	}
	redirect_uri( '/' );
}

function redirect_edited() {
	require_login();
	require_csrf();
	if ( $_POST['submit'] !== SUBMIT_EDIT ) {
		return null;
	}

	$contents = trim( filter_input( INPUT_POST, 'contents', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES ) );
	$id = trim( filter_input( INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT ) );
	if ( empty( $contents ) || empty( $id ) ) {
		return null;
	}

	$matter = parse_matter( $contents );
	$bleat = R::load( 'bleat', (integer) $id );
	$bleat->body = $contents;
	if ( empty( $bleat->slug ) ) {
		# Good URLS don't change!
		$bleat->slug = $matter['slug'] ?? null;
	}
	$bleat->updated = date( "Y-m-d H:i:s" );
	try {
		R::store( $bleat );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();
	}
	redirect_uri( '/' );
}

#[NoReturn] function redirect_uri( $where ) : void {
	if ( empty( $where ) ) {
		$where = '/';
	}
	header( "Location: $where" );
	die( "Redirecting to $where" );
}

function redirect_login() {
	if ( isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		// Already logged in
		redirect_uri( '/' );
	}
	if ( ! isset( $_POST['submit'] ) || $_POST['submit'] !== SUBMIT_LOGIN ) {
		// Show login page?
		return null;
	}
	require_csrf();

	$user_pass = $_POST['password'];
	if ( ! password_verify( $user_pass, base64_decode( LOGIN_PASSWORD ) ) ) {
		$_SESSION['flash'][] = 'Password is incorrect, please try again.';
		redirect_uri( '/' );
	}

	$_SESSION[ SESSION_LOGIN ] = true;
	$where = filter_input( INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL );
	redirect_uri( $where );
}

#[NoReturn] function redirect_logout() : void {
	unset( $_SESSION[ SESSION_LOGIN ] );
	redirect_uri( '/' );
}

#[NoReturn] function redirect_search( $query ) : void {
	header( "Location: /search/$query" );
	die( "Redirecting to /search/$query" );
}

function respond_404( $use_fallback = false ) : array {
	global $config;
	if ( $use_fallback ) {
		if ( isset( $config['404_fallback'] ) ) {
			$fallback = $config['404_fallback'];
			if ( filter_var( $fallback, FILTER_VALIDATE_URL ) ) {
				redirect_404( $fallback );
			}
		}
	}
	$header = "HTTP/1.0 404 Not Found";
	header( $header );

	return [
		'title' => $header,
		'intro' => 'Page not found.',
		'action' => '404',
	];
}

# Single
function respond_status( $id ) : array {
	$bleats = [ R::load( 'bleat', (integer) $id ) ];

	$data = transform( $bleats );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

function respond_edit( $id ) : OODBBean {
	if ( ! empty( $_POST ) ) {
		redirect_edited();
	}
	require_login();

	return R::load( 'bleat', (integer) $id );
}

# Atom feed
function respond_feed() : array {
	global $config;
	$bleats = R::findAll( 'bleat', 'ORDER BY updated DESC LIMIT 20' );
	$data['updated'] = $bleats[0]['updated'];
	$data['title'] = $config['site_title'];

	$data = array_merge( $data, transform( $bleats ) );
	require_once( 'views/feed.php' );
	die();
}

# Index
function respond_home() : array {
	global $config;
	if ( ! empty( $_POST ) ) {
		redirect_created();
	}

	$bleats = R::findAll( 'bleat', 'ORDER BY created DESC' );
	$data['title'] = $config['site_title'];

	$data = array_merge( $data, transform( $bleats ) );
	foreach ( $data['items'] as &$item ) {
		$item['is_menu_item'] = is_menu_item( $item['slug'] ?? $item['id'] );
	}

	return $data;
}

function respond_post( string $slug ) : array {
	$bleats = [ R::findOne( 'bleat', ' slug = ? ', [ $slug ] ) ];

	return transform( $bleats );
}

# Search result (non-FTS)
function respond_search( $query ) : array {
	$query = filter_var( $query, FILTER_SANITIZE_STRING );
	if ( empty( $query ) ) {
		$query = filter_input( INPUT_GET, 's', FILTER_SANITIZE_STRING );
		if ( empty( $query ) ) {
			return [];
		}
		redirect_search( $query );
	}
	$bleats = R::find( 'bleat', 'body LIKE ? or body LIKE ?', [ "% $query%", "$query%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Searched for "' . $query . '"';
	$num_results = count( $bleats );
	if ( $num_results > 0 ) {
		$result = ngettext( "result", "results", $num_results );
		$data['intro'] = count( $bleats ) . " $result found.";
	}

	$data = array_merge( $data, transform( $bleats ) );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

# Tag pages
function respond_tag( $tag ) : array {
	$tag = filter_var( $tag, FILTER_SANITIZE_STRING );
	$bleats = R::find( 'bleat', 'body LIKE ? OR body LIKE ?', [ "% #$tag%", "#$tag%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Tagged with #' . $tag;

	$data = array_merge( $data, transform( $bleats ) );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

# Bootstrap
header( 'Cache-Control: max-age=300' );
header( "Content-Security-Policy: default-src 'self'" );
session_start();
R::setup( 'sqlite:../data/lamb.db' );
$config = [
	'author_email' => 'joe.sheeple@example.com',
	'author_name' => 'Joe Sheeple',
	'site_title' => 'Bleats',
];
$user_config = @parse_ini_file( '../config.ini', true );
if ( $user_config ) {
	$config = array_merge( $config, $user_config );
}

# Router
$request_uri = '/home';
if ( $_SERVER['REQUEST_URI'] !== '/' ) {
	$request_uri = strtok( $_SERVER['REQUEST_URI'], '?' );
}
$action = strtok( $request_uri, '/' );
$lookup = strtok( '/' );

function post_has_slug( string $lookup ) : string|null {
	$bleat = R::findOne( 'bleat', ' slug = ? ', [ $lookup ] );
	if ( $bleat->id === 0 ) {
		return '';
	}

	return $bleat->slug;
}

function register_route( bool|string $action, string $callback, mixed ...$args ) {
	global $routes;
	$routes[ $action ] = [ $callback, $args ];
}

function call_route( bool|string $action ) : array {
	global $routes;
	[ $callback, $args ] = $routes[ $action ];

	$data = [];
	if ( $callback ) {
		$data = $callback( $args );
	}
	if ( ! $data ) {
		$data = respond_404( true );
	}

	return $data;
}

register_route( false, __NAMESPACE__ . '\\respond_404' );
register_route( '404', __NAMESPACE__ . '\\respond_404' );
register_route( 'status', __NAMESPACE__ . '\\respond_status', $lookup );
register_route( 'edit', __NAMESPACE__ . '\\respond_edit', $lookup );
register_route( 'delete', __NAMESPACE__ . '\\redirect_deleted', $lookup );
register_route( 'feed', __NAMESPACE__ . '\\respond_feed' );
register_route( 'home', __NAMESPACE__ . '\\respond_home' );
register_route( 'login', __NAMESPACE__ . '\\respond_login' );
register_route( 'logout', __NAMESPACE__ . '\\respond_logout' );
register_route( 'search', __NAMESPACE__ . '\\respond_search', $lookup );
register_route( 'tag', __NAMESPACE__ . '\\respond_tag', $lookup );

$data = call_route( $action );

switch ( $action ) {
	case false:
	case '404':
		/** @noinspection PhpArrayWriteIsNotUsedInspection */
		$action = '404';
		break;
	case post_has_slug( $action ):
		$data = respond_post( $action );
		$action = 'status';
		break;
}

# Views
require_once( "views/html.php" );

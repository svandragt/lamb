<?php

namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

$root_url = ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ? "https" : "http" ) . "://" . $_SERVER["HTTP_HOST"];
define( 'HIDDEN_CSRF_NAME', 'csrf' );
define( 'LOGIN_PASSWORD', getenv( "LAMB_LOGIN_PASSWORD" ) );
define( 'ROOT_DIR', __DIR__ );
define( 'ROOT_URL', $root_url );
define( 'SESSION_LOGIN', 'logged-in' );
define( 'SUBMIT_CREATE', 'Bleat!' );
define( 'SUBMIT_EDIT', 'Save' );
define( 'SUBMIT_LOGIN', 'Log in' );
unset( $root_url );

# Security
function require_login() {
	if ( ! $_SESSION[ SESSION_LOGIN ] ) {
		$redirect_to = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		$_SESSION['flash'][] = "Please login. You will be redirected to $redirect_to";
		redirect_uri( "/login?redirect_to=$redirect_to" );
	}
}

function require_csrf() {
	$token = filter_input( INPUT_POST, HIDDEN_CSRF_NAME, FILTER_SANITIZE_STRING );
	if ( ! $token || $token !== $_SESSION[ HIDDEN_CSRF_NAME ] ) {
		$txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
		header( $txt );
		die( $txt );
	}
	unset( $_SESSION[ HIDDEN_CSRF_NAME ] );
}

# Transformation
function transform( $bleats ) : array {
	if ( empty( $bleats ) ) {
		return [];
	}
	function render( $bleat ) : array {
		$parts = explode( '---', $bleat );
		$max = count( $parts );
		$front_matter = [];
		if ( $max > 2 ) {
			$ini_text = trim( $parts[ $max - 3 ] );
			$front_matter = parse_ini_string( $ini_text );
		}
		$md_text = trim( $parts[ $max - 1 ] );
		$parser = new LambDown();
		$parser->setSafeMode( true );
		$markdown = $parser->text( $md_text );

		return array_merge( $front_matter, [ 'body' => $markdown ] );
	}

	$data = [];

	foreach ( $bleats as $b ) {
		$data['items'][] = array_merge( [
			'created' => $b->created,
			'updated' => $b->updated,
			'id' => $b->id,
		], render( $b->body ) );
	}

	return $data;
}

# Router handling
function redirect_404( $fallback ) {
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
	$bleat = R::dispense( 'bleat' );
	$bleat->body = $contents;
	$bleat->created = date( "Y-m-d H:i:s" );
	$bleat->updated = date( "Y-m-d H:i:s" );
	try {
		R::store( $bleat );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to save status: ' . $e->getMessage();
	}
	redirect_uri( '/' );
}

function redirect_deleted( $id ) {
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
	$bleat = R::load( 'bleat', (integer) $id );
	$bleat->body = $contents;
	$bleat->updated = date( "Y-m-d H:i:s" );
	try {
		R::store( $bleat );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();
	}
	redirect_uri( '/' );
}

function redirect_uri( $where ) {
	if ( empty( $where ) ) {
		$where = '/';
	}
	header( "Location: $where" );
	die( "Redirecting to $where" );
}

function redirect_login() {
	if ( $_SESSION[ SESSION_LOGIN ] ) {
		redirect_uri( '/' );
	}
	if ( $_POST['submit'] !== SUBMIT_LOGIN ) {
		return null;
	}
	if ( $_POST['password'] !== LOGIN_PASSWORD ) {
		return null;
	}
	require_csrf();

	$_SESSION[ SESSION_LOGIN ] = true;
	$where = filter_input( INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL );
	redirect_uri( $where );
}

function redirect_logout() {
	unset( $_SESSION[ SESSION_LOGIN ] );
	redirect_uri( '/' );
}

function redirect_search( $query ) {
	header( "Location: /search/$query" );
	die( "Redirecting to /search/$query" );
}

function respond_404() : array {
	$header = "HTTP/1.0 404 Not Found";
	header( $header );

	return [
		'title' => $header,
		'intro' => 'Page not found.',
	];
}

# Single
function respond_status( $id ) : array {
	$bleats = [ R::load( 'bleat', (integer) $id ) ];

	return transform( $bleats );
}

function respond_edit( $id ) : OODBBean {
	require_login();

	return R::load( 'bleat', (integer) $id );
}

# Atom feed
function respond_feed() : array {
	global $config;
	$bleats = R::findAll( 'bleat', 'ORDER BY updated DESC LIMIT 20' );
	$data['updated'] = $bleats[0]['updated'];
	$data['title'] = $config['site_title'];

	return array_merge( $data, transform( $bleats ) );
}

# Index
function respond_home() : array {
	global $config;
	$bleats = R::findAll( 'bleat', 'ORDER BY created DESC' );
	$data['title'] = $config['site_title'];

	return array_merge( $data, transform( $bleats ) );
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
	$result = ngettext( "result", "results", count( $bleats ) );
	$data['intro'] = count( $bleats ) . " $result found.";

	return array_merge( $data, transform( $bleats ) );
}

# Tag pages
function respond_tag( $tag ) : array {
	$tag = filter_var( $tag, FILTER_SANITIZE_STRING );
	$bleats = R::find( 'bleat', 'body LIKE ? OR body LIKE ?', [ "% #$tag%", "#$tag%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Tagged with #' . $tag;

	return array_merge( $data, transform( $bleats ) );
}

# Bootstrap
session_start();
R::setup( 'sqlite:../data/lamb.db' );
$config = [
	'author_email' => 'joe.sheeple@example.com',
	'author_name' => 'Joe Sheeple',
	'site_title' => 'Bleats',
];
$user_config = @parse_ini_file( '../config.ini' );
if ( $user_config ) {
	$config = array_merge( $config, $user_config );
}

# Router
$request_uri = '/home';
if ( $_SERVER['REQUEST_URI'] !== '/' ) {
	$request_uri = strtok( $_SERVER['REQUEST_URI'], '?' );
}
$action = strtok( $request_uri, '/' );
switch ( $action ) {
	case '404':
		$data = respond_404();
		$data['action'] = $action;
		$action = '404';
		break;
	case 'status':
		$id = strtok( '/' );
		$data = respond_status( $id );
		break;
	case 'edit':
		$id = strtok( '/' );
		if ( ! empty( $_POST ) ) {
			redirect_edited();
		}
		$bleat = respond_edit( $id );
		break;
	case 'delete':
		if ( empty( $_POST ) ) {
			redirect_uri( '/' );
		}
		$id = strtok( '/' );
		redirect_deleted( $id );
		break;
	case 'feed':
		$data = respond_feed();
		require_once( 'views/feed.php' );
		die();
	case 'home':
		if ( ! empty( $_POST ) ) {
			redirect_created();
		}
		$data = respond_home();
		break;
	case 'login':
		redirect_login();
		break;
	case 'logout':
		redirect_logout();
		break;
	case 'search':
		$query = strtok( '/' );
		$data = respond_search( $query );
		break;
	case 'tag':
		$tag = strtok( '/' );
		$data = respond_tag( $tag );
		break;
	default:
		if ( isset( $config['404_fallback'] ) ) {
			$fallback = $config['404_fallback'];
			if ( filter_var( $fallback, FILTER_VALIDATE_URL ) ) {
				redirect_404( $fallback );
			}
		}
		# Display
		$data = respond_404();
		# Stash action
		/** @noinspection PhpArrayWriteIsNotUsedInspection */
		$data['action'] = $action;
		$action = '404';
		break;
}

# Views
require_once( "views/html.php" );

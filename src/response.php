<?php /** @noinspection PhpUnused */

namespace Svandragt\Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use Svandragt\Lamb\Security;
use Svandragt\Lamb\Config;
use function Svandragt\Lamb\Config\parse_matter;
use function Svandragt\Lamb\transform;

#[NoReturn]
function redirect_404( $fallback ) : void {
	global $request_uri;
	header( "Location: $fallback$request_uri" );
	die( "Redirecting to $fallback$request_uri" );
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

function redirect_created() : ?array {
	Security\require_login();
	Security\require_csrf();
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

#[NoReturn]
function redirect_deleted( $args ) : void {
	if ( empty( $_POST ) ) {
		redirect_uri( '/' );
	}
	Security\require_login();
	Security\require_csrf();

	[ $id ] = $args;
	$bleat = R::load( 'bleat', (integer) $id );
	if ( $bleat !== null ) {
		R::trash( $bleat );
	}
	redirect_uri( '/' );
}

function redirect_edited() {
	Security\require_login();
	Security\require_csrf();
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
	$redirect =  $_SESSION['edit-referrer'];
	unset( $_SESSION['edit-referrer']);
	redirect_uri($redirect );
}

#[NoReturn]
function redirect_uri( $where ) : void {
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
		// Show login page by returning a non empty array.
		return [];
	}
	Security\require_csrf();

	$user_pass = $_POST['password'];
	if ( ! password_verify( $user_pass, base64_decode( LOGIN_PASSWORD ) ) ) {
		$_SESSION['flash'][] = 'Password is incorrect, please try again.';
		redirect_uri( '/' );
	}

	$_SESSION[ SESSION_LOGIN ] = true;
	$where = filter_input( INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL );
	redirect_uri( $where );
}

#[NoReturn]
function redirect_logout() : void {
	unset( $_SESSION[ SESSION_LOGIN ] );
	redirect_uri( '/' );
}

#[NoReturn]
function redirect_search( $query ) : void {
	header( "Location: /search/$query" );
	die( "Redirecting to /search/$query" );
}

# Single
function respond_status( array $args ) : array {
	[ $id ] = $args;
	$bleats = [ R::load( 'bleat', (integer) $id ) ];

	$data = transform( $bleats );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

function respond_edit( array $args ) : array {
	if ( ! empty( $_POST ) ) {
		redirect_edited();
	}
	Security\require_login();

	[ $id ] = $args;

	$_SESSION['edit-referrer'] = $_SERVER['HTTP_REFERER'];

	return [ 'bleat' => R::load( 'bleat', (integer) $id ) ];
}

# Atom feed
#[NoReturn]
function respond_feed() : array {
	global $config;
	global $data;

	// Exclude pages with slugs
	$menu_items = array_keys( $config['menu_items'] ) ?? [];
	$bleats = R::find( 'bleat', ' slug NOT IN (:menu_items) ORDER BY updated DESC LIMIT 20', [ ':menu_items' => $menu_items ], );

	$first_item = reset( $bleats );
	$data['updated'] = $first_item['updated'];
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
	$data['items'] = $data['items'] ?? [];
	foreach ( $data['items'] as &$item ) {
		$item['is_menu_item'] = Config\is_menu_item( $item['slug'] ?? $item['id'] );
	}

	return $data;
}

function respond_post( array $args ) : array {
	[ $slug ] = $args;
	$bleats = [ R::findOne( 'bleat', ' slug = ? ', [ $slug ] ) ];

	return transform( $bleats );
}

# Search result (non-FTS)
function respond_search( array $args ) : array {
	[ $query ] = $args;
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
function respond_tag( array $args ) : array {
	[ $tag ] = $args;
	$tag = filter_var( $tag, FILTER_SANITIZE_STRING );
	$bleats = R::find( 'bleat', 'body LIKE ? OR body LIKE ?', [ "% #$tag%", "#$tag%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Tagged with #' . $tag;

	$data = array_merge( $data, transform( $bleats ) );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

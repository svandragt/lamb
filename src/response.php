<?php /** @noinspection PhpUnused */

namespace Svandragt\Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use JsonException;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use Svandragt\Lamb\Config;
use Svandragt\Lamb\Security;
use function Svandragt\Lamb\Config\parse_matter;
use function Svandragt\Lamb\Route\is_reserved_route;
use function Svandragt\Lamb\transform;
use const ROOT_DIR;

const IMAGE_FILES = 'imageFiles';
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
	$post = R::dispense( 'post' );
	$post->body = $contents;
	$post->slug = $matter['slug'] ?? '';
	$post->created = date( "Y-m-d H:i:s" );
	$post->updated = date( "Y-m-d H:i:s" );

	if ( is_reserved_route( $post->slug ) ) {
		$_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $post->slug . '</code>';

		return null;
	}

	try {
		R::store( $post );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to save: ' . $e->getMessage();
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
	$post = R::load( 'post', (integer) $id );
	if ( $post !== null ) {
		R::trash( $post );
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
	$post = R::load( 'post', (integer) $id );
	$post->body = $contents;
	if ( empty( $post->slug ) ) {
		# Good URLS don't change!
		$post->slug = $matter['slug'] ?? '';
	}
	$post->updated = date( "Y-m-d H:i:s" );

	if ( is_reserved_route( $post->slug ) ) {
		$_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $post->slug . '</code>';

		return null;
	}

	try {
		R::store( $post );
	} catch ( SQL $e ) {
		$_SESSION['flash'][] = 'Failed to update status: ' . $e->getMessage();
	}
	$redirect = $_SESSION['edit-referrer'];
	unset( $_SESSION['edit-referrer'] );
	redirect_uri( $redirect );
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
	$posts = [ R::load( 'post', (integer) $id ) ];

	$data = transform( $posts );
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

	return [ 'post' => R::load( 'post', (integer) $id ) ];
}

# Atom feed
#[NoReturn]
function respond_feed() : void {
	global $config;
	global $data;

	// Exclude pages with slugs
	$menu_items = array_values( $config['menu_items'] ?? [] );
	$posts = R::find( 'post', sprintf( ' slug NOT IN (%s) ORDER BY updated DESC LIMIT 20', R::genSlots( $menu_items ) ), $menu_items );

	$first_post = reset( $posts );
	$data['updated'] = $first_post['updated'];
	$data['title'] = $config['site_title'];

	$data = array_merge( $data, transform( $posts ) );
	require_once( 'views/feed.php' );
	die();
}

# Index
function respond_home() : array {
	global $config;
	if ( ! empty( $_POST ) ) {
		redirect_created();
	}

	$posts = R::findAll( 'post', 'ORDER BY created DESC' );
	$data['title'] = $config['site_title'];

	$data = array_merge( $data, transform( $posts ) );
	$data['items'] = $data['items'] ?? [];
	foreach ( $data['items'] as &$item ) {
		$item['is_menu_item'] = Config\is_menu_item( $item['slug'] ?? $item['id'] );
	}

	return $data;
}

function respond_post( array $args ) : array {
	[ $slug ] = $args;
	$posts = [ R::findOne( 'post', ' slug = ? ', [ $slug ] ) ];

	return transform( $posts );
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
	$posts = R::find( 'post', 'body LIKE ? or body LIKE ?', [ "% $query%", "$query%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Searched for "' . $query . '"';
	$num_results = count( $posts );
	if ( $num_results > 0 ) {
		$result = ngettext( "result", "results", $num_results );
		$data['intro'] = count( $posts ) . " $result found.";
	}

	$data = array_merge( $data, transform( $posts ) );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

# Tag pages
function respond_tag( array $args ) : array {
	[ $tag ] = $args;
	$tag = htmlspecialchars( $tag );
	$posts = R::find( 'post', 'body LIKE ? OR body LIKE ?', [ "% #$tag%", "#$tag%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Tagged with #' . $tag;

	$data = array_merge( $data, transform( $posts ) );
	if ( empty( $data['items'] ) ) {
		respond_404( true );
	}

	return $data;
}

/**
 * @param array $args
 *
 * @return void
 * @throws JsonException
 */
#[NoReturn]
function respond_upload( array $args ) : void {
	if ( empty( $_FILES[ IMAGE_FILES ] ) ) {
		// invalid request http status code
		header( 'HTTP/1.1 400 Bad Request' );
		die( 'No files uploaded!' );
	}
	Security\require_login();

	$files = [];
	foreach ( $_FILES[ IMAGE_FILES ] as $name => $items ) {
		foreach ( $items as $k => $value ) {
			$files[ $k ][ $name ] = $_FILES[ IMAGE_FILES ][ $name ][ $k ];
		}
	}

	$out = '';
	foreach ( $files as $f ) {
		if ( $f['error'] !== UPLOAD_ERR_OK ) {
			// File upload failed
			echo json_encode( 'File upload error: ' . $f['error'] );
			die();
		}
		// File upload successful
		$temp_fp = $f['tmp_name'];
		$ext = pathinfo( $f['name'] )['extension'];
		$new_fn = sha1( $f['name'] ) . ".$ext";
		$new_fp = sprintf( "%s/%s", get_upload_dir(), $new_fn );
		if ( ! move_uploaded_file( $temp_fp, $new_fp ) ) {
			echo json_encode( 'Move upload error: ' . $temp_fp );
			die();
		}
		$upload_url = str_replace( ROOT_DIR, ROOT_URL, get_upload_dir() );
		$out .= sprintf( "![%s](%s)", $f['name'], "$upload_url/$new_fn" );
	}

	echo json_encode( $out, JSON_THROW_ON_ERROR );
	die();
}

function get_upload_dir() {
	// get an upload directory in the current directory based on YYYY/MM/filename.ext
	$upload_dir = sprintf( "%s/assets/%s", ROOT_DIR, date( "Y/m" ) );
	if ( ! is_dir( $upload_dir ) ) {
		if ( ! mkdir( $upload_dir, 0777, true ) && ! is_dir( $upload_dir ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $upload_dir ) );
		}
	}

	return $upload_dir;
}

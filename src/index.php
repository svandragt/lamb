<?php

namespace Lamb;

require '../vendor/autoload.php';

use RedBeanPHP\R;
use function Lamb\Response\respond_404;

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

require_once( ROOT_DIR . '/config.php' );
require_once( ROOT_DIR . '/http.php' );
require_once( ROOT_DIR . '/response.php' );
require_once( ROOT_DIR . '/routes.php' );
require_once( ROOT_DIR . '/security.php' );

/**
 * Retrieves the tags from the given HTML.
 *
 * @param string $html The HTML content to search for tags.
 *
 * @return array An array of tags found in the HTML.
 */
function get_tags( $html ) : array {
	preg_match_all( '/(^|[\s>])#(\w+)/', $html, $matches );

	return $matches[2];
}

/**
 * Parses tags in the given HTML string and converts them into links.
 *
 * This method replaces all occurrences of the "#" symbol followed by an alphanumeric word with
 * a hyperlink to the corresponding tag page. The replacement is done using regular expressions.
 * The resulting HTML string is returned.
 *
 * @param string $html The HTML string to parse tags from.
 *
 * @return string The modified HTML string with tags converted into links.
 */
function parse_tags( $html ) : string {
	return (string) preg_replace( '/(^|[\s>])#(\w+)/', '$1<a href="/tag/$2">#$2</a>', $html );
}

/**
 * Generates a permalink for the given item.
 *
 * This method creates a permalink for the given item based on its slug or ID.
 * If the item has a slug, it appends it to the root URL. Otherwise, it appends
 * the item's ID to the root URL with the "status" path.
 *
 * @param array $item The item for which the permalink is generated.
 *                    It should have the 'slug' and 'id' keys.
 *
 * @return string The generated permalink URL.
 */
function permalink( $item ) : string {
	if ( $item['slug'] ) {
		return ROOT_URL . "/{$item['slug']}";
	}

	return ROOT_URL . '/status/' . $item['id'];
}

/**
 * Renders a post.
 *
 * @param string $post The post to render.
 *
 * @return array The rendered post, including the front matter and the body.
 */
function render( $post ) : array {
	$parts = explode( '---', $post );
	$front_matter = Config\parse_matter( $post );

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

/**
 * Transforms an array of posts into a specific data format.
 *
 * @param array $posts The array of posts to transform.
 *
 * @return array The transformed data.
 */
function transform( $posts ) : array {
	if ( empty( $posts ) ) {
		return [];
	}

	$data = [];

	foreach ( $posts as $post ) {
		if ( is_null( $post ) || $post->id === 0 ) {
			continue;
		}
		$data['items'][] = array_merge( render( $post->body ), [
			'created' => $post->created,
			'id' => $post->id,
			'slug' => $post->slug,
			'updated' => $post->updated,
		] );
	}

	return $data;
}

/**
 * Checks if a post with the given slug exists in the database.
 *
 * @param string $lookup The slug of the post to look up.
 *
 * @return string|null The slug of the post if it exists, otherwise null.
 */
function post_has_slug( string $lookup ) : string|null {
	$post = R::findOne( 'post', ' slug = ? ', [ $lookup ] );
	if ( is_null( $post ) || $post->id === 0 ) {
		return '';
	}

	return $post->slug;
}

# Bootstrap
header( 'Cache-Control: max-age=300' );

$data_dir = '../data';
if ( ! is_dir( $data_dir ) ) {
	if ( ! mkdir( $data_dir, 0777, true ) && ! is_dir( $data_dir ) ) {
		throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $data_dir ) );
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
define( "THEME_DIR", __DIR__ . '/themes/' . THEME . '/' );
define( "THEME_URL", 'themes/' . THEME . '/' );

# Routing
$request_uri = Http\get_request_uri();
$action = strtok( $request_uri, '/' );
$lookup = strtok( '/' );

if ( $action === 'favicon.ico' ) {
	respond_404();

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
require_once( 'theme.php' );
require_once( THEME_DIR . "html.php" );

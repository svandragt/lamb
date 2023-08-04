<?php

namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use function Svandragt\Lamb\Response\respond_404;

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
require_once( ROOT_DIR . '/network.php' );
require_once( ROOT_DIR . '/post.php' );
require_once( ROOT_DIR . '/response.php' );
require_once( ROOT_DIR . '/routes.php' );
require_once( ROOT_DIR . '/security.php' );

function parse_tags( $html ) : string {
	return (string) preg_replace( '/(^|[\s>])#(\w+)/', '$1<a href="/tag/$2">#$2</a>', $html );
}

function permalink( $item ) : string {
	if ( $item['slug'] ) {
		return ROOT_URL . "/{$item['slug']}";
	}

	return ROOT_URL . '/status/' . $item['id'];
}

# Transformation
/**
 * @param array $beans Array of beans
 *
 * @return array Regular array with each post's fields inside the 'items' array.
 */
function transform( array $beans ) : array {
	if ( empty( $beans ) ) {
		return [];
	}
	function render( string $text ) : array {
		$parts = explode( '---', $text );
		$front_matter = Post\parse_matter( $text );

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

	foreach ( $beans as $bean ) {
		if ( is_null( $bean ) || $bean->id === 0 ) {
			continue;
		}
		$data['items'][] = array_merge( render( $bean->body ), [
			'created' => $bean->created,
			'id' => $bean->id,
			'slug' => $bean->slug,
			'updated' => $bean->updated,
			'feed_name' => $bean->feed_name,
			'feeditem_uuid' => $bean->feeditem_uuid,
		] );
	}

	return $data;
}

function post_has_slug( string $lookup ) : string|null {
	$post = R::findOne( 'post', ' slug = ? ', [ $lookup ] );
	if ( is_null( $post ) || $post->id === 0 ) {
		return '';
	}

	return $post->slug;
}

# Bootstrap
header( 'Cache-Control: max-age=300' );
header( "Content-Security-Policy: default-src 'self'; img-src https:; object-src 'none'; require-trusted-types-for 'script'" );
session_start();
R::setup( 'sqlite:../data/lamb.db' );

$config = Config\load();

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

$template = $action;
if ( post_has_slug( $action ) === $action ) {
	Route\register_route( $action, __NAMESPACE__ . '\\Response\respond_post', $action );
	# Ne
	$template = 'status';
}
$data = Route\call_route( $action );

switch ( $action ) {
	case false:
	case '404':
		$action = '404';
		break;
}

# Views
require_once( "views/html.php" );

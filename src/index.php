<?php

namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use RedBeanPHP\R;
use function Svandragt\Lamb\Response\respond_404;

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

require_once( ROOT_DIR . '/config.php' );
require_once( ROOT_DIR . '/http.php' );
require_once( ROOT_DIR . '/response.php' );
require_once( ROOT_DIR . '/routes.php' );
require_once( ROOT_DIR . '/security.php' );

function permalink( $item ) : string {
	if ( $item['slug'] ) {
		return ROOT_URL . "/{$item['slug']}";
	}

	return ROOT_URL . '/status/' . $item['id'];
}

# Transformation
function transform( $bleats ) : array {
	if ( empty( $bleats ) ) {
		return [];
	}
	function render( $bleat ) : array {
		$parts = explode( '---', $bleat );
		$front_matter = Config\parse_matter( $bleat );

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
		if ( is_null( $b ) || $b->id === 0 ) {
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

function post_has_slug( string $lookup ) : string|null {
	$bleat = R::findOne( 'bleat', ' slug = ? ', [ $lookup ] );
	if ( is_null( $bleat ) || $bleat->id === 0 ) {
		return '';
	}

	return $bleat->slug;
}

# Bootstrap
header( 'Cache-Control: max-age=300' );
header( "Content-Security-Policy: default-src 'self'" );
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
Route\register_route( 'status', __NAMESPACE__ . '\\Response\respond_status', $lookup );
Route\register_route( 'edit', __NAMESPACE__ . '\\Response\respond_edit', $lookup );
Route\register_route( 'delete', __NAMESPACE__ . '\\Response\redirect_deleted', $lookup );
Route\register_route( 'feed', __NAMESPACE__ . '\\Response\respond_feed' );
Route\register_route( 'home', __NAMESPACE__ . '\\Response\respond_home' );
Route\register_route( 'login', __NAMESPACE__ . '\\Response\redirect_login' );
Route\register_route( 'logout', __NAMESPACE__ . '\\Response\redirect_logout' );
Route\register_route( 'search', __NAMESPACE__ . '\\Response\respond_search', $lookup );
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

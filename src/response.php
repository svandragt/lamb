<?php /** @noinspection PhpUnused */

namespace Svandragt\Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use Svandragt\Lamb\Config;
use Svandragt\Lamb\Security;
use function Svandragt\Lamb\Config\parse_matter;
use function Svandragt\Lamb\Route\is_reserved_route;
use function Svandragt\Lamb\transform;
use const ROOT_DIR;

const IMAGE_FILES = 'imageFiles';
/**
 * Redirects the user to a 404 page with the provided fallback URL.
 *
 * @param string $fallback The URL to redirect to if the 404 page is not available.
 *
 * @return void
 */
#[NoReturn]
function redirect_404( string $fallback ) : void {
	global $request_uri;
	header( "Location: $fallback$request_uri" );
	die( "Redirecting to $fallback$request_uri" );
}

/**
 * Responds with a 404 error page.
 *
 * @param bool $use_fallback (optional) Whether to use the fallback URL when the 404 page is not available. Default is false.
 *
 * @return array An array containing the title, intro, and action of the 404 error page.
 */
function respond_404( array $args = [], bool $use_fallback = false ) : array {
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

/**
 * Redirects the user after successfully creating a post.
 *
 * This method performs several operations, including checking for user authentication,
 * verifying the presence of a CSRF token, validating the submitted form data,
 * saving the post to the database, and redirecting the user to the home page.
 * If any of these operations fail, the method returns null and no redirection occurs.
 *
 * @return array|null An array containing post data if the redirection was successful,
 *                   otherwise null.
 */
function redirect_created() : ?array {
	Security\require_login();
	Security\require_csrf();
	if ( $_POST['submit'] !== SUBMIT_CREATE ) {
		return null;
	}
	$contents = trim( htmlspecialchars( $_POST['contents'] ?? '' ) );
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

/**
 * Redirects the user to the homepage if the request is not a POST request.
 * If the request is a POST request, then the user is required to be logged in and have a valid CSRF token.
 * If the post with the provided ID exists, it is deleted using RedBean ORM.
 * Finally, the user is redirected to the homepage.
 *
 * @param mixed $args The arguments for the method (expects an array with the first element as the post ID).
 *
 * @return void
 */
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

/**
 * Redirects the user after editing a post.
 *
 * This method performs several checks and updates the post's content and metadata.
 * If any of the checks fail, the method returns null and no redirection is performed.
 *
 * @return void
 */
function redirect_edited() {
	Security\require_login();
	Security\require_csrf();
	if ( $_POST['submit'] !== SUBMIT_EDIT ) {
		return null;
	}

	$contents = trim( htmlspecialchars( $_POST['contents'] ) );
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

/**
 * Redirects the user to a specified URL.
 *
 * @param string $where The URL to redirect to. If empty, redirects to the root URL.
 *
 * @return void
 */
#[NoReturn]
function redirect_uri( $where ) : void {
	if ( empty( $where ) ) {
		$where = '/';
	}
	header( "Location: $where" );
	die( "Redirecting to $where" );
}

/**
 * Redirects the user to the login page if not already logged in.
 *
 * If the user is already logged in, their session is regenerated and they are redirected to the root URL.
 * If the login form has not been submitted or the submitted value is not SUBMIT_LOGIN, it returns an empty array to show the login page.
 * If the submitted password is incorrect, it sets a flash message and redirects to the root URL.
 * If the login is successful, it sets the SESSION_LOGIN session variable to true, regenerates the session ID, and redirects to the specified URL.
 *
 * @return array|null
 */
function redirect_login() : ?array {
	if ( isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		// Already logged in
		session_regenerate_id( true );
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
	session_regenerate_id( true );
	$where = filter_input( INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL );
	redirect_uri( $where );
}

/**
 * Logs out the user by unsetting the session login information, regenerating the session ID, and redirecting to the home page.
 *
 * @return void
 */
#[NoReturn]
function redirect_logout() : void {
	unset( $_SESSION[ SESSION_LOGIN ] );
	session_regenerate_id( true );
	redirect_uri( '/' );
}

/**
 * Redirects the user to a search page with the provided query.
 *
 * @param string $query The search query to be included in the redirected URL.
 *
 * @return void
 */
#[NoReturn]
function redirect_search( $query ) : void {
	header( "Location: /search/$query" );
	die( "Redirecting to /search/$query" );
}

# Single
/**
 * Responds with the status of a post.
 *
 * @param array $args An array containing the post ID.
 *
 * @return array The transformed data representing the post's status.
 */
function respond_status( array $args ) : array {
	[ $id ] = $args;
	$posts = [ R::load( 'post', (integer) $id ) ];

	$data = transform( $posts );
	if ( empty( $data['items'] ) ) {
		respond_404( [], true );
	}

	return $data;
}

/**
 * Responds to the edit request with the provided arguments.
 *
 * @param array $args The arguments passed to the method.
 *                    The first argument should be the ID of the post to edit.
 *
 * @return array The response data.
 *               - The 'post' key contains the loaded post object from the database.
 */
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
/**
 * Responds to a feed request by fetching and rendering the necessary data.
 *
 * This method fetches the feed data by excluding pages with slugs and ordering the posts by the most recent updates.
 * It limits the number of posts returned to 20.
 * After fetching the data, it merges it with the existing data array and renders the feed view.
 *
 * @return void
 */
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
/**
 * Responds to the home page request and returns an array of data.
 *
 * If there is a POST request, it redirects the user to a created page.
 * Retrieves all posts from the database and transforms them into a structured format.
 * It also checks if each post is a menu item.
 *
 * @return array An array containing the transformed posts and additional data:
 *               - ['title']: The site title specified in the configuration.
 *               - ['items']: An array of transformed posts.
 *                             Each transformed post contains the following keys:
 *                             - ['is_menu_item']: A boolean indicating if the post is a menu item.
 */
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

/**
 * Responds to a POST request by retrieving and transforming a single post.
 *
 * @param array $args The arguments for the POST request.
 *                    - string $slug The slug of the post to retrieve.
 *
 * @return array The transformed post.
 */
function respond_post( array $args ) : array {
	[ $slug ] = $args;
	$posts = [ R::findOne( 'post', ' slug = ? ', [ $slug ] ) ];

	return transform( $posts );
}

# Search result (non-FTS)
/**
 * Responds to a search query with an array of search results.
 *
 * @param array $args The arguments for the search. The first element of the array should be the search query.
 *
 * @return array The search results as an associative array. The array may contain the following keys:
 *               - 'title': The title of the search results page.
 *               - 'intro': A short introduction message about the search results.
 *               - 'items': An array of search result items. Each item should be an associative array with the following keys:
 *                          - 'id': The ID of the search result item.
 *                          - 'title': The title of the search result item.
 *                          - 'content': The content of the search result item.
 *
 *               If no search results are found, an empty array will be returned.
 */
function respond_search( array $args ) : array {
	[ $query ] = $args;
	$query = htmlspecialchars( $query );
	if ( empty( $query ) ) {
		$query = htmlspecialchars( $_GET['s'] );
		if ( empty( $query ) ) {
			return [];
		}
		redirect_search( $query );
	}
	$posts = R::find( 'post', 'body LIKE ?', [ "%$query%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Searched for "' . $query . '"';
	$num_results = count( $posts );
	if ( $num_results > 0 ) {
		$result = ngettext( "result", "results", $num_results );
		$data['intro'] = count( $posts ) . " $result found.";
	}

	$data = array_merge( $data, transform( $posts ) );
	if ( empty( $data['items'] ) ) {
		respond_404( [], true );
	}

	return $data;
}

# Tag pages
/**
 * Retrieves posts that are tagged with the provided tag and returns the transformed data.
 *
 * @param array $args The arguments array containing the tag.
 *
 * @return array The transformed data containing the tagged posts.
 */
function respond_tag( array $args ) : array {
	[ $tag ] = $args;
	$tag = htmlspecialchars( $tag );
	$posts = R::find( 'post', 'body LIKE ? OR body LIKE ?', [ "% #$tag%", "%\n#$tag%" ], 'ORDER BY created DESC' );
	$data['title'] = 'Tagged with #' . $tag;

	$data = array_merge( $data, transform( $posts ) );
	if ( empty( $data['items'] ) ) {
		respond_404( [], true );
	}

	return $data;
}

/**
 * Responds to an upload request by processing the uploaded files.
 *
 * @param array $args The arguments for the upload request.
 *
 * @return void
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

/**
 * Retrieves the upload directory for storing files.
 *
 * The upload directory is generated based on the current year/month of the server's date,
 * and is created if it does not exist.
 *
 * @return string The path of the upload directory.
 *
 * @throws \RuntimeException If the upload directory cannot be created.
 */
function get_upload_dir() : string {
	// get an upload directory in the current directory based on YYYY/MM/filename.ext
	$upload_dir = sprintf( "%s/assets/%s", ROOT_DIR, date( "Y/m" ) );
	if ( ! is_dir( $upload_dir ) ) {
		if ( ! mkdir( $upload_dir, 0777, true ) && ! is_dir( $upload_dir ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $upload_dir ) );
		}
	}

	return $upload_dir;
}

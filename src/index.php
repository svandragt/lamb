<?php
namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

define('BUTTON_BLEAT', 'Bleat!');
define('BUTTON_SAVE', 'Save');
define('BUTTON_LOGIN', 'Log in');
define('CSRF_TOKEN_NAME', 'csrf');
define('LOGIN_PASSWORD', getenv("LAMB_LOGIN_PASSWORD"));
define('SESSION_LOGIN', 'loggedin');
$hostname = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"];
define('HOSTNAME', $hostname);

# Security
function require_login() {
	if ( ! $_SESSION[SESSION_LOGIN]) {
		$_SESSION['flash'][] = 'Please login.';
		header("Location: /login");
		die('Redirecting to /login');
	}	
}

function require_csrf() {
	$token = filter_input(INPUT_POST, CSRF_TOKEN_NAME, FILTER_SANITIZE_STRING);
	if (!$token || $token !== $_SESSION[CSRF_TOKEN_NAME]) {
		$txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
		header($txt);
		die($txt);
	}
	unset($_SESSION[CSRF_TOKEN_NAME]);
}

# Transformation
function transform($bleats) {
	if (empty($bleats)) {
		return [];
	} 
	function render($bleat) {
		$parts = explode('---',$bleat);
		$max = count($parts);
		$front_matter = [];
		if ($max > 2) {
			$ini_text = trim($parts[$max-3]);
			$front_matter = parse_ini_string($ini_text);
		}
		$md_text = trim($parts[$max-1]);
		$markdown = (new \Parsedown())->text($md_text);
		return array_merge($front_matter,['body' => $markdown]);
	}
	foreach ($bleats as $b){
		$data['items'][] = array_merge(['created' => $b->created, 'id' => $b->id],render($b->body));
	}
	return $data;
}

# Router handling
function redirect_created() {
	require_login();
	require_csrf();
	if ($_POST['submit'] !== BUTTON_BLEAT) {
		return null;
	}
	$contents = trim(filter_input(INPUT_POST, 'contents', FILTER_SANITIZE_STRING));
	if (empty($contents)) {
		return null;
	}
	$bleat = R::dispense('bleat');
	$bleat->body = $contents;
	$bleat->created = date("Y-m-d H:i:s");
	$bleat->updated = date("Y-m-d H:i:s");
	$id = R::store($bleat);
	redirect_home();
}

function redirect_deleted($id) {
	require_login();
	require_csrf();	

	$bleat = R::load('bleat', (integer)$id);
	if (empty($bleat)) {
		return respond_404();
	} 
	R::trash( $bleat );
	redirect_home();
}

function redirect_edited() {
	require_login();
	require_csrf();
	if ($_POST['submit'] !== BUTTON_SAVE) {
		return null;
	}

	$contents = trim(filter_input(INPUT_POST, 'contents', FILTER_SANITIZE_STRING));
	$id = trim(filter_input(INPUT_POST, 'id', FILTER_SANITIZE_STRING));
	if (empty($contents) || empty($id)) {
		return null;
	}
	$bleat = R::load('bleat', (integer)$id);
	$bleat->body = $contents;
	$bleat->updated = date("Y-m-d H:i:s");
	$id = R::store($bleat);
	redirect_home();
}


function redirect_home() {
	header("Location: /");
	die('Redirecting to /');
}

function redirect_login() {
	if ($_POST['submit'] !== BUTTON_LOGIN) {
		return null;
	}
	if ($_POST['password'] !== LOGIN_PASSWORD) {
		return null;
	}
	require_csrf();

	$_SESSION[SESSION_LOGIN] = true;
	redirect_home();
}

function redirect_logout() {
	unset($_SESSION[SESSION_LOGIN]);
	redirect_home();
}

function redirect_search($query) {
	header("Location: /search/$query");
	die("Redirecting to /search/$query");
}

function respond_404() {
	$header = "HTTP/1.0 404 Not Found";
	header($header);
	return [
		'title' => $header,
		'intro' => 'Page not found.',
	];
}

# Single 
function respond_bleat($id) {
	$bleats = [R::load('bleat', (integer)$id)];
	return transform($bleats);
}

function respond_edit($id) {
	$bleat = R::load('bleat', (integer)$id);
	return $bleat;
}

# Atom feed
function respond_feed() {
	$bleats = R::findAll('bleat', 'ORDER BY created DESC LIMIT 20');
	$data['updated'] = $bleats[0]['created'];
	$data['title'] = $config['site_title'];
	return array_merge($data, transform($bleats));
}

# Index
function respond_home() {
	$bleats = R::findAll('bleat', 'ORDER BY created DESC');
	$data['title'] = $config['site_title'];
	return array_merge($data, transform($bleats));
}

# Search result (non-FTS)
function respond_search($query) {
	$query = filter_var($query, FILTER_SANITIZE_STRING);
	if (empty($query)) {
		$query = filter_input(INPUT_GET, 's', FILTER_SANITIZE_STRING);
		if (empty($query)) {
			return [];
		}
		redirect_search($query);
	}
	$bleats = R::find('bleat', 'body LIKE ?', ["%$query%"], 'ORDER BY created DESC');
	$data['title'] = 'Search results for "' . $query . '"';
	$result = ngettext("result", "results",count($bleats));
	$data['intro'] = count($bleats) . " $result found.";
	return array_merge($data, transform($bleats));
}

# Tag pages
function respond_tag($tag) {
	$tag = filter_var($tag, FILTER_SANITIZE_STRING);
	$bleats = R::find('bleat', 'body LIKE ?', ["%#$tag %"], 'ORDER BY created DESC');
	$data['title'] = 'Tagged with #' . $tag;
	return array_merge($data, transform($bleats));
}

# Bootstrap
session_start();
R::setup( 'sqlite:../data/lamb.db' );
$config = [
	'author_email' => 'joe.sheeple@example.com',
	'author_name' => 'Joe Sheeple',
	'site_title' => 'Bleats',
];
$user_config = @parse_ini_file('../config.ini');
if ($user_config) {
	$config = array_merge($config, $user_config);
} 

# Router
$path_info = $_SERVER['PATH_INFO'] ?? '/home';
$action = strtok($path_info, '/');
switch ($action) {
	case 'bleat':
		$id = strtok('/');
		$data =  respond_bleat($id);
		break;
	case 'edit':
		$id = strtok('/');
		if ( !empty($_POST)) {
			redirect_edited();
		}
		$bleat = respond_edit($id);
		break;
	case 'delete':
		if ( empty($_POST)) {
			redirect_home();
		}
		$id = strtok('/');
		$data = redirect_deleted($id);
		break;
	case 'feed':
		$data = respond_feed();
		require_once('views/feed.php');
		die();
	case 'home':
		if (! empty($_POST)) {
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
		$query = strtok('/');
		$data = respond_search($query);
		break;
	case 'tag':
		$tag = strtok('/');
		$data = respond_tag($tag);
		break;
	default:
		$data = respond_404();
		break;
}


# Views
require_once("views/html.php");	

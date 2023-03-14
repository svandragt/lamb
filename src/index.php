<?php
namespace Svandragt\Lamb;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

define('BUTTON_BLEAT', 'Bleat!');
define('BUTTON_LOGIN', 'Log in');
define('CSRF_TOKEN_NAME', 'csrf');
define('LOGIN_PASSWORD', getenv("LAMB_LOGIN_PASSWORD"));
define('SESSION_LOGIN', 'loggedin');
$hostname = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"];
define('HOSTNAME', $hostname);

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

function redirect_create() {
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
	$id = R::store($bleat);
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

	$_SESSION[SESSION_LOGIN] = 'yup';
	header("Location: /");
	die('Redirecting to /');
}

function redirect_logout() {
	unset($_SESSION[SESSION_LOGIN]);
	header("Location: /");
	die('Redirecting to /');
}

function redirect_deleted($id) {
	require_login();
	require_csrf($id);	

	$bleat = R::load('bleat', (integer)$id);
	if (empty($bleat)) {
		return respond_404();
	} 
	R::trash( $bleat );
	header("Location: /");
	die('Redirecting to /');
}

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

function respond_404() {
	$header = "HTTP/1.0 404 Not Found";
	header($header);
	return [
		'title' => $header,
		'intro' => 'Page not found.',
	];
}

function respond_home() {
	$bleats = R::findAll('bleat', 'ORDER BY created DESC');
	$data['title'] = $config['site_title'];
	return array_merge($data, process($bleats));
}

function respond_bleat($id) {
	$bleats = [R::load('bleat', (integer)$id)];
	return process($bleats);
}

function process($bleats) {
	if (empty($bleats)) {
		return respond_404();
	} 
	foreach ($bleats as $b){
		$data['items'][] = array_merge(['created' => $b->created, 'id' => $b->id],render($b->body));
	}
	return $data;
}


$config = [
	'author_email' => 'joe.sheeple@example.com',
	'author_name' => 'Joe Sheeple',
	'site_title' => 'Bleats',
];
$user_config = parse_ini_file('../config.ini') ?? [];
if ($user_config) {
	$config = array_merge($config, $user_config);
} 

session_start();
R::setup( 'sqlite:../data/lamb.db' );

# Router
$path_info = $_SERVER['PATH_INFO'] ?? '/home';
$action = strtok($path_info, '/');
switch ($action) {
	case 'bleat':
		$id = strtok('/');
		$data =  respond_bleat($id);
		break;
	case 'delete':
		$id = strtok('/');
		$data = redirect_deleted($id);
		break;
	case 'feed':
		$format ='.feed';
		$data = respond_home();
		require_once('views/feed.php');
		die();
	case 'home':
		if (! empty($_POST)) {
			redirect_create();
		}
		$data = respond_home();
		break;
	case 'login':
		redirect_login();
		break;
	case 'logout':
		redirect_logout();
		break;

	default:
		$data = respond_404();
		break;
}

require_once("views/html.php");	

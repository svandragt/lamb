<?php
namespace Svandragt\Microsites;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

define('BUTTON_BLEAT', 'Bleat!');
define('BUTTON_LOGIN', 'Log in');
define('LOGIN_PASSWORD', getenv("LAMB_LOGIN_PASSWORD"));

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
	if ($_POST['submit'] !== BUTTON_BLEAT) {
		return null;
	}
	if ( ! session['loggedin']) {
		return null;
	}
	$bleat = R::dispense('bleat');
	$bleat->body = filter_var($_POST['contents'], FILTER_SANITIZE_STRING);
	$bleat->created = date("Y-m-d H:i:s");
	$id = R::store($bleat);
	header("Location: /");
	die();
}

function redirect_login() {
	if ($_POST['submit'] !== BUTTON_LOGIN) {
		return null;
	}
	if ($_POST['password'] !== LOGIN_PASSWORD) {
		return null;
	}
	$_SESSION['loggedin'] = 'yup';
	header("Location: /");
	die();
}

function redirect_logout() {
	unset($_SESSION['loggedin']);
	header("Location: /");
	die();
}

function redirect_deleted($id) {
	$bleat = R::load('bleat', (integer)$id);
	if (empty($bleat)) {
		return respond_404();
	} 
	R::trash( $bleat );
	header("Location: /");
	die();
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
	$data['title'] = 'Bleats';
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

session_start();
R::setup( 'sqlite:../data/lamb.db' );

# Router
$path_info = $_SERVER['PATH_INFO'] ?? '/home';
$action = strtok($path_info, '/');
$match = true;
switch ($action) {
	case 'delete':
		$id = strtok('/');
		$data = redirect_deleted($id);
		break;
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
	case 'bleat':
		$id = strtok('/');
		$data =  respond_bleat($id);
		break;
	default:
		$data = respond_404();
		break;
}

require_once("views/_layout.php");
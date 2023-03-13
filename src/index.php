<?php
namespace Svandragt\Microsites;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

define('BUTTON_LABEL', 'Bleat!');

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
	if ($_POST['submit'] !== BUTTON_LABEL) {
		return null;
	}
	$bleat = R::dispense('bleat');
	$bleat->body = filter_var($_POST['contents'], FILTER_SANITIZE_STRING);
	$bleat->created = date("Y-m-d H:i:s");
	$id = R::store($bleat);
	return $id;
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

function respond_index() {
	$bleats = R::findAll('bleat');
	$data['title'] = 'Bleats';
	return array_merge($data, process($bleats));
}

function respond_view($id) {
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

R::setup( 'sqlite:../data/lamb.db' );

# Router
$path_info = (empty($_SERVER['PATH_INFO']) ? '/index' : $_SERVER['PATH_INFO']);
$action = strtok($path_info, '/');
switch ($action) {
	case 'delete':
		$id = strtok('/');
		$data = redirect_deleted($id);
		break;
	case 'index':
		if (! empty($_POST)) {
			$id = redirect_create();
		}
		$data = respond_index();
		break;
	case 'bleat':
		$id = strtok('/');
		$data =  respond_view($id);
		break;
	default:
		$data = respond_404();
		break;
}

require_once("layout.php");
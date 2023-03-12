<?php
namespace Svandragt\Microsites;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

function md($bleat) {
	$parts = explode('---',$bleat);
	$max = count($parts);
	$front_matter = [];
	if ($max > 2) {
		$ini_text = trim($parts[$max-3]);
		$front_matter = parse_ini_string($ini_text);
	}
	$md_text = trim($parts[$max-1]);
	$markdown = (new \Parsedown())->text($md_text);
	return array_merge($front_matter,['bleat' => $markdown]);
}

function response_404() {
	$header = "HTTP/1.0 404 Not Found";
	header($header);
	return [
		'title' => $header,
		'bleat' => 'Page not found',
	];
}

R::setup( 'sqlite:../data/lamb.db' );
# Submission
if (! empty($_POST) && $_POST['submit'] === 'Blaat') {
	$bleat = R::dispense('bleat');
	$bleat->contents = filter_var($_POST['contents'], FILTER_SANITIZE_STRING);
	$bleat->created = date("Y-m-d H:i:s");
	$id = R::store($bleat);
}

$path_info = (empty($_SERVER['PATH_INFO']) ? '/index' : $_SERVER['PATH_INFO']);
$action = strtok($path_info, '/');

switch ($action) {
	case 'bleat':
		$id = strtok('/');
		$bleats = [R::load('bleat', (integer)$id)];
		if (empty($bleats)) {
			$data['bleats'] = response_404();
		} 
		else {
			foreach ($bleats as $b){
				$data['bleats'][] = array_merge(['created' => $b->created, 'id' => $b->id],md($b->contents));
			}
		}
		break;
	case 'delete':
		$id = strtok('/');
		$bleat = R::load('bleat', (integer)$id);
		if (empty($bleat)) {
			$data['bleats'] = response_404();
		} 
		else {
			R::trash( $bleat );
			header("Location: /");
			die();
		}
	case 'index':
		$data['title'] = 'Blaats';
		$bleats = R::findAll('bleat');
		if (empty($bleats)) {
			$data['bleats'] = response_404();
		} 
		else {
			foreach ($bleats as $b){
				$data['bleats'][] = array_merge(['created' => $b->created, 'id' => $b->id],md($b->contents));
			}
		}
		break;
	
	default:
		$data = response_404();
		break;
}

require_once("layout.php");
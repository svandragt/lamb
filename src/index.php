<?php
namespace Svandragt\Microsites;

require '../vendor/autoload.php';

use \RedBeanPHP\R as R;

function md($blaat) {
	$parts = explode('---',$blaat);
	$max = count($parts);
	$front_matter = [];
	if ($max > 2) {
		$ini_text = trim($parts[$max-3]);
		$front_matter = parse_ini_string($ini_text);
	}
	$md_text = trim($parts[$max-1]);
	$markdown = (new \Parsedown())->text($md_text);
	return array_merge($front_matter,['blaat' => $markdown]);
}

function response_404() {
	$header = "HTTP/1.0 404 Not Found";
	header($header);
	return [
		'title' => $header,
		'blaat' => 'Page not found',
	];
}

R::setup( 'sqlite:lamb.db' );
# Submission
if (! empty($_POST) && $_POST['submit'] === 'Blaat') {
	$blaat = R::dispense('blaat');
	$blaat->contents = filter_var($_POST['contents'], FILTER_SANITIZE_STRING);
	$blaat->created = date("Y-m-d H:i:s");
	$id = R::store($blaat);
}

$path_info = (empty($_SERVER['PATH_INFO']) ? '/index' : $_SERVER['PATH_INFO']);
$action = strtok($path_info, '/');

switch ($action) {
	case 'blaat':
		$id = strtok('/');
		$blaats = [R::load('blaat', (integer)$id)];
		if (empty($blaats)) {
			$data['blaats'] = response_404();
		} 
		else {
			foreach ($blaats as $b){
				$data['blaats'][] = array_merge(['created' => $b->created, 'id' => $b->id],md($b->contents));
			}
		}
		break;
	case 'delete':
		$id = strtok('/');
		$blaat = R::load('blaat', (integer)$id);
		if (empty($blaat)) {
			$data['blaats'] = response_404();
		} 
		else {
			R::trash( $blaat );
			header("Location: /");
			die();
		}
	case 'index':
		$data['title'] = 'Blaats';
		$blaats = R::findAll('blaat');
		if (empty($blaats)) {
			$data['blaats'] = response_404();
		} 
		else {
			foreach ($blaats as $b){
				$data['blaats'][] = array_merge(['created' => $b->created, 'id' => $b->id],md($b->contents));
			}
		}
		break;
	
	default:
		$data = response_404();
		break;
}

require_once("layout.php");
<?php

namespace Svandragt\Lamb\Http;

/**
 * @return false|string
 */
function get_request_uri() : string|false {
	$request_uri = '/home';
	if ( $_SERVER['REQUEST_URI'] !== '/' ) {
		$request_uri = strtok( $_SERVER['REQUEST_URI'], '?' );
	}

	return $request_uri;
}

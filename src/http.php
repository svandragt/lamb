<?php

namespace Svandragt\Lamb\Http;

/**
 * @return false|string
 */
function get_request_uri() : string|false {
	$request_uri = strtok( $_SERVER['REQUEST_URI'], '?' );
	if ( $request_uri === '/' ) {
		return '/home';
	}

	return $request_uri;
}

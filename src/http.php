<?php

namespace Svandragt\Lamb\Http;

/**
 * Retrieves the current request URI.
 *
 * This method uses the PHP superglobal variable $_SERVER to retrieve the request URI.
 * The request URI is the string representation of the current URL path.
 * The URI does not include any query parameters that may be present in the URL.
 *
 * @return string|false The current request URI as a string. If the URI is '/',
 *                     the method returns the string '/home'. If the URI cannot be determined,
 *                     the method returns false.
 */
function get_request_uri() : string|false {
	$request_uri = strtok( $_SERVER['REQUEST_URI'], '?' );
	if ( $request_uri === '/' ) {
		return '/home';
	}

	return $request_uri;
}

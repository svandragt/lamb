<?php

namespace Svandragt\Lamb\Route;

use Svandragt\Lamb\Response;

function register_route( bool|string $action, string $callback, mixed ...$args ) : void {
	global $routes;
	$routes[ $action ] = [ $callback, $args ];
}

function call_route( bool|string $action ) : array {
	global $routes;
	[ $callback, $args ] = $routes[ $action ];

	if ( is_null( $callback ) ) {
		return Response\respond_404( true );
	}

	return $callback( $args );
}

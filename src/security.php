<?php

namespace Svandragt\Lamb\Security;

use Svandragt\Lamb\Response;

# Security
function require_login() : void {
	if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		$redirect_to = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		$_SESSION['flash'][] = "Please login. You will be redirected to $redirect_to";
		Response\redirect_uri( "/login?redirect_to=$redirect_to" );
	}
}

function require_csrf() : void {
	$token = htmlspecialchars( $_POST[ HIDDEN_CSRF_NAME ] );
	if ( ! $token || $token !== $_SESSION[ HIDDEN_CSRF_NAME ] ) {
		$txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
		header( $txt );
		die( $txt );
	}
	unset( $_SESSION[ HIDDEN_CSRF_NAME ] );
}

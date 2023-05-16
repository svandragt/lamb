<?php

namespace Svandragt\Lamb\Security;

use Svandragt\Lamb\Response;

# Security
function require_login() : void {
	if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		$_SESSION['flash'][] = "Please login";
		Response\redirect_uri( "/login" );
	}
}

function require_csrf() : void {
	$token = htmlspecialchars( $_POST[ HIDDEN_CSRF_NAME ] );
	$csrf = $_SESSION[ HIDDEN_CSRF_NAME ] ?? null;
	if ( ! $token || $token !== $csrf ) {
		$txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
		header( $txt );
		die( $txt );
	}
	unset( $_SESSION[ HIDDEN_CSRF_NAME ] );
}

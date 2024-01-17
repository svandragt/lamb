<?php

/**
 * Checks if a user is logged in. If not, it redirects to the login page
 *
 * @since 1.0.0
 */

namespace Svandragt\Lamb\Security;

/**
 * Class Response
 *
 * Represents a response returned by a controller action.
 */

use    Svandragt\Lamb\Response;

# Security
/**
 * Checks if the user is logged in.
 *
 * @return void
 *
 * If the user is not logged in, a flash message "Please login" is added to the session and the user is redirected to the login page.
 */
function require_login() : void {
	if ( ! isset( $_SESSION[ SESSION_LOGIN ] ) ) {
		$_SESSION['flash'][] = "Please login";
		Response\redirect_uri( "/login" );
	}
}

/**
 * Checks if the CSRF token in the POST request matches the token stored in the session.
 * If the tokens don't match, sends a 405 Method Not Allowed response and terminates the script.
 * After successful validation, removes the CSRF token from the session.
 *
 * @return void
 */
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

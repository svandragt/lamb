<?php

/**
 * Checks if a user is logged in. If not, it redirects to the login page
 *
 * @since 1.0.0
 */

namespace Lamb\Security;

/**
 * Class Response
 *
 * Represents a response returned by a controller action.
 */

use    Lamb\Response;

# Security
/**
 * Checks if the user is logged in.
 *
 * @return void
 *
 * If the user is not logged in, a flash message "Please login" is added to the session and the user is redirected to the login page.
 */
function get_login_url(string $current_uri): string
{
    if (empty($current_uri)) {
        return '/login';
    }
    return '/login?redirect_to=' . urlencode($current_uri);
}

function require_login(): void
{
    if (! isset($_SESSION[SESSION_LOGIN])) {
        $_SESSION['flash'][] = "Please login";
        Response\redirect_uri(get_login_url($_SERVER['REQUEST_URI'] ?? ''));
    }
}

/**
 * Checks if the CSRF token in the POST request matches the token stored in the session.
 * If the tokens don't match, sends a 405 Method Not Allowed response and terminates the script.
 * After successful validation, removes the CSRF token from the session.
 *
 * @return void
 */
function require_csrf(): void
{
    $token = $_POST[HIDDEN_CSRF_NAME] ?? '';
    $csrf = $_SESSION[HIDDEN_CSRF_NAME] ?? '';
    if (! $token || ! hash_equals($csrf, $token)) {
        $txt = $_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed';
        header($txt);
        die($txt);
    }
    unset($_SESSION[HIDDEN_CSRF_NAME]);
}

<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Network;
use Lamb\Security;
use Random\RandomException;

/**
 * Redirects the user to the login page if not already logged in.
 *
 * If the user is already logged in, their session is regenerated and they are redirected to the root URL.
 * If the login form has not been submitted or the submitted value is not SUBMIT_LOGIN, it returns an empty array to show the login page.
 * If the submitted password is incorrect, it sets a flash message and redirects to the root URL.
 * If the login is successful, it sets the SESSION_LOGIN session variable to true, regenerates the session ID, and redirects to the specified URL.
 *
 * @return array<string, mixed>
 * @throws RandomException
 */
function redirect_login(): array
{
    // The login page needs a session for the CSRF token and any flash messages,
    // even for an otherwise-anonymous visitor who carries no session cookie yet.
    \Lamb\Bootstrap\start_session();

    // Prevent caching for this page
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if (isset($_SESSION[SESSION_LOGIN])) {
        // Already logged in (e.g. an existing session adopted via LAMBSESSID).
        // Reissue the marker too: the session is authoritative, but without a
        // valid marker the next request treats the visitor as anonymous.
        session_regenerate_id(true);
        set_login_marker();
        redirect_uri('/');
    }
    if (!isset($_POST['submit']) || $_POST['submit'] !== SUBMIT_LOGIN) {
        // Show login page by returning a non-empty array.
        return [];
    }
    Security\require_csrf();

    $user_pass = $_POST['password'];
    if (!password_verify($user_pass, base64_decode(LOGIN_PASSWORD))) {
        $_SESSION['flash'][] = 'Password is incorrect, please try again.';
        redirect_uri('/');
    }

    $_SESSION[SESSION_LOGIN] = true;
    session_regenerate_id(true);
    set_login_marker();
    $where = local_redirect_target(filter_input(INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL) ?: null);
    redirect_uri($where);
}

/**
 * Issues the signed lamb_logged_in marker cookie for the current login.
 *
 * The marker is a random id signed with the per-install login hash so
 * should_start_session() can confirm we issued it without touching session
 * storage — a forged cookie can't trigger a session_start(). Called on every
 * path that concludes the visitor is authenticated, so the marker never drifts
 * out of sync with the session.
 *
 * @return void
 * @throws RandomException
 */
function set_login_marker(): void
{
    $uuid = bin2hex(random_bytes(16));
    $marker = \Lamb\Bootstrap\sign_login_marker($uuid, LOGIN_PASSWORD);
    setcookie('lamb_logged_in', $marker, get_cookie_options(time() + REMEMBER_LIFETIME));
}

/**
 * Constrains a post-login redirect target to a local path, defeating open-redirect
 * phishing via the `redirect_to` parameter.
 *
 * Only same-site absolute paths are accepted: the value must start with a single
 * "/" and must not begin with "//" or "/\" (which browsers treat as protocol-relative
 * URLs pointing off-site). Anything else falls back to the site root.
 *
 * @param string|null $value The requested redirect target.
 * @return string A safe local path, or '/' when the value is missing or off-site.
 */
function local_redirect_target(?string $value): string
{
    if ($value === null || $value === '' || $value[0] !== '/') {
        return '/';
    }
    if (str_starts_with($value, '//') || str_starts_with($value, '/\\')) {
        return '/';
    }

    return $value;
}

/**
 * Logs out the user by unsetting the session login information, regenerating the session ID, and redirecting to the home page.
 *
 * @return void
 */
#[NoReturn]
function redirect_logout(): void
{
    $_SESSION = [];

    // Clear the login marker cookie.
    setcookie('lamb_logged_in', '', get_cookie_options(time() - 3600));

    // Expire the session cookie too, so subsequent requests are fully anonymous
    // (no session started, responses cacheable again — issue #116).
    $params = session_get_cookie_params();
    setcookie(session_name() ?: '', '', [
        'expires'  => time() - 3600,
        'path'     => $params['path'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    redirect_uri('/');
}

/**
 * Handles the settings page logic, including displaying, validating, and saving settings.
 *
 * @return array<string, mixed> An array containing the page title and the current or updated INI configuration text.
 */
function respond_settings(): array
{
    Security\require_login();

    $data = [
        'title' => 'Settings',
        'ini_text' => Config\get_ini_text(),
        'feed_statuses' => Network\get_feed_statuses(),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security\require_csrf();

        if (isset($_POST['action']) && $_POST['action'] === 'reset') {
            $default_ini = Config\get_default_ini_text();
            Config\save_ini_text($default_ini);
            $_SESSION['flash'][] = "Settings reset to defaults.";
            redirect_uri('/settings');
        }

        $submitted_ini = $_POST['ini_text'] ?? '';
        $validation = Config\validate_ini($submitted_ini);

        if ($validation['valid']) {
            Config\save_ini_text($submitted_ini);
            $_SESSION['flash'][] = "Settings saved successfully.";
            redirect_uri('/settings');
        } else {
            $_SESSION['flash'][] = "Invalid INI syntax. Your changes were not saved.";
            if ($validation['error']) {
                $_SESSION['flash'][] = $validation['error'];
            }
            $data['ini_text'] = $submitted_ini; // Preserve typed content
        }
    }

    return $data;
}

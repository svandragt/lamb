<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
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
 * @return array|null
 * @throws RandomException
 */
function redirect_login(): ?array
{
    // Prevent caching for this page
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if (isset($_SESSION[SESSION_LOGIN])) {
        // Already logged in
        session_regenerate_id(true);
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

    $uuid = bin2hex(random_bytes(16)); // Generate a UUID
    setcookie('lamb_logged_in', $uuid, get_cookie_options(time() + 3600));
    $where = filter_input(INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL);
    redirect_uri($where);
}

/**
 * Logs out the user by unsetting the session login information, regenerating the session ID, and redirecting to the home page.
 *
 * @return void
 */
#[NoReturn]
function redirect_logout(): void
{
    unset($_SESSION[SESSION_LOGIN]);

    setcookie('lamb_logged_in', '', get_cookie_options(time() - 3600));

    session_regenerate_id(true);
    redirect_uri('/');
}

/**
 * Handles the settings page logic, including displaying, validating, and saving settings.
 *
 * @return array An array containing the page title and the current or updated INI configuration text.
 */
function respond_settings(): array
{
    Security\require_login();

    $data = [
        'title' => 'Settings',
        'ini_text' => Config\get_ini_text(),
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

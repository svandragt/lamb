<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Network;
use Lamb\Security;
use Random\RandomException;

/**
 * Handles the /login route without starting a session for anonymous visitors.
 *
 * /login is the one anonymous-reachable route, so starting a session here let an
 * attacker mint a week-lived session file per request — a disk-exhaustion DoS
 * through the single endpoint that couldn't refuse a session (issue #462). The
 * form's CSRF protection therefore rides in a signed double-submit cookie + a
 * matching hidden field (issue_login_csrf()/valid_login_csrf()) instead of the
 * session, and a server-side session is only established *after* the password
 * verifies — so anonymous traffic never writes a session file.
 *
 * - Already logged in (a valid marker let bootstrap start a session): the marker
 *   is reissued and the visitor is bounced to the root URL.
 * - Form not submitted: returns the login page data (including the double-submit
 *   token) so the form renders.
 * - Wrong password: the page is re-rendered in place with the error in the
 *   returned data — there is no session flash to carry it (issue #460).
 * - Correct password: a session is started, SESSION_LOGIN is set, the id is
 *   regenerated, the marker is issued, and the visitor is redirected.
 *
 * @return array<string, mixed>
 * @throws RandomException
 */
function redirect_login(): array
{
    // Prevent caching for this page
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");

    if (isset($_SESSION[SESSION_LOGIN])) {
        // Already logged in (bootstrap started a session from a valid marker).
        // Reissue the marker too: the session is authoritative, but without a
        // valid marker the next request treats the visitor as anonymous.
        session_regenerate_id(true);
        set_login_marker();
        redirect_uri('/');
    }
    if (!isset($_POST['submit']) || $_POST['submit'] !== SUBMIT_LOGIN) {
        // Show login page (no session started for the anonymous visitor).
        return login_page_data();
    }
    require_login_csrf();

    $user_pass = $_POST['password'] ?? '';
    if (!password_verify($user_pass, base64_decode(LOGIN_PASSWORD))) {
        log_failed_login();
        // Re-render the login page in place with the error: /login is sessionless
        // now, so there is no flash to carry the message across a redirect (#462).
        return login_page_data('Password is incorrect, please try again.');
    }

    // Password verified — only now do we establish server-side state.
    \Lamb\Bootstrap\start_session();
    $_SESSION[SESSION_LOGIN] = true;
    session_regenerate_id(true);
    set_login_marker();
    clear_login_csrf();
    $where = local_redirect_target(filter_input(INPUT_POST, 'redirect_to', FILTER_SANITIZE_URL) ?: null);
    redirect_uri($where);
}

/**
 * Builds the data array used to render the (sessionless) login page.
 *
 * Always issues a fresh-or-reused double-submit CSRF token for the form, and
 * optionally carries an inline error message to re-render after a failed login.
 *
 * @param string|null $error Inline error to display, or null for a clean form.
 * @return array<string, mixed>
 * @throws RandomException
 */
function login_page_data(?string $error = null): array
{
    $data = ['login_csrf' => issue_login_csrf()];
    if ($error !== null) {
        $data['login_error'] = $error;
    }
    return $data;
}

/**
 * Derives the HMAC key used to sign the anonymous /login CSRF token.
 *
 * Deliberately distinct from the raw login hash (the key used for lamb_logged_in
 * markers): if the two shared a key, a CSRF token harvested from GET /login would
 * itself be a valid login marker, and replaying it as lamb_logged_in would start
 * a session per request — reopening the very DoS this endpoint avoids (#462).
 *
 * @param string $loginHash The per-install login hash (LAMB_LOGIN_PASSWORD).
 * @return string A derived HMAC key, distinct from $loginHash.
 */
function login_csrf_secret(string $loginHash): string
{
    return hash_hmac('sha256', 'lamb-login-csrf', $loginHash);
}

/**
 * Returns options for the /login CSRF cookie, reusing the hardened defaults.
 *
 * Like the session cookie (configure_session()), `secure` tracks the connection
 * scheme rather than being forced on: the token this cookie replaces — the
 * session-backed CSRF token — rode in LAMBSESSID, which is only marked secure
 * under HTTPS, so a plain-HTTP dev server still round-trips it. SameSite=Strict
 * is the load-bearing control: a cross-site POST never carries the cookie, so the
 * double-submit comparison fails.
 *
 * @param int $expires Unix timestamp for cookie expiry.
 * @return array<string, mixed>
 */
function login_csrf_cookie_options(int $expires): array
{
    $options = get_cookie_options($expires);
    $options['secure'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    return $options;
}

/**
 * Issues (or reuses) the anonymous /login double-submit CSRF token.
 *
 * Returns the token to embed in the form's hidden field and, when no valid token
 * cookie is already present, sets a matching signed cookie. A still-valid cookie
 * is reused rather than rotated so two tabs both sitting on /login don't
 * invalidate each other's hidden field. No session is touched.
 *
 * @return string The signed double-submit token.
 * @throws RandomException
 */
function issue_login_csrf(): string
{
    $secret   = login_csrf_secret(LOGIN_PASSWORD);
    $existing = $_COOKIE[LOGIN_CSRF_COOKIE] ?? null;
    if (is_string($existing) && \Lamb\Bootstrap\valid_login_marker($existing, $secret)) {
        return $existing;
    }
    $token = \Lamb\Bootstrap\sign_login_marker(bin2hex(random_bytes(16)), $secret);
    setcookie(LOGIN_CSRF_COOKIE, $token, login_csrf_cookie_options(time() + LOGIN_CSRF_LIFETIME));
    // Reflect for any same-request read (e.g. validation in tests).
    $_COOKIE[LOGIN_CSRF_COOKIE] = $token;
    return $token;
}

/**
 * Validates the anonymous /login double-submit CSRF token.
 *
 * Passes only when the hidden field is byte-for-byte equal to the cookie (the
 * double-submit check) AND the value carries a valid signature under the derived
 * CSRF key (proving the server issued it). No session is consulted.
 *
 * @return bool
 */
function valid_login_csrf(): bool
{
    $field  = $_POST[HIDDEN_CSRF_NAME] ?? '';
    $cookie = $_COOKIE[LOGIN_CSRF_COOKIE] ?? '';
    if (!is_string($field) || !is_string($cookie) || $field === '' || $cookie === '') {
        return false;
    }
    if (!hash_equals($cookie, $field)) {
        return false;
    }
    return \Lamb\Bootstrap\valid_login_marker($field, login_csrf_secret(LOGIN_PASSWORD));
}

/**
 * Enforces the /login double-submit CSRF check, mirroring Security\require_csrf():
 * a failed check sends 405 Method Not Allowed and terminates the request.
 *
 * @return void
 */
function require_login_csrf(): void
{
    if (!valid_login_csrf()) {
        $txt = ($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 405 Method Not Allowed';
        header($txt);
        die($txt);
    }
}

/**
 * Clears the /login CSRF cookie once it has served its purpose (after a
 * successful login). Best-effort tidy-up; an expired token is harmless.
 *
 * @return void
 */
function clear_login_csrf(): void
{
    if (isset($_COOKIE[LOGIN_CSRF_COOKIE])) {
        setcookie(LOGIN_CSRF_COOKIE, '', login_csrf_cookie_options(time() - 3600));
        unset($_COOKIE[LOGIN_CSRF_COOKIE]);
    }
}

/**
 * Writes an audit line for a failed admin login attempt via error_log().
 *
 * The line carries a fixed "failed admin login" marker (easy to grep) and the
 * client IP from REMOTE_ADDR, falling back to "unknown" when it is absent. It
 * deliberately records no secret — never the submitted password. error_log()
 * respects the host's configured log destination (web server / PHP-FPM), so a
 * self-hoster needs no new dependency to capture brute-force attempts.
 *
 * Trust the IP only behind a known proxy: REMOTE_ADDR is the immediate peer, so
 * behind a reverse proxy it is the proxy's address rather than the real client.
 *
 * @return void
 */
function log_failed_login(): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') {
        $ip = 'unknown';
    }
    error_log(sprintf('failed admin login from %s', $ip));
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

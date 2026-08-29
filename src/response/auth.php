<?php

/** @noinspection PhpUnused */

namespace Lamb\Response;

use JetBrains\PhpStorm\NoReturn;
use Lamb\Config;
use Lamb\Network;
use Lamb\Security;
use Random\RandomException;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

/**
 * Handles the /login route without starting a session for anonymous visitors.
 *
 * Sessionless-login model (why, and the CSRF/throttle scheme that replaces the
 * session-backed CSRF token): see response/README.md ("Login: a sessionless
 * page with its own CSRF model").
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

    // Refuse a client that has already burned through its attempts, before
    // bcrypt runs (issue #443). A refused attempt is not itself recorded, so
    // retrying can't extend the block indefinitely. Cheap early exit only;
    // reserve_login_attempt() below is the race-free gate.
    $ip  = client_ip();
    $now = time();
    $retry_after = login_throttle_retry_after($ip, $now);
    if ($retry_after > 0) {
        return throttled_login_response($retry_after);
    }

    // An install with no LAMB_LOGIN_PASSWORD can never authenticate anyone, and
    // password_verify() against an empty hash fails exactly like a wrong
    // password — so the operator goes looking for a forgotten password instead
    // of an unset variable (php-fpm clears the environment; the pool config has
    // to declare it). Say which it is, in the log and on the page, and do not
    // record a failure: the server is at fault, and throttling the operator
    // out of their own diagnosis makes it worse.
    if (LOGIN_PASSWORD === '') {
        error_log('LAMB_LOGIN_PASSWORD is not set; admin logins cannot succeed');
        return login_page_data('Login is not configured on this site.');
    }

    // Reserve a slot for this attempt atomically, immediately before bcrypt
    // runs: see reserve_login_attempt()'s docblock for why the peek above is
    // not enough on its own.
    $retry_after = reserve_login_attempt($ip, $now);
    if ($retry_after > 0) {
        return throttled_login_response($retry_after);
    }

    $user_pass = \Lamb\Http\request_string($_POST['password'] ?? null) ?? '';
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
    clear_login_failures($ip);
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
 * markers) — see response/README.md ("Login: a sessionless page with its own
 * CSRF model") for why sharing a key would reopen the DoS this endpoint avoids.
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
 * get_cookie_options() already derives `secure` from the connection scheme
 * rather than forcing it on: the token this cookie replaces — the
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
    return get_cookie_options($expires);
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
 * Returns the client address for logging and throttling, or "unknown".
 *
 * Trust the value only behind a known proxy: REMOTE_ADDR is the immediate peer,
 * so behind a reverse proxy it is the proxy's address rather than the real
 * client. X-Forwarded-For is deliberately not consulted — it is attacker-
 * controlled on a directly-exposed install, and honouring it would let one
 * client mint a fresh throttle bucket per request.
 *
 * @return string
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!is_string($ip) || $ip === '') {
        return 'unknown';
    }
    return $ip;
}

/**
 * Writes an audit line for a failed admin login attempt via error_log().
 *
 * The line carries a fixed "failed admin login" marker (easy to grep) and the
 * client IP. It deliberately records no secret — never the submitted password.
 * error_log() respects the host's configured log destination (web server /
 * PHP-FPM), so a self-hoster needs no new dependency to capture brute-force
 * attempts.
 *
 * @return void
 */
function log_failed_login(): void
{
    error_log(sprintf('failed admin login from %s', client_ip()));
}

/**
 * Names the `option` row holding one client's failed-attempt counter.
 *
 * The address is hashed rather than stored: the counter is a throttle, not a
 * visitor log, and a fixed-width key keeps the rows uniform. The per-install
 * login hash is the HMAC key so the same address yields a different row on
 * different installs.
 *
 * @param string $ip Client address from client_ip().
 * @return string    Option name, prefixed so pruning can find these rows.
 */
function login_throttle_key(string $ip): string
{
    return LOGIN_THROTTLE_PREFIX . substr(hash_hmac('sha256', $ip, LOGIN_PASSWORD), 0, 32);
}

/**
 * Serialises a throttle counter as "<count>:<expires>".
 *
 * @param int $count   Failures recorded in the current window.
 * @param int $expires Unix timestamp at which the window (and any block) lapses.
 * @return string
 */
function encode_throttle_state(int $count, int $expires): string
{
    return $count . ':' . $expires;
}

/**
 * Parses a stored throttle counter, treating anything unrecognised as "no
 * failures" — a corrupt row must fail open rather than lock the author out.
 *
 * @param mixed $raw Stored option value.
 * @return array{count: int, expires: int}
 */
function decode_throttle_state(mixed $raw): array
{
    $empty = ['count' => 0, 'expires' => 0];
    if (!is_string($raw) && !is_int($raw)) {
        return $empty;
    }
    $parts = explode(':', (string) $raw);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
        return $empty;
    }

    return ['count' => (int) $parts[0], 'expires' => (int) $parts[1]];
}

/**
 * Decides how long a client must wait, given its counter — the pure half of the
 * throttle, so the policy is testable without a database.
 *
 * A window that has lapsed means the counter is stale: the client starts over
 * with a clean slate rather than staying blocked.
 *
 * @param array{count: int, expires: int} $state Decoded counter.
 * @param int                             $now   Current Unix timestamp.
 * @return int Seconds to wait, or 0 when the attempt may proceed.
 */
function throttle_retry_after(array $state, int $now): int
{
    if ($state['expires'] <= $now || $state['count'] < LOGIN_THROTTLE_MAX_FAILURES) {
        return 0;
    }

    return $state['expires'] - $now;
}

/**
 * Reads a client's counter and returns how long it must wait (0 = go ahead).
 *
 * An unlocked peek, not the enforcement point: it exists only as a cheap
 * early exit before the CSRF/config checks in redirect_login(). The actual,
 * race-free gate is reserve_login_attempt(), called immediately before
 * bcrypt runs.
 *
 * @param string $ip  Client address.
 * @param int    $now Current Unix timestamp.
 * @return int Seconds to wait.
 */
function login_throttle_retry_after(string $ip, int $now): int
{
    $bean = \Lamb\get_option(login_throttle_key($ip), '');

    return throttle_retry_after(decode_throttle_state($bean->value), $now);
}

/**
 * Reserves an attempt slot for a client, atomically with the threshold check,
 * starting a fresh window when the previous one has lapsed, and prunes rows
 * left behind by other clients.
 *
 * Pruning rides on the write path because that is the only thing that creates
 * these rows: a burst of attempts from many addresses cleans up after itself
 * once each window lapses, so the `option` table doesn't keep a permanent row
 * per address that ever probed the login form.
 *
 * The check and increment are atomic and must both complete before
 * password_verify(). The old design peeked at an unlocked counter before
 * bcrypt and incremented only after a wrong password, so a concurrent burst
 * from one IP could all read the same under-limit count, all run bcrypt, and
 * only serialise on the write — spending far more than
 * LOGIN_THROTTLE_MAX_FAILURES real password_verify() calls per window.
 * Reserving the slot before bcrypt refuses the surplus up front.
 *
 * R::begin()/R::commit() are no-ops in this app's fluid mode (see
 * RedBeanPHP\Facade::begin()), so the lock is taken with a raw statement
 * instead.
 *
 * @param string $ip  Client address.
 * @param int    $now Current Unix timestamp.
 * @return int Seconds to wait before the client may attempt again (0 = reserved, go ahead).
 */
function reserve_login_attempt(string $ip, int $now): int
{
    prune_login_throttle($now);

    try {
        R::exec('BEGIN IMMEDIATE');
    } catch (SQL $e) {
        // Another request already holds the write lock: refuse this attempt
        // rather than let it through unreserved, which would reopen the exact
        // race this function exists to close. The refused client just retries.
        return 1;
    }

    $bean  = \Lamb\get_option(login_throttle_key($ip), '');
    $state = decode_throttle_state($bean->value);
    $retry_after = throttle_retry_after($state, $now);
    if ($retry_after > 0) {
        R::exec('COMMIT');
        return $retry_after;
    }

    $count = $state['expires'] > $now ? $state['count'] + 1 : 1;
    \Lamb\set_option($bean, encode_throttle_state($count, $now + LOGIN_THROTTLE_WINDOW));

    R::exec('COMMIT');

    return 0;
}

/**
 * Drops the client's counter after a successful login, so a session of typos
 * costs nothing once the right password lands.
 *
 * @param string $ip Client address.
 * @return void
 */
function clear_login_failures(string $ip): void
{
    $bean = R::findOne('option', ' name = ? ', [login_throttle_key($ip)]);
    if ($bean !== null) {
        R::trash($bean);
    }
}

/**
 * Deletes throttle rows whose window has lapsed.
 *
 * Bounded per call: a run only has to keep pace with the writes that create the
 * rows, and an unbounded delete on a large table would make the login request
 * that triggers it pay for every address that ever probed the site.
 *
 * @param int $now Current Unix timestamp.
 * @return int Number of rows removed.
 */
function prune_login_throttle(int $now): int
{
    $rows = R::find('option', ' name LIKE ? LIMIT 200 ', [LOGIN_THROTTLE_PREFIX . '%']);

    $pruned = 0;
    foreach ($rows as $row) {
        if (decode_throttle_state($row->value)['expires'] <= $now) {
            R::trash($row);
            $pruned++;
        }
    }

    return $pruned;
}

/**
 * Phrases the refusal for the login page: the author locked out by their own
 * typos needs to know when to come back, not just that they were refused.
 *
 * @param int $seconds Seconds left on the block.
 * @return string
 */
function throttle_message(int $seconds): string
{
    $minutes = max(1, (int) ceil($seconds / MINUTE_IN_SECONDS));

    return sprintf(
        'Too many failed attempts. Try again in %d %s.',
        $minutes,
        $minutes === 1 ? 'minute' : 'minutes'
    );
}

/**
 * Refuses a throttled login attempt: 429 with Retry-After, and the login page
 * re-rendered in place with the wait spelled out.
 *
 * Deliberately not a sleep() — delaying the response would park a PHP-FPM
 * worker for the duration, making the throttle a cheaper denial of service than
 * the brute force it prevents.
 *
 * @param int $retryAfter Seconds left on the block.
 * @return array<string, mixed>
 * @throws RandomException
 */
function throttled_login_response(int $retryAfter): array
{
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 429 Too Many Requests');
    header('Retry-After: ' . $retryAfter);

    return login_page_data(throttle_message($retryAfter));
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
        'redirects' => \Lamb\get_all_redirects(),
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Security\require_csrf();

        if (\Lamb\Http\request_string($_POST['action'] ?? null) === 'reset') {
            $default_ini = Config\get_default_ini_text();
            Config\save_ini_text($default_ini);
            $_SESSION['flash'][] = "Settings reset to defaults.";
            redirect_uri('/settings');
        }

        // A submission carrying no configuration text at all is not an author
        // clearing the box (that posts an empty string) — it is a malformed
        // request, and saving '' for it would wipe every setting.
        $submitted_ini = \Lamb\Http\request_string($_POST['ini_text'] ?? null);
        if ($submitted_ini === null) {
            $_SESSION['flash'][] = 'Settings not saved: the form did not include the configuration text.';
            redirect_uri('/settings');
        }

        $validation = Config\validate_ini($submitted_ini);

        if ($validation['valid']) {
            Config\save_ini_text($submitted_ini);
            $_SESSION['flash'][] = "Settings saved successfully.";
            // Syntactically valid INI can still hold a setting in the wrong
            // shape — a `[site_title]` section, a `feeds = <url>` line. Those
            // are ignored on read so they cannot break the site; say so here,
            // or the author is left with a setting that saved and did nothing.
            foreach (Config\shape_warnings($submitted_ini) as $warning) {
                $_SESSION['flash'][] = $warning;
            }
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

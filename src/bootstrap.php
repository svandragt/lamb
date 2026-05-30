<?php

namespace Lamb\Bootstrap;

use RuntimeException;
use RedBeanPHP\R;

/**
 * Initializes the database by configuring the SQLite connection and setting up the writer cache.
 *
 * @param string $data_dir The directory path where the database file will be stored.
 * @return void
 * @throws RuntimeException If the specified directory cannot be created.
 */
function bootstrap_db(string $data_dir): void
{
    if (!is_dir($data_dir)) {
        if (!mkdir($data_dir, 0777, true) && !is_dir($data_dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $data_dir));
        }
    }
    R::setup(sprintf("sqlite:%s/lamb.db", $data_dir));
    R::useWriterCache(true);

    // One-time migration: mark pre-versioning posts as version 1.
    // transformed is already populated for these posts (parse_bean ran at creation/edit time);
    // we only need to stamp the version column so upgrade_posts() never writes them again.
    R::exec('UPDATE post SET version = 1 WHERE version IS NULL');

    ensure_post_columns();
}

/**
 * Ensures the post table has the columns introduced by soft-delete and draft features.
 * Safe to call on any DB: no-ops if the table or columns don't exist yet.
 *
 * @return void
 */
function ensure_post_columns(): void
{
    $postTableExists = (bool) R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='post'");
    if (!$postTableExists) {
        return;
    }
    $columns = array_column(R::getAll('PRAGMA table_info(post)'), 'name');
    if (!in_array('deleted', $columns, true)) {
        R::exec('ALTER TABLE post ADD COLUMN deleted INTEGER');
    }
    if (!in_array('draft', $columns, true)) {
        R::exec('ALTER TABLE post ADD COLUMN draft INTEGER');
    }
}

/**
 * Configures session security settings without starting the session.
 *
 * This hardens the session by enabling strict mode, making cookies inaccessible
 * via JavaScript, ensuring secure transmission over HTTPS, and setting a SameSite
 * attribute to mitigate CSRF. It also disables PHP's session cache limiter so that
 * starting a session does not emit no-cache headers — the application manages cache
 * headers itself (see cache_headers()).
 *
 * @return void
 */
function configure_session(): void
{
    // Make cookies inaccessible via JavaScript (XSS).
    ini_set("session.cookie_httponly", 1);
    $secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
    ini_set("session.cookie_secure", $secure ? 1 : 0);
    ini_set("session.use_strict_mode", 1);

    // Prevent the browser from sending cookies along with cross-site requests (CSRF)
    $cookie_params = [
        'samesite' => 'Strict',
        'path' => '/',
        'httponly' => true,
    ];
    if ($secure) {
        $cookie_params['secure'] = true;
    }
    session_set_cookie_params($cookie_params);
    session_name('LAMBSESSID');

    // We manage Cache-Control ourselves (cache_headers()). Without this, session_start()
    // would emit no-store/no-cache on every page that has a session, defeating caching.
    session_cache_limiter('');
}

/**
 * Decides whether a request should resume/start a PHP session.
 *
 * A session is only warranted when the request carries evidence of a (previously)
 * logged-in user: the lamb_logged_in marker cookie set at login, or an existing
 * LAMBSESSID session cookie. Anonymous visitors get no session — and therefore no
 * Set-Cookie and no no-cache headers — so their pages remain cacheable (issue #116).
 *
 * @param array $cookies Typically $_COOKIE.
 * @return bool
 */
function should_start_session(array $cookies): bool
{
    return isset($cookies['lamb_logged_in']) || isset($cookies['LAMBSESSID']);
}

/**
 * Starts the session if one is not already active. Idempotent.
 *
 * Routes that need a session for an otherwise-anonymous request (the login page,
 * CSRF-protected POSTs, setting a flash before redirecting) call this explicitly.
 * configure_session() must have run first.
 *
 * @return void
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_start();
}

/**
 * Initializes and secures a session, starting it only for (previously) logged-in users.
 *
 * @return void
 */
function bootstrap_session(): void
{
    configure_session();
    if (should_start_session($_COOKIE)) {
        start_session();
    }
}

/**
 * Returns the Cache-Control headers to emit for the current request.
 *
 * Logged-in responses are private and uncacheable; anonymous responses are
 * cacheable so a CDN/reverse-proxy/browser can serve them without hitting PHP.
 *
 * Vary: Cookie tells shared caches to key on the request cookies, so a cached
 * anonymous page is never served to a logged-in user (who always carries the
 * session/login cookie) and vice versa.
 *
 * @param bool $logged_in Whether the current visitor is logged in.
 * @return string[] Header strings ready to pass to header().
 */
function cache_headers(bool $logged_in): array
{
    if ($logged_in) {
        return [
            'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma: no-cache',
            'Vary: Cookie',
        ];
    }
    return [
        'Cache-Control: max-age=300',
        'Vary: Cookie',
    ];
}

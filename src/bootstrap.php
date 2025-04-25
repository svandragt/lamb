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
}

/**
 * Initializes and secures a session.
 *
 * This method configures the session to enhance security by enabling strict mode,
 * making cookies inaccessible via JavaScript, and ensuring secure transmission over HTTPS.
 * It also sets a SameSite attribute to cookies to mitigate CSRF attacks.
 * Additionally, it validates the user agent to prevent session hijacking attempts.
 *
 * @return void
 */
function bootstrap_session(): void
{
    // Make cookies inaccessible via JavaScript (XSS).
    ini_set("session.cookie_httponly", 1);
    ini_set("session.cookie_secure", 1);
    ini_set("session.use_strict_mode", 1);

    // Prevent the browser from sending cookies along with cross-site requests (CSRF)
    session_set_cookie_params(['samesite' => 'Strict']); // or 'Lax'
    session_name('LAMBSESSID');
    session_start();

    // Validate user agents
    if (isset($_SESSION['HTTP_USER_AGENT'])) {
        if ($_SESSION['HTTP_USER_AGENT'] !== md5($_SERVER['HTTP_USER_AGENT'])) {
            /* Possible session hijacking attempt */
            exit("Security fail");
        }
    } else {
        $_SESSION['HTTP_USER_AGENT'] = md5($_SERVER['HTTP_USER_AGENT']);
    }
}

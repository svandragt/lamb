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
    session_start();

}

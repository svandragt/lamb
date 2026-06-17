<?php

/**
 * Application-wide constants.
 *
 * All static, non-runtime constants live here so they are discoverable in one place.
 * Runtime constants that depend on config, environment, or server variables remain
 * in index.php (ROOT_URL, THEME, THEME_DIR, THEME_URL) or response.php (LOGIN_PASSWORD).
 */

define('HIDDEN_CSRF_NAME', 'csrf');
// Cookie holding the anonymous /login double-submit CSRF token (issue #462).
// Distinct from LAMBSESSID/lamb_logged_in: /login issues no session, so its
// CSRF token lives in this signed cookie + a matching hidden field instead.
define('LOGIN_CSRF_COOKIE', 'lamb_login_csrf');
define('IMAGE_FILES', 'imageFiles');
// Image extensions accepted for upload. SVG is excluded: it can carry scripts.
define('IMAGE_UPLOAD_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif']);
// Seconds before a single feed fetch is abandoned during /_cron ingestion.
define('FEED_FETCH_TIMEOUT', 15);
define('MINUTE_IN_SECONDS', 60);
// Current post render-format version. Bump when `transformed` output changes
// (e.g. new syntax highlighting); older posts are re-parsed on read by upgrade_posts().
define('POST_VERSION', 3);
// How long a login is remembered. The session cookie and the server-side session
// both persist this long, so logins survive a browser restart and idle time.
define('REMEMBER_LIFETIME', 7 * 24 * 60 * MINUTE_IN_SECONDS); // one week
// How long an anonymous /login CSRF token stays valid. Short-lived: the login
// page is never cached (no-store), so a visitor always gets a fresh token, and
// an expired one just means reloading /login.
define('LOGIN_CSRF_LIFETIME', 60 * MINUTE_IN_SECONDS); // one hour
define('SESSION_LOGIN', 'logged_in');
define('SUBMIT_CREATE', 'Create post');
define('SUBMIT_EDIT', 'Update post');
define('SUBMIT_LOGIN', 'Log in');

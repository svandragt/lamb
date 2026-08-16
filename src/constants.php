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
// Video extensions accepted for upload; browsers decode these containers natively.
define('VIDEO_UPLOAD_EXTENSIONS', ['mp4', 'webm', 'mov']);
// Seconds before a single feed fetch is abandoned during /_cron ingestion.
define('FEED_FETCH_TIMEOUT', 15);
// Largest feed body kept during /_cron ingestion. /_cron is unauthenticated, so an
// uncapped read lets a configured feed's host (or anything it redirects to) stream
// an endless body into the worker's memory until it fatals.
define('FEED_FETCH_MAX_BYTES', 5_000_000);
define('MINUTE_IN_SECONDS', 60);
// How often /_cron re-fetches an individual feed. Also caps how long SimplePie may
// serve a feed from its own cache: a cache outliving this window would make every
// other crawl read a stale copy of the feed while still counting as a success.
define('FEED_FETCH_INTERVAL', 30 * MINUTE_IN_SECONDS);
// Current post render-format version. Bump when `transformed` output changes
// (e.g. new syntax highlighting); older posts are re-parsed on read by upgrade_posts().
define('POST_VERSION', 4);
// How long a login is remembered. The session cookie and the server-side session
// both persist this long, so logins survive a browser restart and idle time.
define('REMEMBER_LIFETIME', 7 * 24 * 60 * MINUTE_IN_SECONDS); // one week
// How long an anonymous /login CSRF token stays valid. Short-lived: the login
// page is never cached (no-store), so a visitor always gets a fresh token, and
// an expired one just means reloading /login.
define('LOGIN_CSRF_LIFETIME', 60 * MINUTE_IN_SECONDS); // one hour
// Failed /login attempts tolerated from one client address before further
// attempts are refused without running bcrypt (issue #443). Ten per window is
// ~40 guesses an hour — hopeless against any real password — while leaving room
// for a typo-prone author (and for the browser suites, which submit several
// wrong passwords from one address in a single run).
define('LOGIN_THROTTLE_MAX_FAILURES', 10);
// How long failures accumulate, and how long a refusal lasts once the limit is
// met. Also the lifetime of a throttle row before it is pruned.
define('LOGIN_THROTTLE_WINDOW', 15 * MINUTE_IN_SECONDS);
// Prefix for the per-client throttle rows in the `option` table.
define('LOGIN_THROTTLE_PREFIX', 'login_fail_');
define('SESSION_LOGIN', 'logged_in');
define('SUBMIT_CREATE', 'Create post');
define('SUBMIT_EDIT', 'Update post');
define('SUBMIT_LOGIN', 'Log in');

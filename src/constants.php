<?php

/**
 * Application-wide constants.
 *
 * All static, non-runtime constants live here so they are discoverable in one place.
 * Runtime constants that depend on config, environment, or server variables remain
 * in index.php (ROOT_URL, THEME, THEME_DIR, THEME_URL) or response.php (LOGIN_PASSWORD).
 */

define('HIDDEN_CSRF_NAME', 'csrf');
define('IMAGE_FILES', 'imageFiles');
define('MINUTE_IN_SECONDS', 60);
define('SESSION_LOGIN', 'logged_in');
define('SUBMIT_CREATE', 'Create post');
define('SUBMIT_EDIT', 'Update post');
define('SUBMIT_LOGIN', 'Log in');

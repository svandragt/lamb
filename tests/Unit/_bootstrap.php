<?php

declare(strict_types=1);

// ROOT_URL is a constant, so the first definition wins for the whole process.
// Defining it once here keeps its value independent of which test runs first.
define('ROOT_URL', 'http://localhost');

// index.php defines IS_HTTPS once per request. A plain-HTTP request keeps cookies without Secure.
define('IS_HTTPS', false);

// index.php defines THEME_DIR for every request, and respond_settings() reads it.
define('THEME_DIR', dirname(__DIR__, 2) . '/src/themes/base/');

// Modules are not autoloaded: index.php loads them per request. Load their code
// (not their hook registrations) so module functions are callable from tests.
foreach (glob(dirname(__DIR__, 2) . '/src/modules/*/*.php') ?: [] as $module_file) {
    if (basename($module_file) !== 'module.php') {
        require_once $module_file;
    }
}

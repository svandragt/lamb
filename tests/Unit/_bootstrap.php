<?php

declare(strict_types=1);

// ROOT_URL is a constant, so the first definition wins for the whole process.
// Defining it once here keeps its value independent of which test runs first.
define('ROOT_URL', 'http://localhost');

// index.php defines THEME_DIR for every request, and respond_settings() reads it.
define('THEME_DIR', dirname(__DIR__, 2) . '/src/themes/base/');

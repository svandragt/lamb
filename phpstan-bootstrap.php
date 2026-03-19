<?php

namespace JetBrains\PhpStorm {

use Attribute;

if (!class_exists(NoReturn::class)) {
    #[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
    final class NoReturn
    {
    }
}
}

namespace {

if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', __DIR__ . '/src');
}

if (!defined('ROOT_URL')) {
    define('ROOT_URL', 'http://localhost');
}

if (!defined('THEME')) {
    define('THEME', 'default');
}

if (!defined('THEME_DIR')) {
    define('THEME_DIR', ROOT_DIR . '/themes/' . THEME . '/');
}

if (!defined('THEME_URL')) {
    define('THEME_URL', 'themes/' . THEME . '/');
}
}

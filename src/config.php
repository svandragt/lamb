<?php

namespace Lamb\Config;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * Loads and processes settings from the database to generate a configuration array.
 *
 * @return array Returns an associative array representing the configuration,
 *               organized by sections and keys when applicable.
 */
function load(): array
{

    $settings = R::findAll('setting');
    $settings = maybe_migrate($settings);

    foreach ($settings as $setting) {
        if (!empty($setting->section)) {
            $config[$setting->section][$setting->key] = $setting->value;
            continue;
        }
        $config[$setting->key] = $setting->value;
    }
    return $config;
}

/**
 * Handles the migration of settings by merging predefined defaults with user configurations
 * and storing the updated settings. Returns the updated settings or the current database settings.
 *
 * @param array $settings An array of current settings to check and potentially migrate.
 *
 * @return array|false Returns the updated settings if migration is performed, the current database settings if no input is provided, or false on failure.
 */
function maybe_migrate(array $settings): array|false
{
    if (!empty($settings)) {
        return $settings;
    }

    $config = [
        'author_email' => 'joe.sheeple@example.com',
        'author_name' => 'Joe Sheeple',
        'site_title' => 'My Microblog',
    ];
    $user_config = @parse_ini_file('config.ini', true);
    if ($user_config) {
        $config = array_merge($config, $user_config);
    }

    store_settings($config);
    return R::findAll('setting');
}

/**
 * Stores configuration settings into the database.
 *
 * @param array $config An associative array of configuration settings where the key is the setting name
 *                      and the value is the setting value. Nested arrays represent grouped settings.
 * @param string|null $section The optional section name to associate with the settings group. Defaults to null.
 *
 * @return void
 */
function store_settings(array $config, ?string $section = null): void
{
    foreach ($config as $key => $value) {
        if (is_array($value)) {
            store_settings($value, $key);
        } else {
            $setting = R::findOneOrDispense('setting', 'key = ? AND section = ?', [$key, $section]);
            if ($setting->ID) {
                // Setting exists
                continue;
            }
            $setting->key = $key;
            $setting->value = $value;
            if ($section) {
                $setting->section = $section;
            }
            R::store($setting);
        }
    }
}

/**
 * Checks if a given menu item exists in the configuration array.
 *
 * @param string $slug The menu item slug to check.
 *
 * @return bool Returns true if the menu item exists in the configuration array, false otherwise.
 */
function is_menu_item(string $slug): bool
{
    global $config;

    // Checks array values for needle.
    return in_array($slug, $config['menu_items'] ?? [], true);
}

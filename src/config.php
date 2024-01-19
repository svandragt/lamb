<?php

namespace Svandragt\Lamb\Config;

use function yaml_parse;

/**
 * Loads the configuration settings.
 *
 * @return array The configuration settings.
 */
function load() : array {
	$config = [
		'author_email' => 'joe.sheeple@example.com',
		'author_name' => 'Joe Sheeple',
		'site_title' => 'My Microblog',
	];
	$user_config = @parse_ini_file( 'config.ini', true );
	if ( $user_config ) {
		$config = array_merge( $config, $user_config );
	}

	return $config;
}

/**
 * Checks if a given menu item exists in the configuration array.
 *
 * @param string $slug The menu item slug to check.
 *
 * @return bool Returns true if the menu item exists in the configuration array, false otherwise.
 */
function is_menu_item( string $slug ) : bool {
	global $config;

	// Checks array values for needle.
	return in_array( $slug, $config['menu_items'] ?? [], true );
}

/**
 * Parses the front matter from a given text and returns it as an array.
 *
 * @param string $text The text containing the front matter.
 *
 * @return array Returns an array containing the parsed front matter.
 */
function parse_matter( string $text ) : array {
	$matter = @yaml_parse( $text );
	if ( ! is_array( $matter ) ) {
		// There is no front matter.
		return [];
	}
	if ( isset( $matter['title'] ) ) {
		$matter['slug'] = strtolower( preg_replace( '/\W+/m', "-", $matter['title'] ) );
	}

	return $matter;
}

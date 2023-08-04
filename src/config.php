<?php

namespace Svandragt\Lamb\Config;

use function yaml_parse;

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

function is_menu_item( string $slug ) : bool {
	global $config;

	// Checks array values for needle.
	return in_array( $slug, $config['menu_items'] );
}

/**
 * @param string $text
 *
 * @return array
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

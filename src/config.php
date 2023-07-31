<?php

namespace Svandragt\Lamb\Config;

function load() : array {
	$config = [
		'author_email' => 'joe.sheeple@example.com',
		'author_name' => 'Joe Sheeple',
		'site_title' => 'Bleats',
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
	return in_array( $slug, $config['menu_items'], true );
}

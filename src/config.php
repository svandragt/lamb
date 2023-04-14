<?php

namespace Svandragt\Lamb\Config;

use function yaml_parse;

function load() : array {
	$config = [
		'author_email' => 'joe.sheeple@example.com',
		'author_name' => 'Joe Sheeple',
		'site_title' => 'Bleats',
	];
	$user_config = @parse_ini_file( '../config.ini', true );
	if ( $user_config ) {
		$config = array_merge( $config, $user_config );
	}

	return $config;
}

function is_menu_item( string $slug ) : bool {
	global $config;

	return isset( $config['menu_items'][ $slug ] );
}

/**
 * @param string $text
 *
 * @return array
 */
function parse_matter( string $text ) : array {
	$matter = yaml_parse( $text );
	if ( ! is_array( $matter ) ) {
		$matter = parse_ini_matter( $text );
		if ( ! $matter ) {
			return [];
		}
	}

	if ( isset( $matter['title'] ) ) {
		$matter['slug'] = strtolower( preg_replace( '/\W+/m', "-", $matter['title'] ) );
	}

	return $matter;
}

function parse_ini_matter( string $text ) : array|false {
	trigger_error( "Deprecated function called - " . __FUNCTION__, E_USER_DEPRECATED );
	$parts = explode( '---', $text );
	if ( count( $parts ) < 3 ) {
		return [];
	}
	$ini_text = trim( $parts[1] );

	return parse_ini_string( $ini_text );
}

<?php

namespace Svandragt\Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;

/**
 * Return an unsaved dispensed bleat.
 *
 * @param string $contents
 *
 * @return OODBBean
 */
function prepare( string $contents, Item $item = null ) : OODBBean {
	$matter = parse_matter( $contents );

	$bleat = R::dispense( 'bleat' );
	$bleat->body = $contents;
	$bleat->slug = $matter['slug'] ?? '';
	$bleat->created = date( "Y-m-d H:i:s" );
	if ( $item ) {
		$bleat->created = $item->get_date( "Y-m-d H:i:s" );
	}
	$bleat->updated = date( "Y-m-d H:i:s" );

	return $bleat;
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

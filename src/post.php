<?php

namespace Svandragt\Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;

/**
 * Return an unsaved dispensed bean.
 *
 * @param string    $text
 * @param Item|null $feed_item
 *
 * @return OODBBean
 */
function prepare( string $text, Item $feed_item = null ) : OODBBean {
	$matter = parse_matter( $text );

	$bean = R::dispense( 'post' );
	$bean->body = $text;
	$bean->slug = $matter['slug'] ?? '';
	$bean->created = date( "Y-m-d H:i:s" );
	if ( $feed_item ) {
		$bean->created = $feed_item->get_date( "Y-m-d H:i:s" );
	}
	$bean->updated = date( "Y-m-d H:i:s" );

	return $bean;
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

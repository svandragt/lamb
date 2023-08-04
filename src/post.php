<?php

namespace Svandragt\Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;
use function Svandragt\Lamb\Route\is_reserved_route;

/**
 * Return an unsaved dispensed bean.
 *
 * @param string      $text
 * @param Item|null   $feed_item
 * @param string|null $feed_name
 *
 * @return OODBBean|null
 */
function prepare_bean( string $text, Item $feed_item = null, string $feed_name = null ) : ?OODBBean {
	$matter = parse_matter( $text );

	$bean = R::dispense( 'post' );
	$bean->body = $text;
	$bean->slug = $matter['slug'] ?? '';
	$bean->created = date( "Y-m-d H:i:s" );
	if ( $feed_item ) {
		$bean->created = $feed_item->get_date( "Y-m-d H:i:s" );
		if ( $feed_name ) {
			if ( $bean->slug ) {
				// Prefix with feed name
				$bean->slug = slugify( "$feed_name-" . $bean->slug );
			}
			$bean->feeditem_uuid = md5( $feed_name . $feed_item->get_id() );
			$bean->feed_name = $feed_name;
		}
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
		$matter['slug'] = slugify( $matter['title'] );
	}

	return $matter;
}

function slugify( string $text ) : string {
	return strtolower( preg_replace( '/\W+/m', "-", $text ) );
}

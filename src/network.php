<?php

namespace Svandragt\Lamb\Network;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item;
use SimplePie\SimplePie;
use Svandragt\Lamb\Route;
use function Svandragt\Lamb\Post\prepare;
use function Svandragt\Lamb\Route\is_reserved_route;
use const PHP_EOL;
use const ROOT_DIR;

const MIN_RETRY_SECONDS = 60;
require_once( ROOT_DIR . '/routes.php' );

Route\register_route( '_cron', __NAMESPACE__ . '\\process_feeds' );

function get_feeds() : array {
	global $config;

	return $config['network_feeds'] ?? [];
}

function process_feeds() {
	header( 'Content-Type: text/plain' );

	$feeds = get_feeds();

	$option_lpdate = get_option( 'last_processed_date', 0 );
	if ( ( time() - $option_lpdate->value ) < MIN_RETRY_SECONDS ) {
		die( 'Try again later.' );
	}
	foreach ( $feeds as $name => $url ) {
		$feed = new SimplePie();
		$feed->enable_cache( false );
		$feed->set_feed_url( $url );
		$feed->init();

		echo "Processing " . $feed->get_title() . PHP_EOL;

		if ( $feed->data ) {
			/** @var Item $item */
			foreach ( $feed->get_items() as $item ) {
				$pub_date = $item->get_date( 'U' );
				$mod_date = $item->get_updated_date( 'U' );

				// Compare the publication date of the item with the last processed date.
				if ( $pub_date > $option_lpdate->value ) {
					create_item( $item, $name );
					printf( "Created: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title() );
				}

				if ( $mod_date > $option_lpdate->value ) {
					update_item( $item, $name );
					printf( "Updated: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title() );
				}
			}
		}
	}

	set_option( $option_lpdate, (int) date( 'U' ) );
	exit();
}

function update_item( Item $item, string $name ) {
	$uuid = md5( $name . $item->get_id() );
	$bleat = R::findOne( 'bleat', ' feeditem_uuid = ?', [ $uuid ] );
	if ( ! $bleat ) {
		// Bleat not found
		return;
	}
	$contents = wrapped_contents( $item, $name );
	$title = $item->get_title();
	if ( ! empty( $title ) ) {
		$contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
	}
	$bleat->body = $contents;
	$bleat->updated = date( "Y-m-d H:i:s" );

	try {
		R::store( $bleat );
	} catch ( SQL $e ) {
		// continue
	}
}

function create_item( Item $item, string $name ) {
	$contents = wrapped_contents( $item, $name );
	$title = $item->get_title();
	if ( ! empty( $title ) ) {
		$contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
	}

	$bleat = prepare( $contents, $item );
	if ( is_reserved_route( $bleat->slug ) ) {
		$_SESSION['flash'][] = 'Failed to save, slug is in use <code>' . $bleat->slug . '</code>';

		return null;
	}

	try {
		$bleat->feeditem_uuid = md5( $name . $item->get_id() );
		$bleat->feed_name = $name;
		R::store( $bleat );
	} catch ( SQL $e ) {
		// continue
	}
}

function get_option( string $key, $default ) : OODBBean {
	$bean = R::findOneOrDispense( 'option', ' key = ? ', [ $key ] );
	$bean->key = $key;
	if ( $bean->id === 0 ) {
		$bean->value = $default;
	}

	return $bean;
}

function set_option( OODBBean $bean, $value ) {
	$bean->value = $value;
	R::store( $bean );
}

function wrapped_contents( Item $item, string $name ) : string {
	$contents = strip_tags( $item->get_description() );
	$lines = explode( PHP_EOL, $contents );
	foreach ( $lines as &$line ) {
		$line = "> $line";
	}

	$contents = implode( PHP_EOL, $lines );
	$url = $item->get_permalink();
	$contents = "Originally written on [$name]($url): " . PHP_EOL . PHP_EOL . $contents;

	return $contents;
}

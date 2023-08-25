<?php

namespace Svandragt\Lamb\Network;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item;
use SimplePie\SimplePie;
use Svandragt\Lamb\Route;
use function Svandragt\Lamb\Post\prepare_bean;
use function Svandragt\Lamb\Route\is_reserved_route;
use const PHP_EOL;
use const ROOT_DIR;

const MINUTE_IN_SECONDS = 60;
require_once( ROOT_DIR . '/routes.php' );

Route\register_route( '_cron', __NAMESPACE__ . '\\process_feeds' );

function get_feeds() : array {
	global $config;

	return $config['network_feeds'] ?? [];
}

function process_feeds() {
	header( 'Content-Type: text/plain' );
	// FIXME: Missing permalink and title

	$feeds = get_feeds();

	$cron_last_updated = get_option( 'last_processed_date', 0 );
	if ( ( time() - $cron_last_updated->value ) < MINUTE_IN_SECONDS ) {
		die( 'Too often, try again later.' );
	}
	foreach ( $feeds as $name => $url ) {
		flush();
		$last_updated = get_option( 'last_processed_date_' . md5( $name . $url ), 0 );
		if ( ( time() - $last_updated->value ) < MINUTE_IN_SECONDS * 30 ) {
			echo( 'Skipped ' . $url . PHP_EOL );
			continue;
		}

		$feed = new SimplePie();
		$feed->set_cache_location( '../data/cache/simplepie' );
		$feed->set_feed_url( $url );
		$feed->init();
		echo PHP_EOL . "Processing " . $feed->get_title() . PHP_EOL;

		if ( $feed->data ) {
			/** @var Item $item */
			foreach ( $feed->get_items() as $item ) {
				$pub_date = $item->get_date( 'U' );
				$mod_date = $item->get_updated_date( 'U' );

				// Compare the publication date of the item with the last processed date.
				if ( $pub_date > $last_updated->value ) {
					create_item( $item, $name );
					printf( "Created: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title() );
					continue;
				}
				if ( $mod_date > $last_updated->value ) {
					update_item( $item, $name );
					printf( "Updated: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title() );
					continue;
				}
			}
		}
		set_option( $last_updated, (int) date( 'U' ) );
	}

	set_option( $cron_last_updated, (int) date( 'U' ) );
	exit();
}

function update_item( Item $item, string $name ) {
	$uuid = md5( $name . $item->get_id() );
	$bean = R::findOne( 'post', ' feeditem_uuid = ?', [ $uuid ] );
	if ( ! $bean ) {
		// Record not found
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
	$bean->body = $contents;
	$bean->updated = date( "Y-m-d H:i:s" );

	try {
		R::store( $bean );
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
	$bean = prepare_bean( $contents, $item, $name );
	if ( is_null( $bean ) ) {
		$_SESSION['flash'][] = 'Failed to save post';

		return null;
	}

	try {
		$id = R::store( $bean );
		if ( is_reserved_route( $bean->slug ) ) {
			$bean->slug .= "-" . $id;
		}
		R::store( $bean );
	} catch ( SQL $e ) {
		// continue
	}
}

function get_option( string $key, $default_value ) : OODBBean {
	$bean = R::findOneOrDispense( 'option', ' key = ? ', [ $key ] );
	$bean->key = $key;
	if ( $bean->id === 0 ) {
		$bean->value = $default_value;
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
	unset( $line );

	$contents = implode( PHP_EOL, $lines );
	$url = $item->get_permalink();
	$contents = "Originally written on [$name]($url): " . PHP_EOL . PHP_EOL . $contents;

	return $contents;
}

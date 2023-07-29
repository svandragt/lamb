<?php

namespace Svandragt\Lamb\Flock;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\SimplePie;
use Svandragt\Lamb\Route;
use const ROOT_DIR;

require_once( ROOT_DIR . '/routes.php' );

Route\register_route( '_flock', __NAMESPACE__ . '\\update' );

 function get_subscriptions(): array {
	global $config;

	return $config['flock_subscriptions'] ?? [];
}

function update() {
	$subs = get_subscriptions();

	$option_lpdate = get_option('last_processed_date', 0);
	$option_lpdate->value = 0;
	foreach ( $subs as $label => $url ) {
		$feed = new SimplePie();
		$feed->enable_cache(false);
		$feed->set_feed_url( $url );
		$feed->init();

		if ( $feed->data ) {
			foreach ( $feed->get_items() as $item ) {
				$pub_date = $item->get_date( 'U' );
				$mod_date = $item->get_updated_date( 'U' );

				// Compare the publication date of the item with the last processed date.
				if ( $pub_date > $option_lpdate->value ) {
					// TODO This item is new since its publication date is after the last processed date.
					echo $item->get_title() . ' is new.<br>';
				}

				if ( $mod_date > $option_lpdate->value ) {
					// TODO ˚ª˚updated since we last checked
				}
			}
		}
	}

	set_option($option_lpdate, date('U'));
	exit();
 }

function get_option(string $key, $default) : OODBBean {
	$bean = R::findOne( 'option', ' key = ? ', [ $key ] );
	if ( ! $bean ) {
		$bean = R::dispense( 'option' );
		$bean->key = $key;
		$bean->value = $default;
	}
	return $bean;
}

function set_option(OODBBean $bean, $value) {
	$bean->value = $value;
	return R::store( $bean );
}

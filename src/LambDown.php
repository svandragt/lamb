<?php

namespace Svandragt\Lamb;

use Parsedown;

class LambDown extends Parsedown {
	/**
	 * Handle #tag at the start of the line.
	 *
	 * @param $Line
	 *
	 * @return array[]|void
	 */
	protected function blockHeader( $Line ) {
		$level = strspn( $Line['text'], '#' );
		$tag = substr( $Line['text'], $level - 1, 2 );
		if ( $tag !== '# ' ) {
			return;
		}

		return parent::blockHeader( $Line );
	}
}

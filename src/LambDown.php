<?php

namespace Svandragt\Lamb;

use Parsedown;

class LambDown extends Parsedown {
	/**
	 * Determines if the given line is a valid header block in Markdown format.
	 *
	 * @param array $Line The line to be checked.
	 *
	 * @return array[]|void Returns the result of the parent's blockHeader method, or null if the line is not a valid header block.
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

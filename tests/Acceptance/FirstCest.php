<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class FirstCest {
	public function _before( AcceptanceTester $I ) {
	}

	// tests
	public function frontpageWorks( AcceptanceTester $I ) {
		$I->amOnPage( '/' );
		$I->see( 'Powered by Lamb.' );
	}
}

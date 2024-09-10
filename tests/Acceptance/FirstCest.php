<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class FirstCest {
	public function _before( AcceptanceTester $I ) {
	}

	// tests
	public function tryHomepage( AcceptanceTester $I ) {
		// Open homepage
		$I->amOnPage( '/' );

		// Check if the title is correct
		$I->seeInTitle( 'My Microblog' );

		// Check if the header is present
		$I->see( 'My Microblog', 'h1' );

		// Check if a specific link is present
		$I->seeElement( 'a[href="/login"]' );

		// Bottom of the page
		$I->see( 'Powered by', 'small' );
		$I->seeElement( 'small a[href="https://github.com/svandragt/lamb"]' );
	}
}

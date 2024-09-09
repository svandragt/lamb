<?php

namespace Tests\Functional;

use Tests\Support\FunctionalTester;

class HomePageCest {
	protected function _before() {
	}

	public function tryToTestHomePage( FunctionalTester $I ) {
		// Open homepage
		$I->amOnPage( '/' );

		// Check if the title is correct
		$I->seeInTitle( 'My Microblog' );

		// Check if the header is present
		$I->see( 'My Microblog', 'h1' );

		// Check if a specific link is present
		$I->seeElement( 'a[href="/login"]' );
	}
}

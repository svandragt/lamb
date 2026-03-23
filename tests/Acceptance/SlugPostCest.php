<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class SlugPostCest
{
    public function tryVisitingAPostWithASlug(AcceptanceTester $I)
    {
        // Log in
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');

        // Create a post with a title (which generates a slug)
        $I->amOnPage('/');
        $I->fillField('contents', "---\ntitle: Test Slug Post\n---\nThis is a test post.");
        $I->click('Create post');

        // Visit the post via its slug
        $I->amOnPage('/test-slug-post');
        $I->seeResponseCodeIs(200);
        $I->see('Test Slug Post');
        $I->dontSee('Warning:');
        $I->dontSee('Fatal error');
    }
}

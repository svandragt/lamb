<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class LogoutCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    // redirect_logout

    public function testLogoutRedirectsToHomepage(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        $I->seeResponseCodeIs(200);
        // Should land on homepage (h1 with site title)
        $I->seeElement('h1');
    }

    public function testLogoutPageHasNoErrors(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    public function testAfterLogoutDraftsPageRedirectsToLogin(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        // Session cleared — protected pages now redirect to login
        $I->amOnPage('/drafts');
        $I->seeCurrentUrlMatches('~/login~');
    }

    public function testAfterLogoutTrashPageRedirectsToLogin(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        $I->amOnPage('/trash');
        $I->seeCurrentUrlMatches('~/login~');
    }
}

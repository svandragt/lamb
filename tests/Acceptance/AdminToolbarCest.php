<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class AdminToolbarCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    public function testHelpLinkSitsBeforeLogoutAndPointsAtTheDocs(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->seeElement('//div[@id="admin-toolbar"]//a[@href="https://svandragt.github.io/lamb"]/following-sibling::a[1][@href="/logout"]');
    }

    public function testLoggedOutVisitorSeesNoAdminToolbar(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->dontSeeElement('#admin-toolbar');
    }
}

<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Covers the login sad-path and login-gating of admin pages.
 *
 * The cache-headers suite proves a *correct* password logs in; the security-
 * critical rejection of a *wrong* password, and the gating of settings/scheduled
 * (LogoutCest covers drafts/trash) had no browser-level coverage.
 */
class LoginCest
{
    public function testWrongPasswordLeavesVisitorAnonymous(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');

        // A rejected login must not authenticate the visitor: the author-only
        // entry form must not appear, and admin pages must still bounce to login.
        $I->dontSeeElement('//textarea[@name="contents"]');
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
    }

    public function testWrongPasswordHasNoErrors(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');

        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    public function testSettingsPageRequiresLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
    }

    public function testScheduledPageRequiresLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/scheduled');
        $I->seeInCurrentUrl('/login');
    }
}
